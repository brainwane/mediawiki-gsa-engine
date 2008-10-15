<?php

/**
 * @file
 * @ingroup Search
 */

/**
 * Search engine hook for Google Search Appliance
 * @ingroup Search
 */
class SearchGSA extends SearchEngine {

	/**
	 * Perform a full text search query and return a result set.
	 *
	 * @param string $term - Raw search term
	 * @return GSASearchResultSet
	 * @access public
	 */
	function searchText( $term ) {
		global $wgGSA, $wgServer;

		$params = array( 'as_sitesearch' => $wgServer,
				 'q' => $term,
				 'site' => 'my_collection',
				 'client' => 'my_collection',
				 'output' => 'xml',
				 'start' => $this->offset,
				 'num' => $this->limit );
		$request = sprintf("%s?%s", $wgGSA, http_build_query($params));
		$xml = new SimpleXMLElement(file_get_contents($request));
		//print "$request <br/>";

		return new GSASearchResultSet( $xml, array($term) );
	}

	/**
	 * Perform a title-only search query and return a result set.
	 *
	 * @param string $term - Raw search term
	 * @return GSASearchResultSet
	 * @access public
	 */
	function searchTitle( $term ) {
		global $wgGSA, $wgServer;

		$params = array( 'as_sitesearch' => $wgServer,
				 'as_occt' => 'title',
				 'q' => $term,
				 'site' => 'my_collection',
				 'client' => 'my_collection',
				 'output' => 'xml',
				 'start' => $this->offset,
				 'num' => $this->limit );
		$request = sprintf("%s?%s", $wgGSA, http_build_query($params));
		$xml = new SimpleXMLElement(file_get_contents($request));
		//print "$request <br/>";

		return new GSASearchResultSet( $xml, array($term) );
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

	function hasSuggestion() {
		return array_key_exists('Spelling', $this->mResultSet->children());
	}
	function getSuggestionQuery() {
		return strip_tags($this->mResultSet->Spelling->Suggestion);
	}

	function getSuggestionSnippet() {
		return $this->mResultSet->Spelling->Suggestion;
	}

	function getTotalHits() {
		return $this->mResultSet->RES->M;
	}
	

	function numRows() {
		return count($this->mResultSet->RES->R);
	}

	function next() {
		if ( $this->counter < count($this->mResultSet->RES->R) ) {
			return new GSASearchResult( $this->mResultSet->RES->R[$this->counter++] );
		} else {
			return false;
		}
		
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
		$this->initText();
		return $this->gsa_row->S;
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
		return $this->mRevision->getTimestamp();
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
