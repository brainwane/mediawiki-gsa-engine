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
		
		$xml = array();
		$start = null;
		$end = 0;
		for ( $i=$this->offset; 
			$i < $this->limit + $this->offset; 
			$i += 100 ) 
		{
			$params = array( 'as_sitesearch' => $wgServer,
					 'q' => $term,
					 'site' => 'my_collection',
					 'client' => 'my_collection',
					 'output' => 'xml',
					 'start' => $i,
					 'num' => ( $this->limit > 100 ? 100 : $this->limit ) );
			$request = sprintf("%s?%s", $wgGSA, http_build_query($params));
			$new_xml = new SimpleXMLElement(file_get_contents($request));
			$start = is_null($start) || $start > $new_xml->RES['SN'] ? $new_xml->RES['SN'] : $start;
			$end = $end < $new_xml->RES['EN'] ? $new_xml->RES['EN'] : $end;
			$xml[] = $new_xml;
		}
		//print "$request <br/>";

		return new GSASearchResultSet($xml, array($term), $this->limit, $this->offset, $start, $end);
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

		$xml = array();
		$start = null;
		$end = 0;
		for ( $i=$this->offset; 
			$i < $this->limit + $this->offset; 
			$i += 100 ) 
		{
			$params = array( 'as_sitesearch' => $wgServer,
					 'as_occt' => 'title',
					 'q' => $term,
					 'site' => 'my_collection',
					 'client' => 'my_collection',
					 'output' => 'xml',
					 'start' => $i,
					 'num' => ( $this->limit > 100 ? 100 : $this->limit ) );
			$request = sprintf("%s?%s", $wgGSA, http_build_query($params));
			$new_xml = new SimpleXMLElement(file_get_contents($request));
			$start = is_null($start) || $start > $new_xml->RES['SN'] ? $new_xml->RES['SN'] : $start;
			$end = $end < $new_xml->RES['EN'] ? $new_xml->RES['EN'] : $end;
			$xml[] = $new_xml;
		}
		//print "$request <br/>";

		return new GSASearchResultSet($xml, array($term), $this->limit, $this->offset, $start, $end);
	}


}

/**
 * @ingroup Search
 */
class GSASearchResultSet extends SearchResultSet {
	function GSASearchResultSet( $resultSet, $terms, $limit, $offset, $start, $end ) {
		$this->mResultSet = $resultSet;
		$this->mTerms = $terms;
		$this->limit = $limit;
		$this->offset = $offset;
		$this->counter = array(0, 0);
		$this->start = $start;
		$this->end = $end;
	}

	function termMatches() {
		return $this->mTerms;
	}

	function hasSuggestion() {
		return array_key_exists('Spelling', $this->mResultSet[0]->children());
	}
	function getSuggestionQuery() {
		return strip_tags($this->mResultSet[0]->Spelling->Suggestion);
	}

	function getSuggestionSnippet() {
		return $this->mResultSet[0]->Spelling->Suggestion;
	}

	function getTotalHits() {
		return null;
	}
	

	function numRows() {
		if ( $this->start < $this->offset || empty($this->start) )
			return 0;
		$num = $this->end - $this->start + 1;
		return $num;
	}

	function next() {

		if ( $this->counter[1] >= count($this->mResultSet[$this->counter[0]]->RES->R) ) {
			$this->counter[0]++;
			$this->counter[1] = 0;
		}

		if ( $this->counter[0] >= count($this->mResultSet) ) 
			return false;
		return new GSASearchResult( $this->mResultSet[$this->counter[0]]->RES->R[$this->counter[1]++] );
		
	}

}

class GSASearchResult extends SearchResult {
	var $mRevision = null;

	function GSASearchResult( $row ) {
		global $wgServer, $wgUploadPath;

		$this->gsa_row = $row;

		$url = preg_replace(sprintf("/%s%s.*\/(.*)/", preg_quote($wgServer, '/'), 
				preg_quote($wgUploadPath,'/')), "$wgServer/Image:$1", $this->gsa_row->U);

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
