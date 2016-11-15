<?php
/**
 * Implements Special:Search
 *
 * Copyright © 2004 Brion Vibber <brion@pobox.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

use MediaWiki\MediaWikiServices;

/**
 * implements Special:Search - Run text & title search and display the output
 * @ingroup SpecialPage
 */
class SpecialSearch extends SpecialPage {
	/**
	 * Current search profile. Search profile is just a name that identifies
	 * the active search tab on the search page (content, discussions...)
	 * For users tt replaces the set of enabled namespaces from the query
	 * string when applicable. Extensions can add new profiles with hooks
	 * with custom search options just for that profile.
	 * @var null|string
	 */
	protected $profile;

	/** @var SearchEngine Search engine */
	protected $searchEngine;

	/** @var string Search engine type, if not default */
	protected $searchEngineType;

	/** @var array For links */
	protected $extraParams = [];

	/**
	 * @var string The prefix url parameter. Set on the searcher and the
	 * is expected to treat it as prefix filter on titles.
	 */
	protected $mPrefix;

	/**
	 * @var int
	 */
	protected $limit, $offset;

	/**
	 * @var array
	 */
	protected $namespaces;

	/**
	 * @var string
	 */
	protected $fulltext;

	/**
	 * @var bool
	 */
	protected $runSuggestion = true;

	/**
	 * Names of the wikis, in format: Interwiki prefix -> caption
	 * @var array
	 */
	protected $customCaptions;

	/**
	 * Search engine configurations.
	 * @var SearchEngineConfig
	 */
	protected $searchConfig;

	const NAMESPACES_CURRENT = 'sense';

	public function __construct() {
		parent::__construct( 'Search' );
		$this->searchConfig = MediaWikiServices::getInstance()->getSearchEngineConfig();
	}

	/**
	 * Entry point
	 *
	 * @param string $par
	 */
	public function execute( $par ) {
		$request = $this->getRequest();

		// Fetch the search term
		$search = str_replace( "\n", " ", $request->getText( 'search' ) );

		// Historically search terms have been accepted not only in the search query
		// parameter, but also as part of the primary url. This can have PII implications
		// in releasing page view data. As such issue a 301 redirect to the correct
		// URL.
		if ( strlen( $par ) && !strlen( $search ) ) {
			$query = $request->getValues();
			unset( $query['title'] );
			// Strip underscores from title parameter; most of the time we'll want
			// text form here. But don't strip underscores from actual text params!
			$query['search'] = str_replace( '_', ' ', $par );
			$this->getOutput()->redirect( $this->getPageTitle()->getFullURL( $query ), 301 );
			return;
		}

		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->allowClickjacking();
		$out->addModuleStyles( [
			'mediawiki.special', 'mediawiki.special.search.styles', 'mediawiki.ui', 'mediawiki.ui.button',
			'mediawiki.ui.input', 'mediawiki.widgets.SearchInputWidget.styles',
		] );
		$this->addHelpLink( 'Help:Searching' );

		$this->load();
		if ( !is_null( $request->getVal( 'nsRemember' ) ) ) {
			$this->saveNamespaces();
			// Remove the token from the URL to prevent the user from inadvertently
			// exposing it (e.g. by pasting it into a public wiki page) or undoing
			// later settings changes (e.g. by reloading the page).
			$query = $request->getValues();
			unset( $query['title'], $query['nsRemember'] );
			$out->redirect( $this->getPageTitle()->getFullURL( $query ) );
			return;
		}

		$out->addJsConfigVars( [ 'searchTerm' => $search ] );
		$this->searchEngineType = $request->getVal( 'srbackend' );

		if ( $request->getVal( 'fulltext' )
			|| !is_null( $request->getVal( 'offset' ) )
		) {
			$this->showResults( $search );
		} else {
			$this->goResult( $search );
		}
	}

	/**
	 * Set up basic search parameters from the request and user settings.
	 *
	 * @see tests/phpunit/includes/specials/SpecialSearchTest.php
	 */
	public function load() {
		$request = $this->getRequest();
		list( $this->limit, $this->offset ) = $request->getLimitOffset( 20, '' );
		$this->mPrefix = $request->getVal( 'prefix', '' );

		$user = $this->getUser();

		# Extract manually requested namespaces
		$nslist = $this->powerSearch( $request );
		if ( !count( $nslist ) ) {
			# Fallback to user preference
			$nslist = $this->searchConfig->userNamespaces( $user );
		}

		$profile = null;
		if ( !count( $nslist ) ) {
			$profile = 'default';
		}

		$profile = $request->getVal( 'profile', $profile );
		$profiles = $this->getSearchProfiles();
		if ( $profile === null ) {
			// BC with old request format
			$profile = 'advanced';
			foreach ( $profiles as $key => $data ) {
				if ( $nslist === $data['namespaces'] && $key !== 'advanced' ) {
					$profile = $key;
				}
			}
			$this->namespaces = $nslist;
		} elseif ( $profile === 'advanced' ) {
			$this->namespaces = $nslist;
		} else {
			if ( isset( $profiles[$profile]['namespaces'] ) ) {
				$this->namespaces = $profiles[$profile]['namespaces'];
			} else {
				// Unknown profile requested
				$profile = 'default';
				$this->namespaces = $profiles['default']['namespaces'];
			}
		}

		$this->fulltext = $request->getVal( 'fulltext' );
		$this->runSuggestion = (bool)$request->getVal( 'runsuggestion', true );
		$this->profile = $profile;
	}

	/**
	 * If an exact title match can be found, jump straight ahead to it.
	 *
	 * @param string $term
	 */
	public function goResult( $term ) {
		$this->setupPage( $term );
		# Try to go to page as entered.
		$title = Title::newFromText( $term );
		# If the string cannot be used to create a title
		if ( is_null( $title ) ) {
			$this->showResults( $term );

			return;
		}
		# If there's an exact or very near match, jump right there.
		$title = $this->getSearchEngine()
			->getNearMatcher( $this->getConfig() )->getNearMatch( $term );

		if ( !is_null( $title ) &&
			Hooks::run( 'SpecialSearchGoResult', [ $term, $title, &$url ] )
		) {
			if ( $url === null ) {
				$url = $title->getFullURL();
			}
			$this->getOutput()->redirect( $url );

			return;
		}
		$this->showResults( $term );
	}

	/**
	 * @param string $term
	 */
	public function showResults( $term ) {
		global $wgContLang;

		$search = $this->getSearchEngine();
		$search->setFeatureData( 'rewrite', $this->runSuggestion );
		$search->setLimitOffset( $this->limit, $this->offset );
		$search->setNamespaces( $this->namespaces );
		$search->prefix = $this->mPrefix;
		$term = $search->transformSearchTerm( $term );

		Hooks::run( 'SpecialSearchSetupEngine', [ $this, $this->profile, $search ] );

		$this->setupPage( $term );

		$out = $this->getOutput();

		if ( $this->getConfig()->get( 'DisableTextSearch' ) ) {
			$searchFowardUrl = $this->getConfig()->get( 'SearchForwardUrl' );
			if ( $searchFowardUrl ) {
				$url = str_replace( '$1', urlencode( $term ), $searchFowardUrl );
				$out->redirect( $url );
			} else {
				$out->addHTML(
					Xml::openElement( 'fieldset' ) .
					Xml::element( 'legend', null, $this->msg( 'search-external' )->text() ) .
					Xml::element(
						'p',
						[ 'class' => 'mw-searchdisabled' ],
						$this->msg( 'searchdisabled' )->text()
					) .
					$this->msg( 'googlesearch' )->rawParams(
						htmlspecialchars( $term ),
						'UTF-8',
						$this->msg( 'searchbutton' )->escaped()
					)->text() .
					Xml::closeElement( 'fieldset' )
				);
			}

			return;
		}

		$title = Title::newFromText( $term );
		$showSuggestion = $title === null || !$title->isKnown();
		$search->setShowSuggestion( $showSuggestion );

		// fetch search results
		$rewritten = $search->replacePrefixes( $term );

		$titleMatches = $search->searchTitle( $rewritten );
		$textMatches = $search->searchText( $rewritten );

		$textStatus = null;
		if ( $textMatches instanceof Status ) {
			$textStatus = $textMatches;
			$textMatches = $textStatus->getValue();
		}

		// did you mean... suggestions
		$didYouMeanHtml = '';
		if ( $showSuggestion && $textMatches ) {
			if ( $textMatches->hasRewrittenQuery() ) {
				$didYouMeanHtml = $this->getDidYouMeanRewrittenHtml( $term, $textMatches );
			} elseif ( $textMatches->hasSuggestion() ) {
				$didYouMeanHtml = $this->getDidYouMeanHtml( $textMatches );
			}
		}

		if ( !Hooks::run( 'SpecialSearchResultsPrepend', [ $this, $out, $term ] ) ) {
			# Hook requested termination
			return;
		}

		// start rendering the page
		$out->addHTML(
			Xml::openElement(
				'form',
				[
					'id' => ( $this->isPowerSearch() ? 'powersearch' : 'search' ),
					'method' => 'get',
					'action' => wfScript(),
				]
			)
		);

		// Get number of results
		$titleMatchesNum = $textMatchesNum = $numTitleMatches = $numTextMatches = 0;
		if ( $titleMatches ) {
			$titleMatchesNum = $titleMatches->numRows();
			$numTitleMatches = $titleMatches->getTotalHits();
		}
		if ( $textMatches ) {
			$textMatchesNum = $textMatches->numRows();
			$numTextMatches = $textMatches->getTotalHits();
		}
		$num = $titleMatchesNum + $textMatchesNum;
		$totalRes = $numTitleMatches + $numTextMatches;

		$out->enableOOUI();
		$out->addHTML(
			# This is an awful awful ID name. It's not a table, but we
			# named it poorly from when this was a table so now we're
			# stuck with it
			Xml::openElement( 'div', [ 'id' => 'mw-search-top-table' ] ) .
			$this->shortDialog( $term, $num, $totalRes ) .
			Xml::closeElement( 'div' ) .
			$this->searchProfileTabs( $term ) .
			$this->searchOptions( $term ) .
			Xml::closeElement( 'form' ) .
			$didYouMeanHtml
		);

		$filePrefix = $wgContLang->getFormattedNsText( NS_FILE ) . ':';
		if ( trim( $term ) === '' || $filePrefix === trim( $term ) ) {
			// Empty query -- straight view of search form
			return;
		}

		$out->addHTML( "<div class='searchresults'>" );

		$hasErrors = $textStatus && $textStatus->getErrors();
		if ( $hasErrors ) {
			list( $error, $warning ) = $textStatus->splitByErrorType();
			if ( $error->getErrors() ) {
				$out->addHTML( Html::rawElement(
					'div',
					[ 'class' => 'errorbox' ],
					$error->getHTML( 'search-error' )
				) );
			}
			if ( $warning->getErrors() ) {
				$out->addHTML( Html::rawElement(
					'div',
					[ 'class' => 'warningbox' ],
					$warning->getHTML( 'search-warning' )
				) );
			}
		}

		// prev/next links
		$prevnext = null;
		if ( $num || $this->offset ) {
			// Show the create link ahead
			$this->showCreateLink( $title, $num, $titleMatches, $textMatches );
			if ( $totalRes > $this->limit || $this->offset ) {
				if ( $this->searchEngineType !== null ) {
					$this->setExtraParam( 'srbackend', $this->searchEngineType );
				}
				$prevnext = $this->getLanguage()->viewPrevNext(
					$this->getPageTitle(),
					$this->offset,
					$this->limit,
					$this->powerSearchOptions() + [ 'search' => $term ],
					$this->limit + $this->offset >= $totalRes
				);
			}
		}
		Hooks::run( 'SpecialSearchResults', [ $term, &$titleMatches, &$textMatches ] );

		$out->parserOptions()->setEditSection( false );
		if ( $titleMatches ) {
			if ( $numTitleMatches > 0 ) {
				$out->wrapWikiMsg( "==$1==\n", 'titlematches' );
				$out->addHTML( $this->showMatches( $titleMatches ) );
			}
			$titleMatches->free();
		}

		if ( $textMatches ) {
			// output appropriate heading
			if ( $numTextMatches > 0 && $numTitleMatches > 0 ) {
				$out->addHTML( '<div class="mw-search-visualclear"></div>' );
				// if no title matches the heading is redundant
				$out->wrapWikiMsg( "==$1==\n", 'textmatches' );
			}

			// show results
			if ( $numTextMatches > 0 ) {
				$search->augmentSearchResults( $textMatches );
				$out->addHTML( $this->showMatches( $textMatches ) );
			}

			// show secondary interwiki results if any
			if ( $textMatches->hasInterwikiResults( SearchResultSet::SECONDARY_RESULTS ) ) {
				$out->addHTML( $this->showInterwiki( $textMatches->getInterwikiResults(
						SearchResultSet::SECONDARY_RESULTS ), $term ) );
			}
		}

		$hasOtherResults = $textMatches &&
			$textMatches->hasInterwikiResults( SearchResultSet::INLINE_RESULTS );

		// If we have no results and we have not already displayed an error message
		if ( $num === 0 && !$hasErrors ) {
			if ( !$this->offset ) {
				// If we have an offset the create link was rendered earlier in this function.
				// This class needs a good de-spaghettification, but for now this will
				// do the job.
				$this->showCreateLink( $title, $num, $titleMatches, $textMatches );
			}
			$out->wrapWikiMsg( "<p class=\"mw-search-nonefound\">\n$1</p>", [
				$hasOtherResults ? 'search-nonefound-thiswiki' : 'search-nonefound',
				wfEscapeWikiText( $term )
			] );
		}

		if ( $hasOtherResults ) {
			foreach ( $textMatches->getInterwikiResults( SearchResultSet::INLINE_RESULTS )
						as $interwiki => $interwikiResult ) {
				if ( $interwikiResult instanceof Status || $interwikiResult->numRows() == 0 ) {
					// ignore bad interwikis for now
					continue;
				}
				// TODO: wiki header
				$out->addHTML( $this->showMatches( $interwikiResult, $interwiki ) );
			}
		}

		if ( $textMatches ) {
			$textMatches->free();
		}

		$out->addHTML( '<div class="mw-search-visualclear"></div>' );

		if ( $prevnext ) {
			$out->addHTML( "<p class='mw-search-pager-bottom'>{$prevnext}</p>\n" );
		}

		$out->addHTML( "</div>" );

		Hooks::run( 'SpecialSearchResultsAppend', [ $this, $out, $term ] );
	}

	/**
	 * Produce wiki header for interwiki results
	 * @param string $interwiki Interwiki name
	 * @param SearchResultSet $interwikiResult The result set
	 * @return string
	 */
	protected function interwikiHeader( $interwiki, $interwikiResult ) {
		// TODO: we need to figure out how to name wikis correctly
		$wikiMsg = $this->msg( 'search-interwiki-results-' . $interwiki )->parse();
		return "<p class=\"mw-search-interwiki-header mw-search-visualclear\">\n$wikiMsg</p>";
	}

	/**
	 * Generates HTML shown to the user when we have a suggestion about a query
	 * that might give more results than their current query.
	 */
	protected function getDidYouMeanHtml( SearchResultSet $textMatches ) {
		# mirror Go/Search behavior of original request ..
		$params = [ 'search' => $textMatches->getSuggestionQuery() ];
		if ( $this->fulltext === null ) {
			$params['fulltext'] = 'Search';
		} else {
			$params['fulltext'] = $this->fulltext;
		}
		$stParams = array_merge( $params, $this->powerSearchOptions() );

		$linkRenderer = $this->getLinkRenderer();

		$snippet = $textMatches->getSuggestionSnippet() ?: null;
		if ( $snippet !== null ) {
			$snippet = new HtmlArmor( $snippet );
		}

		$suggest = $linkRenderer->makeKnownLink(
			$this->getPageTitle(),
			$snippet,
			[ 'id' => 'mw-search-DYM-suggestion' ],
			$stParams
		);

		# HTML of did you mean... search suggestion link
		return Html::rawElement(
			'div',
			[ 'class' => 'searchdidyoumean' ],
			$this->msg( 'search-suggest' )->rawParams( $suggest )->parse()
		);
	}

	/**
	 * Generates HTML shown to user when their query has been internally rewritten,
	 * and the results of the rewritten query are being returned.
	 *
	 * @param string $term The users search input
	 * @param SearchResultSet $textMatches The response to the users initial search request
	 * @return string HTML linking the user to their original $term query, and the one
	 *  suggested by $textMatches.
	 */
	protected function getDidYouMeanRewrittenHtml( $term, SearchResultSet $textMatches ) {
		// Showing results for '$rewritten'
		// Search instead for '$orig'

		$params = [ 'search' => $textMatches->getQueryAfterRewrite() ];
		if ( $this->fulltext === null ) {
			$params['fulltext'] = 'Search';
		} else {
			$params['fulltext'] = $this->fulltext;
		}
		$stParams = array_merge( $params, $this->powerSearchOptions() );

		$linkRenderer = $this->getLinkRenderer();

		$snippet = $textMatches->getQueryAfterRewriteSnippet() ?: null;
		if ( $snippet !== null ) {
			$snippet = new HtmlArmor( $snippet );
		}

		$rewritten = $linkRenderer->makeKnownLink(
			$this->getPageTitle(),
			$snippet,
			[ 'id' => 'mw-search-DYM-rewritten' ],
			$stParams
		);

		$stParams['search'] = $term;
		$stParams['runsuggestion'] = 0;
		$original = $linkRenderer->makeKnownLink(
			$this->getPageTitle(),
			$term,
			[ 'id' => 'mw-search-DYM-original' ],
			$stParams
		);

		return Html::rawElement(
			'div',
			[ 'class' => 'searchdidyoumean' ],
			$this->msg( 'search-rewritten' )->rawParams( $rewritten, $original )->escaped()
		);
	}

	/**
	 * @param Title $title
	 * @param int $num The number of search results found
	 * @param null|SearchResultSet $titleMatches Results from title search
	 * @param null|SearchResultSet $textMatches Results from text search
	 */
	protected function showCreateLink( $title, $num, $titleMatches, $textMatches ) {
		// show direct page/create link if applicable

		// Check DBkey !== '' in case of fragment link only.
		if ( is_null( $title ) || $title->getDBkey() === ''
			|| ( $titleMatches !== null && $titleMatches->searchContainedSyntax() )
			|| ( $textMatches !== null && $textMatches->searchContainedSyntax() )
		) {
			// invalid title
			// preserve the paragraph for margins etc...
			$this->getOutput()->addHTML( '<p></p>' );

			return;
		}

		$messageName = 'searchmenu-new-nocreate';
		$linkClass = 'mw-search-createlink';

		if ( !$title->isExternal() ) {
			if ( $title->isKnown() ) {
				$messageName = 'searchmenu-exists';
				$linkClass = 'mw-search-exists';
			} elseif ( $title->quickUserCan( 'create', $this->getUser() ) ) {
				$messageName = 'searchmenu-new';
			}
		}

		$params = [
			$messageName,
			wfEscapeWikiText( $title->getPrefixedText() ),
			Message::numParam( $num )
		];
		Hooks::run( 'SpecialSearchCreateLink', [ $title, &$params ] );

		// Extensions using the hook might still return an empty $messageName
		if ( $messageName ) {
			$this->getOutput()->wrapWikiMsg( "<p class=\"$linkClass\">\n$1</p>", $params );
		} else {
			// preserve the paragraph for margins etc...
			$this->getOutput()->addHTML( '<p></p>' );
		}
	}

	/**
	 * @param string $term
	 */
	protected function setupPage( $term ) {
		$out = $this->getOutput();
		if ( strval( $term ) !== '' ) {
			$out->setPageTitle( $this->msg( 'searchresults' ) );
			$out->setHTMLTitle( $this->msg( 'pagetitle' )
				->rawParams( $this->msg( 'searchresults-title' )->rawParams( $term )->text() )
				->inContentLanguage()->text()
			);
		}
		// add javascript specific to special:search
		$out->addModules( 'mediawiki.special.search' );
	}

	/**
	 * Return true if current search is a power (advanced) search
	 *
	 * @return bool
	 */
	protected function isPowerSearch() {
		return $this->profile === 'advanced';
	}

	/**
	 * Extract "power search" namespace settings from the request object,
	 * returning a list of index numbers to search.
	 *
	 * @param WebRequest $request
	 * @return array
	 */
	protected function powerSearch( &$request ) {
		$arr = [];
		foreach ( $this->searchConfig->searchableNamespaces() as $ns => $name ) {
			if ( $request->getCheck( 'ns' . $ns ) ) {
				$arr[] = $ns;
			}
		}

		return $arr;
	}

	/**
	 * Reconstruct the 'power search' options for links
	 *
	 * @return array
	 */
	protected function powerSearchOptions() {
		$opt = [];
		if ( !$this->isPowerSearch() ) {
			$opt['profile'] = $this->profile;
		} else {
			foreach ( $this->namespaces as $n ) {
				$opt['ns' . $n] = 1;
			}
		}

		return $opt + $this->extraParams;
	}

	/**
	 * Save namespace preferences when we're supposed to
	 *
	 * @return bool Whether we wrote something
	 */
	protected function saveNamespaces() {
		$user = $this->getUser();
		$request = $this->getRequest();

		if ( $user->isLoggedIn() &&
			$user->matchEditToken(
				$request->getVal( 'nsRemember' ),
				'searchnamespace',
				$request
			) && !wfReadOnly()
		) {
			// Reset namespace preferences: namespaces are not searched
			// when they're not mentioned in the URL parameters.
			foreach ( MWNamespace::getValidNamespaces() as $n ) {
				$user->setOption( 'searchNs' . $n, false );
			}
			// The request parameters include all the namespaces to be searched.
			// Even if they're the same as an existing profile, they're not eaten.
			foreach ( $this->namespaces as $n ) {
				$user->setOption( 'searchNs' . $n, true );
			}

			DeferredUpdates::addCallableUpdate( function () use ( $user ) {
				$user->saveSettings();
			} );

			return true;
		}

		return false;
	}

	/**
	 * Show whole set of results
	 *
	 * @param SearchResultSet $matches
	 * @param string $interwiki Interwiki name
	 *
	 * @return string
	 */
	protected function showMatches( $matches, $interwiki = null ) {
		global $wgContLang;

		$terms = $wgContLang->convertForSearchResult( $matches->termMatches() );
		$out = '';
		$result = $matches->next();
		$pos = $this->offset;

		if ( $result && $interwiki ) {
			$out .= $this->interwikiHeader( $interwiki, $matches );
		}

		$out .= "<ul class='mw-search-results'>\n";
		$widget = new \MediaWiki\Widget\Search\FullSearchResultWidget(
			$this,
			$this->getLinkRenderer()
		);
		while ( $result ) {
			$out .= $widget->render( $result, $terms, $pos++ );
			$result = $matches->next();
		}
		$out .= "</ul>\n";

		// convert the whole thing to desired language variant
		$out = $wgContLang->convert( $out );

		return $out;
	}

	/**
	 * Extract custom captions from search-interwiki-custom message
	 */
	protected function getCustomCaptions() {
		if ( is_null( $this->customCaptions ) ) {
			$this->customCaptions = [];
			// format per line <iwprefix>:<caption>
			$customLines = explode( "\n", $this->msg( 'search-interwiki-custom' )->text() );
			foreach ( $customLines as $line ) {
				$parts = explode( ":", $line, 2 );
				if ( count( $parts ) == 2 ) { // validate line
					$this->customCaptions[$parts[0]] = $parts[1];
				}
			}
		}
	}

	/**
	 * Show results from other wikis
	 *
	 * @param SearchResultSet|array $matches
	 * @param string $terms
	 *
	 * @return string
	 */
	protected function showInterwiki( $matches, $terms ) {
		global $wgContLang;

		// work out custom project captions
		$this->getCustomCaptions();

		if ( !is_array( $matches ) ) {
			$matches = [ $matches ];
		}

		$iwResults = [];
		foreach ( $matches as $set ) {
			$result = $set->next();
			while ( $result ) {
				if ( !$result->isBrokenTitle() ) {
					$iwResults[$result->getTitle()->getInterwiki()][] = $result;
				}
				$result = $set->next();
			}
		}

		$out = '';
		$widget = new MediaWiki\Widget\Search\SimpleSearchResultWidget(
			$this,
			$this->getLinkRenderer()
		);
		foreach ( $iwResults as $iwPrefix => $results ) {
			$out .= $this->iwHeaderHtml( $iwPrefix, $terms );
			$out .= "<ul class='mw-search-iwresults'>";
			foreach ( $results as $result ) {
				// This makes the bold asumption interwiki results are never paginated.
				// That's currently true, but could change at some point?
				$out .= $widget->render( $result, $terms, 0 );
			}
			$out .= "</ul>";
		}

		$out =
			"<div id='mw-search-interwiki'>" .
				"<div id='mw-search-interwiki-caption'>" .
					$this->msg( 'search-interwiki-caption' )->escaped() .
				"</div>" .
				$out .
			"</div>";

		// convert the whole thing to desired language variant
		return $wgContLang->convert( $out );
	}

	/**
	 * @param string $iwPrefix The interwiki prefix to render a header for
	 * @param string $terms The user-provided search terms
	 */
	protected function iwHeaderHtml( $iwPrefix, $terms ) {
		if ( isset( $this->customCaptions[$iwPrefix] ) ) {
			$caption = $this->customCaptions[$iwPrefix];
		} else {
			$iwLookup = MediaWiki\MediaWikiServices::getInstance()->getInterwikiLookup();
			$interwiki = $iwLookup->fetch( $iwPrefix );
			$parsed = wfParseUrl( wfExpandUrl( $interwiki ? $interwiki->getURL() : '/' ) );
			$caption = $this->msg( 'search-interwiki-default', $parsed['host'] )->text();
		}
		$searchLink = Linker::linkKnown(
			Title::newFromText( "$iwPrefix:Special:Search" ),
			$this->msg( 'search-interwiki-more' )->text(),
			[],
			[
				'search' => $terms,
				'fulltext' => 1,
			]
		);
		return
			"<div class='mw-search-interwiki-project'>" .
				"<span class='mw-search-interwiki-more'>{$searchLink}</span>" .
				$caption .
			"</div>";
	}

	/**
	 * Generates the power search box at [[Special:Search]]
	 *
	 * @param string $term Search term
	 * @param array $opts
	 * @return string HTML form
	 */
	protected function powerSearchBox( $term, $opts ) {
		global $wgContLang;

		// Groups namespaces into rows according to subject
		$rows = [];
		foreach ( $this->searchConfig->searchableNamespaces() as $namespace => $name ) {
			$subject = MWNamespace::getSubject( $namespace );
			if ( !array_key_exists( $subject, $rows ) ) {
				$rows[$subject] = "";
			}

			$name = $wgContLang->getConverter()->convertNamespace( $namespace );
			if ( $name == '' ) {
				$name = $this->msg( 'blanknamespace' )->text();
			}

			$rows[$subject] .=
				Xml::openElement( 'td' ) .
				Xml::checkLabel(
					$name,
					"ns{$namespace}",
					"mw-search-ns{$namespace}",
					in_array( $namespace, $this->namespaces )
				) .
				Xml::closeElement( 'td' );
		}

		$rows = array_values( $rows );
		$numRows = count( $rows );

		// Lays out namespaces in multiple floating two-column tables so they'll
		// be arranged nicely while still accommodating different screen widths
		$namespaceTables = '';
		for ( $i = 0; $i < $numRows; $i += 4 ) {
			$namespaceTables .= Xml::openElement( 'table' );

			for ( $j = $i; $j < $i + 4 && $j < $numRows; $j++ ) {
				$namespaceTables .= Xml::tags( 'tr', null, $rows[$j] );
			}

			$namespaceTables .= Xml::closeElement( 'table' );
		}

		$showSections = [ 'namespaceTables' => $namespaceTables ];

		Hooks::run( 'SpecialSearchPowerBox', [ &$showSections, $term, $opts ] );

		$hidden = '';
		foreach ( $opts as $key => $value ) {
			$hidden .= Html::hidden( $key, $value );
		}

		# Stuff to feed saveNamespaces()
		$remember = '';
		$user = $this->getUser();
		if ( $user->isLoggedIn() ) {
			$remember .= Xml::checkLabel(
				$this->msg( 'powersearch-remember' )->text(),
				'nsRemember',
				'mw-search-powersearch-remember',
				false,
				// The token goes here rather than in a hidden field so it
				// is only sent when necessary (not every form submission).
				[ 'value' => $user->getEditToken(
					'searchnamespace',
					$this->getRequest()
				) ]
			);
		}

		// Return final output
		return Xml::openElement( 'fieldset', [ 'id' => 'mw-searchoptions' ] ) .
			Xml::element( 'legend', null, $this->msg( 'powersearch-legend' )->text() ) .
			Xml::tags( 'h4', null, $this->msg( 'powersearch-ns' )->parse() ) .
			Xml::element( 'div', [ 'id' => 'mw-search-togglebox' ], '', false ) .
			Xml::element( 'div', [ 'class' => 'divider' ], '', false ) .
			implode( Xml::element( 'div', [ 'class' => 'divider' ], '', false ), $showSections ) .
			$hidden .
			Xml::element( 'div', [ 'class' => 'divider' ], '', false ) .
			$remember .
			Xml::closeElement( 'fieldset' );
	}

	/**
	 * @return array
	 */
	protected function getSearchProfiles() {
		// Builds list of Search Types (profiles)
		$nsAllSet = array_keys( $this->searchConfig->searchableNamespaces() );
		$defaultNs = $this->searchConfig->defaultNamespaces();
		$profiles = [
			'default' => [
				'message' => 'searchprofile-articles',
				'tooltip' => 'searchprofile-articles-tooltip',
				'namespaces' => $defaultNs,
				'namespace-messages' => $this->searchConfig->namespacesAsText(
					$defaultNs
				),
			],
			'images' => [
				'message' => 'searchprofile-images',
				'tooltip' => 'searchprofile-images-tooltip',
				'namespaces' => [ NS_FILE ],
			],
			'all' => [
				'message' => 'searchprofile-everything',
				'tooltip' => 'searchprofile-everything-tooltip',
				'namespaces' => $nsAllSet,
			],
			'advanced' => [
				'message' => 'searchprofile-advanced',
				'tooltip' => 'searchprofile-advanced-tooltip',
				'namespaces' => self::NAMESPACES_CURRENT,
			]
		];

		Hooks::run( 'SpecialSearchProfiles', [ &$profiles ] );

		foreach ( $profiles as &$data ) {
			if ( !is_array( $data['namespaces'] ) ) {
				continue;
			}
			sort( $data['namespaces'] );
		}

		return $profiles;
	}

	/**
	 * @param string $term
	 * @return string
	 */
	protected function searchProfileTabs( $term ) {
		$out = Html::element( 'div', [ 'class' => 'mw-search-visualclear' ] ) .
			Xml::openElement( 'div', [ 'class' => 'mw-search-profile-tabs' ] );

		$bareterm = $term;
		if ( $this->startsWithImage( $term ) ) {
			// Deletes prefixes
			$bareterm = substr( $term, strpos( $term, ':' ) + 1 );
		}

		$profiles = $this->getSearchProfiles();
		$lang = $this->getLanguage();

		// Outputs XML for Search Types
		$out .= Xml::openElement( 'div', [ 'class' => 'search-types' ] );
		$out .= Xml::openElement( 'ul' );
		foreach ( $profiles as $id => $profile ) {
			if ( !isset( $profile['parameters'] ) ) {
				$profile['parameters'] = [];
			}
			$profile['parameters']['profile'] = $id;

			$tooltipParam = isset( $profile['namespace-messages'] ) ?
				$lang->commaList( $profile['namespace-messages'] ) : null;
			$out .= Xml::tags(
				'li',
				[
					'class' => $this->profile === $id ? 'current' : 'normal'
				],
				$this->makeSearchLink(
					$bareterm,
					[],
					$this->msg( $profile['message'] )->text(),
					$this->msg( $profile['tooltip'], $tooltipParam )->text(),
					$profile['parameters']
				)
			);
		}
		$out .= Xml::closeElement( 'ul' );
		$out .= Xml::closeElement( 'div' );
		$out .= Xml::element( 'div', [ 'style' => 'clear:both' ], '', false );
		$out .= Xml::closeElement( 'div' );

		return $out;
	}

	/**
	 * @param string $term Search term
	 * @return string
	 */
	protected function searchOptions( $term ) {
		$out = '';
		$opts = [];
		$opts['profile'] = $this->profile;

		if ( $this->isPowerSearch() ) {
			$out .= $this->powerSearchBox( $term, $opts );
		} else {
			$form = '';
			Hooks::run( 'SpecialSearchProfileForm', [ $this, &$form, $this->profile, $term, $opts ] );
			$out .= $form;
		}

		return $out;
	}

	/**
	 * @param string $term
	 * @param int $resultsShown
	 * @param int $totalNum
	 * @return string
	 */
	protected function shortDialog( $term, $resultsShown, $totalNum ) {
		$searchWidget = new MediaWiki\Widget\SearchInputWidget( [
			'id' => 'searchText',
			'name' => 'search',
			'autofocus' => trim( $term ) === '',
			'value' => $term,
			'dataLocation' => 'content',
			'infusable' => true,
		] );

		$layout = new OOUI\ActionFieldLayout( $searchWidget, new OOUI\ButtonInputWidget( [
			'type' => 'submit',
			'label' => $this->msg( 'searchbutton' )->text(),
			'flags' => [ 'progressive', 'primary' ],
		] ), [
			'align' => 'top',
		] );

		$out =
			Html::hidden( 'title', $this->getPageTitle()->getPrefixedText() ) .
			Html::hidden( 'profile', $this->profile ) .
			Html::hidden( 'fulltext', 'Search' ) .
			$layout;

		// Results-info
		if ( $totalNum > 0 && $this->offset < $totalNum ) {
			$top = $this->msg( 'search-showingresults' )
				->numParams( $this->offset + 1, $this->offset + $resultsShown, $totalNum )
				->numParams( $resultsShown )
				->parse();
			$out .= Xml::tags( 'div', [ 'class' => 'results-info' ], $top );
		}

		return $out;
	}

	/**
	 * Make a search link with some target namespaces
	 *
	 * @param string $term
	 * @param array $namespaces Ignored
	 * @param string $label Link's text
	 * @param string $tooltip Link's tooltip
	 * @param array $params Query string parameters
	 * @return string HTML fragment
	 */
	protected function makeSearchLink( $term, $namespaces, $label, $tooltip, $params = [] ) {
		$opt = $params;
		foreach ( $namespaces as $n ) {
			$opt['ns' . $n] = 1;
		}

		$stParams = array_merge(
			[
				'search' => $term,
				'fulltext' => $this->msg( 'search' )->text()
			],
			$opt
		);

		return Xml::element(
			'a',
			[
				'href' => $this->getPageTitle()->getLocalURL( $stParams ),
				'title' => $tooltip
			],
			$label
		);
	}

	/**
	 * Check if query starts with image: prefix
	 *
	 * @param string $term The string to check
	 * @return bool
	 */
	protected function startsWithImage( $term ) {
		global $wgContLang;

		$parts = explode( ':', $term );
		if ( count( $parts ) > 1 ) {
			return $wgContLang->getNsIndex( $parts[0] ) == NS_FILE;
		}

		return false;
	}

	/**
	 * @since 1.18
	 *
	 * @return SearchEngine
	 */
	public function getSearchEngine() {
		if ( $this->searchEngine === null ) {
			$this->searchEngine = $this->searchEngineType ?
				MediaWikiServices::getInstance()->getSearchEngineFactory()->create( $this->searchEngineType ) :
				MediaWikiServices::getInstance()->newSearchEngine();
		}

		return $this->searchEngine;
	}

	/**
	 * Current search profile.
	 * @return null|string
	 */
	function getProfile() {
		return $this->profile;
	}

	/**
	 * Current namespaces.
	 * @return array
	 */
	function getNamespaces() {
		return $this->namespaces;
	}

	/**
	 * Users of hook SpecialSearchSetupEngine can use this to
	 * add more params to links to not lose selection when
	 * user navigates search results.
	 * @since 1.18
	 *
	 * @param string $key
	 * @param mixed $value
	 */
	public function setExtraParam( $key, $value ) {
		$this->extraParams[$key] = $value;
	}

	protected function getGroupName() {
		return 'pages';
	}
}
