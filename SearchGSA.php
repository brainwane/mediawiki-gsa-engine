<?php
# Copyright (C) 2004 Brion Vibber <brion@pobox.com>
# http://www.mediawiki.org/
#
# This program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License along
# with this program; if not, write to the Free Software Foundation, Inc.,
# 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
# http://www.gnu.org/copyleft/gpl.html

/**
 * @file
 * @ingroup Search
 */

/**
 * Search engine hook for MySQL 4+
 * @ingroup Search
 */
class SearchGSA extends SearchEngine {
	var $strictMatching = true;

	/** @todo document */
	function __construct( $db ) {
		$this->db = $db;
	}

	/** @todo document */
	function parseQuery( $filteredText, $fulltext ) {
		global $wgContLang;
		$lc = SearchEngine::legalSearchChars(); // Minus format chars
		$searchon = '';
		$this->searchTerms = array();

		# FIXME: This doesn't handle parenthetical expressions.
		$m = array();
		if( preg_match_all( '/([-+<>~]?)(([' . $lc . ']+)(\*?)|"[^"]*")/',
			  $filteredText, $m, PREG_SET_ORDER ) ) {
			foreach( $m as $terms ) {
				if( $searchon !== '' ) $searchon .= ' ';
				if( $this->strictMatching && ($terms[1] == '') ) {
					$terms[1] = '+';
				}
				$searchon .= $terms[1] . $wgContLang->stripForSearch( $terms[2] );
				if( !empty( $terms[3] ) ) {
					// Match individual terms in result highlighting...
					$regexp = preg_quote( $terms[3], '/' );
					if( $terms[4] ) $regexp .= "[0-9A-Za-z_]+";
				} else {
					// Match the quoted term in result highlighting...
					$regexp = preg_quote( str_replace( '"', '', $terms[2] ), '/' );
				}
				$this->searchTerms[] = $regexp;
			}
			wfDebug( "Would search with '$searchon'\n" );
			wfDebug( 'Match with /' . implode( '|', $this->searchTerms ) . "/\n" );
		} else {
			wfDebug( "Can't understand search query '{$filteredText}'\n" );
		}

		$searchon = $this->db->strencode( $searchon );
		$field = $this->getIndexField( $fulltext );
		return " MATCH($field) AGAINST('$searchon' IN BOOLEAN MODE) ";
	}

	public static function legalSearchChars() {
		return "\"*" . parent::legalSearchChars();
	}

	/**
	 * Perform a full text search query and return a result set.
	 *
	 * @param string $term - Raw search term
	 * @return MySQLSearchResultSet
	 * @access public
	 */
	function searchText( $term ) {
		$resultSet = $this->db->resultObject( $this->db->query( $this->getQuery( $this->filter( $term ), true ) ) );
		$xml = new SimpleXMLElement(file_get_contents("http://10.2.74.122/search?ie=&q=" .urlencode($term) . "&site=my_collection&output=xml&client=my_collection&btnG=Intranet+Search&access=p&lr=&ip=10.2.74.5&oe=&start=0&num=100"));
		return new GSASearchResultSet( $xml, array($term) );
	}

	/**
	 * Perform a title-only search query and return a result set.
	 *
	 * @param string $term - Raw search term
	 * @return MySQLSearchResultSet
	 * @access public
	 */
	function searchTitle( $term ) {
		$resultSet = $this->db->resultObject( $this->db->query( $this->getQuery( $this->filter( $term ), false ) ) );
		$xml = new SimpleXMLElement(file_get_contents("http://10.2.74.122/search?ie=&q=" .urlencode($term) . "&site=my_collection&output=xml&client=my_collection&btnG=Intranet+Search&access=p&lr=&ip=10.2.74.5&oe=&start=0&num=100"));
		return new GSASearchResultSet( $xml, array($term) );
	}


	/**
	 * Return a partial WHERE clause to exclude redirects, if so set
	 * @return string
	 * @private
	 */
	function queryRedirect() {
		if( $this->showRedirects ) {
			return '';
		} else {
			return 'AND page_is_redirect=0';
		}
	}

	/**
	 * Return a partial WHERE clause to limit the search to the given namespaces
	 * @return string
	 * @private
	 */
	function queryNamespaces() {
		if( is_null($this->namespaces) )
			return '';  # search all
		$namespaces = implode( ',', $this->namespaces );
		if ($namespaces == '') {
			$namespaces = '0';
		}
		return 'AND page_namespace IN (' . $namespaces . ')';
	}

	/**
	 * Return a LIMIT clause to limit results on the query.
	 * @return string
	 * @private
	 */
	function queryLimit() {
		return $this->db->limitResult( '', $this->limit, $this->offset );
	}

	/**
	 * Does not do anything for generic search engine
	 * subclasses may define this though
	 * @return string
	 * @private
	 */
	function queryRanking( $filteredTerm, $fulltext ) {
		return '';
	}

	/**
	 * Construct the full SQL query to do the search.
	 * The guts shoulds be constructed in queryMain()
	 * @param string $filteredTerm
	 * @param bool $fulltext
	 * @private
	 */
	function getQuery( $filteredTerm, $fulltext ) {
		return $this->queryMain( $filteredTerm, $fulltext ) . ' ' .
			$this->queryRedirect() . ' ' .
			$this->queryNamespaces() . ' ' .
			$this->queryRanking( $filteredTerm, $fulltext ) . ' ' .
			$this->queryLimit();
	}


	/**
	 * Picks which field to index on, depending on what type of query.
	 * @param bool $fulltext
	 * @return string
	 */
	function getIndexField( $fulltext ) {
		return $fulltext ? 'si_text' : 'si_title';
	}

	/**
	 * Get the base part of the search query.
	 * The actual match syntax will depend on the server
	 * version; MySQL 3 and MySQL 4 have different capabilities
	 * in their fulltext search indexes.
	 *
	 * @param string $filteredTerm
	 * @param bool $fulltext
	 * @return string
	 * @private
	 */
	function queryMain( $filteredTerm, $fulltext ) {
		$match = $this->parseQuery( $filteredTerm, $fulltext );
		$page        = $this->db->tableName( 'page' );
		$searchindex = $this->db->tableName( 'searchindex' );
		return 'SELECT page_id, page_namespace, page_title ' .
			"FROM $page,$searchindex " .
			'WHERE page_id=si_page AND ' . $match;
	}

	/**
	 * Create or update the search index record for the given page.
	 * Title and text should be pre-processed.
	 *
	 * @param int $id
	 * @param string $title
	 * @param string $text
	 */
	function update( $id, $title, $text ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->replace( 'searchindex',
			array( 'si_page' ),
			array(
				'si_page' => $id,
				'si_title' => $title,
				'si_text' => $text
			), __METHOD__ );
	}

	/**
	 * Update a search index record's title only.
	 * Title should be pre-processed.
	 *
	 * @param int $id
	 * @param string $title
	 */
    function updateTitle( $id, $title ) {
		$dbw = wfGetDB( DB_MASTER );

		$dbw->update( 'searchindex',
			array( 'si_title' => $title ),
			array( 'si_page'  => $id ),
			__METHOD__,
			array( $dbw->lowPriorityOption() ) );
	}
}

/**
 * @ingroup Search
 */
class GSASearchResultSet extends SearchResultSet {
	function GSASearchResultSet( $resultSet, $terms ) {
		$this->mResultSet = $resultSet;
		$this->mTerms = $terms;
		$this->counter = 0;
	}

	function termMatches() {
		return $this->mTerms;
	}

	function numRows() {
		return $this->mResultSet->RES->M;
	}

	function next() {
		if ( $this->counter < count($this->mResultSet->RES->R) ) {
			return new GSASearchResult( $this->mResultSet->RES->R[$this->counter++] );
		} else {
			return false;
		}
		
	}

	function free() {
		return;
	}
}

class GSASearchResult extends SearchResult {
	var $mRevision = null;

	function GSASearchResult( $row ) {
		global $wgServer, $wgUploadPath;

		$this->gsa_row = $row;

		$url = preg_replace(sprintf("/%s%s.*\/(.*)/", preg_quote($wgServer, '/'), 
				preg_quote("/images",'/')), "$wgServer/Image:$1", $this->gsa_row->U);

		$this->mTitle = Title::newFromURL( str_replace("$wgServer/", '', $url) );
		if( !is_null($this->mTitle) )
			$this->mRevision = Revision::newFromTitle( $this->mTitle );
	}
	
	/**
	 * Check if this is result points to an invalid title
	 *
	 * @return boolean
	 * @access public
	 */
	function isBrokenTitle(){
		if( is_null($this->mTitle) )
			return true;
		return false;
	}
	
	/**
	 * Check if target page is missing, happens when index is out of date
	 * 
	 * @return boolean
	 * @access public
	 */
	function isMissingRevision(){
		if( !$this->mRevision )
			return true;
		return false;
	}

	/**
	 * @return Title
	 * @access public
	 */
	function getTitle() {
		return $this->mTitle;
	}

	/**
	 * @return double or null if not supported
	 */
	function getScore() {
		return null;
	}

	/**
	 * Lazy initialization of article text from DB
	 */
	protected function initText(){
		if( !isset($this->mText) ){
			$this->mText = $this->mRevision->getText();
		}
	}
	
	/**
	 * @param array $terms terms to highlight
	 * @return string highlighted text snippet, null (and not '') if not supported 
	 */
	function getTextSnippet($terms){
		global $wgUser, $wgAdvancedSearchHighlighting;
		$this->initText();
		return $this->gsa_row->S;
		list($contextlines,$contextchars) = SearchEngine::userHighlightPrefs($wgUser);
		$h = new SearchHighlighter();
		if( $wgAdvancedSearchHighlighting )
			return $h->highlightText( $this->mText, $terms, $contextlines, $contextchars );
		else
			return $h->highlightSimple( $this->mText, $terms, $contextlines, $contextchars );
	}
	
	/**
	 * @param array $terms terms to highlight
	 * @return string highlighted title, '' if not supported
	 */
	function getTitleSnippet($terms){
		return $this->gsa_row->T;
	}

	/**
	 * @param array $terms terms to highlight
	 * @return string highlighted redirect name (redirect to this page), '' if none or not supported
	 */
	function getRedirectSnippet($terms){
		return '';
	}

	/**
	 * @return Title object for the redirect to this page, null if none or not supported
	 */
	function getRedirectTitle(){
		return null;
	}

	/**
	 * @return string highlighted relevant section name, null if none or not supported
	 */
	function getSectionSnippet(){
		return '';
	}

	/**
	 * @return Title object (pagename+fragment) for the section, null if none or not supported
	 */
	function getSectionTitle(){
		return null;
	}

	/**
	 * @return string timestamp
	 */
	function getTimestamp(){
		return "";
		//return $this->mRevision->getTimestamp();
	}

	/**
	 * @return int number of words
	 */
	function getWordCount(){
		$this->initText();
		return str_word_count( $this->mText );
	}

	/**
	 * @return int size in bytes
	 */
	function getByteSize(){
		$this->initText();
		return strlen( $this->mText );
	}
	
	/**
	 * @return boolean if hit has related articles
	 */
	function hasRelated(){
		return false;
	}
	
	/**
	 * @return interwiki prefix of the title (return iw even if title is broken)
	 */
	function getInterwikiPrefix(){
		return '';
	}
}
