<?php

class DefaultQuestion {

	function __construct( $question ) {
		global $wgRequest, $wgEnableReviews;

		// if it's all caps, tone it down!
		if ( strtoupper( $question ) == $question ) {
			$question = strtolower( $question );
		}

		// strip question marks and slashes
		$question = Answer::q2s( $question );
		$question = str_replace( '?', ' ', $question );

		// remove "Ask a question" or equivalent if it got here
		// question is in DBkey form, with underscores
		$askAQuestion = wfMessage( 'ask_a_question' )->inContentLanguage()->text();
		$search = str_replace( ' ', '_', $askAQuestion );
		if ( strpos( $question, $search ) === 0 ) {
			$question = substr( $question, strlen( $search ) );
			$question = ltrim( $question );
		}

		if ( !empty( $wgEnableReviews ) && !preg_match( '/_/', $question ) ) {
			// remove www. if typed at the beginning of the pagename
			$question = preg_replace( '/^www\./i', '', $question );

			// add a .com if there was no .com, .net, .org, .co.uk, .ca or .au at the end of the pagename
			// (for the sake of simplicity add .com if there is on . or character)
			if ( !preg_match( "/\.|\//", $question ) ) {
				$question .= '.com';
			}
		}

		$this->title = Title::makeTitleSafe( NS_MAIN, $question );
		if ( !$this->title ) {
			return null;
		}

		$this->question = $this->title->getText();

		$this->categories = $wgRequest->getVal( 'categories' );
	}

	function create() {
		global $wgOut, $wgUser, $wgContLang;

		if ( wfReadOnly() ) {
			return false;
		}

		if (
			empty( $this->title ) ||
			!$this->title->userCan( 'edit' ) ||
			!$this->title->userCan( 'create' )
		)
		{
			return false;
		}

		if ( $this->badWordsTest() ) {
			return false;
		}

		if ( !wfRunHooks( 'CreateDefaultQuestionPageFilter', array( $this->title ) ) ) {
			wfDebug( __METHOD__ . ": question '{$this->title}' filtered out by hook\n" );
			return false;
		}

		if ( $this->searchTest() ) {
			return false;
		}

		$default_text = Answer::getSpecialCategoryTag( 'unanswered' );

		// add default category tags passed in
		if ( $this->categories ) {
			$categories_array = explode( '|', $this->categories );
			foreach ( $categories_array as $category ) {
				$default_text .= "\n[[" . $wgContLang->getNsText( NS_CATEGORY ) .
					':' . ucfirst( $category ) . ']]';
			}
		}

		$flags = EDIT_NEW;
		$article = new Article( $this->title );
		$article->doEdit(
			$default_text,
			wfMessage( 'new_question_comment' )->inContentLanguage()->parse(),
			$flags
		);

		if ( $wgUser->isLoggedIn() ) {
			// check user preferences before adding to watchlist (RT #45647)
			$watchCreations = $wgUser->getOption( 'watchcreations' );
			if ( !empty( $watchCreations ) ) {
				$wgUser->addWatch( $this->title );
			}
		}

		// store question in session so we can give attribution if they create an account afterwards
		$_SESSION['wsQuestionAsk'] = '';
		if ( $wgUser->isAnon() ) {
			$_SESSION['wsQuestionAsk'] = $this->question;
		}

		return true;
	}

	// redirect one or two word questions to search
	function searchTest() {
		// on reviews wikis always redirect to search if there is a space inside
		global $wgEnableReviews;
		if ( !empty( $wgEnableReviews ) && preg_match( '/\s/', $this->question ) ) {
			return true;
		}

		global $wgDisableAnswersShortQuestionsRedirect;
		if ( !empty( $wgDisableAnswersShortQuestionsRedirect ) ) {
			return false;
		}

		$words = explode( ' ', $this->question );
		if ( count( $words ) < 3 ) {
			return true;
		}

		return false;
	}

	function getBadWords() {
		$swearContent = wfMessage( 'BadWords' )->inContentLanguage()->text();

		$swearList = explode( "\n", $swearContent );
		foreach ( $swearList as $swear ) {
			if ( $swear ) {
				$swearWords[] = $swear;
			}
		}

		return $swearWords;
	}

	// don't allow swear words when creating new question / generating list of recenlty asked questions (via HPL)
	function badWordsTest() {
		// TODO: temporary check for Phalanx (don't perform additional filtering when enabled)
		if ( class_exists( 'Phalanx' ) ) {
			return false;
		}

		// remove punctations
		$search_test = preg_replace( '/\pP+/', '', $this->question );
		$search_test = preg_replace( '/\s+/', ' ', $search_test );

		$found = preg_match(
			'/\s(' . implode( '|', $this->getBadWords() ) . ')\s/i',
			' ' . $search_test . ' '
		);
		if ( $found ) {
			return true;
		}

		return false;
	}

	function getFilterWords() {
		global $wgMemc;

		$mkey = wfMemcKey( __METHOD__ );
		$filtered_words = $wgMemc->get( $mkey );

		if ( empty( $filtered_words ) ) {
			$filtered = wfMessage( 'FilterWords' );
			if ( !$filtered->isDisabled() ) {
				$aFiltered = explode( "\n", $filtered->inContentLanguage()->text() );
				foreach ( $aFiltered as $filter ) {
					if ( $filter ) {
						$filtered_words[] = $filter;
					}
				}
				$wgMemc->set( $mkey, $filtered_words, 3 * 60 );
			}
		}

		return $filtered_words;
	}

	// don't allow swear words when generating list of recently asked questions (via HPL)
	function filterWordsTest() {
		if ( !wfRunHooks( 'DefaultQuestion::filterWordsTest', array( $this->question ) ) ) {
			wfDebug( __METHOD__ . ": question '{$this->question}' filtered out by hook\n" );
			return true;
		}

		// TODO: temporary check for Phalanx (don't perform additional filtering when enabled)
		if ( class_exists( 'Phalanx' ) ) {
			return false;
		}

		// remove punctuations
		$search_test = preg_replace( '/\pP+/', '', $this->question );
		$search_test = preg_replace( '/\s+/', ' ', $search_test );

		$words = $this->getFilterWords();
		if ( !empty( $words ) ) {
			$found = preg_match(
				'/\s(' . implode( '|', $words ) . ')\s/i',
				' ' . $search_test . ' '
			);
			if ( $found ) {
				wfDebug( __METHOD__ . ": question '{$search_test}' filtered out\n" );
				return true;
			}
		}

		return false;
	}
}
