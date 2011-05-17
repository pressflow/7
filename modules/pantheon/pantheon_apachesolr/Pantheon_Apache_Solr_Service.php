<?php

/**
 * Copyright (c) 2007-2009, Conduit Internet Technologies, Inc.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 *  - Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *  - Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *  - Neither the name of Conduit Internet Technologies, Inc. nor the names of
 *    its contributors may be used to endorse or promote products derived from
 *    this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @copyright Copyright 2007-2009 Conduit Internet Technologies, Inc. (http://conduit-it.com)
 * @license New BSD (http://solr-php-client.googlecode.com/svn/trunk/COPYING)
 * @version $Id: Service.php 22 2009-11-09 22:46:54Z donovan.jimenez $
 *
 * @package Apache
 * @subpackage Solr
 * @author Donovan Jimenez <djimenez@conduit-it.com>
 */

/**
 * Additional code Copyright (c) 2008-2011 by Robert Douglass, James McKinney,
 * Jacob Singh, Alejandro Garza, Peter Wolanin, and additional contributors.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.

 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License
 * for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program as the file LICENSE.txt; if not, please see
 * http://www.gnu.org/licenses/old-licenses/gpl-2.0.txt.
 */

/**
 * Starting point for the Solr API. Represents a Solr server resource and has
 * methods for pinging, adding, deleting, committing, optimizing and searching.
 */

class DrupalApacheSolrService {
  /**
   * How NamedLists should be formatted in the output.  This specifically effects facet counts. Valid values
   * are 'map' (default) or 'flat'.
   *
   */
  const NAMED_LIST_FORMAT = 'map';

  /**
   * Servlet mappings
   */
  const PING_SERVLET = 'admin/ping';
  const UPDATE_SERVLET = 'update';
  const SEARCH_SERVLET = 'select';
  const LUKE_SERVLET = 'admin/luke';
  const STATS_SERVLET = 'admin/stats.jsp';

  /**
   * Server url
   *
   * @var array
   */
  protected $parsed_url;

  /**
   * Constructed servlet full path URLs
   *
   * @var string
   */
  protected $update_url;

  /**
   * Default HTTP timeout when one is not specified (initialized to default_socket_timeout ini setting)
   *
   * var float
   */
  protected $_defaultTimeout;
  protected $env_id;
  protected $luke;
  protected $stats;

  /**
   * Call the /admin/ping servlet, to test the connection to the server.
   *
   * @param $timeout
   *   maximum time to wait for ping in seconds, -1 for unlimited (default 2).
   * @return
   *   (float) seconds taken to ping the server, FALSE if timeout occurs.
   */
  public function ping($timeout = 2) {
    $start = microtime(TRUE);

    if ($timeout <= 0.0) {
      $timeout = -1;
    }
    $pingUrl = $this->_constructUrl(self::PING_SERVLET);
    // Attempt a HEAD request to the solr ping url.
    $options = array(
      'method' => 'HEAD',
      'timeout' => $timeout,
    );
    $response = $this->_makeHttpRequest($pingUrl, $options);

    if ($response->code == 200) {
      // Add 0.1 ms to the ping time so we never return 0.0.
      return microtime(TRUE) - $start + 0.0001;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Sets $this->luke with the meta-data about the index from admin/luke.
   */
  protected function setLuke($num_terms = 0) {
    if (empty($this->luke[$num_terms])) {
      $url = $this->_constructUrl(self::LUKE_SERVLET, array('numTerms' => "$num_terms", 'wt' => 'json'));
      if ($this->env_id) {
        $cid = $this->env_id . ":luke:" . drupal_hash_base64($url);
        $cache = cache_get($cid, 'cache_apachesolr');
        if (isset($cache->data)) {
          $this->luke = $cache->data;
        }
      }
    }
    // Second pass to populate the cache if necessary.
    if (empty($this->luke[$num_terms])) {
      $this->luke[$num_terms] = $this->_sendRawGet($url);
      if ($this->env_id) {
        cache_set($cid, $this->luke, 'cache_apachesolr');
      }
    }
  }

 /**
   * Get just the field meta-data about the index.
   */
  public function getFields($num_terms = 0) {
    $facets = variable_get('apachesolr_enabled_facets', array('apachesolr_search' => array('content' => 1)));
    return (object) $facets['apachesolr_search'];
    // return $this->getLuke($num_terms)->fields;
  }

  /**
   * Get meta-data about the index.
   *
   * Can we construct this synthetically?
   */
  public function getLuke($num_terms = 0) {
    $luke = array(
      'index' => array(
        'numDocs' => 10, // toootaly fake
      ),
    );
    return (object) $luke;
  }

  /**
   * Sets $this->stats with the information about the Solr Core form /admin/stats.jsp
   */
  protected function setStats() {
    $data = $this->getLuke();
    // Only try to get stats if we have connected to the index.
    if (empty($this->stats) && isset($data->index->numDocs)) {
      $url = $this->_constructUrl(self::STATS_SERVLET);
      if ($this->env_id) {
        $this->stats_cid = $this->env_id . ":stats:" . drupal_hash_base64($url);
        $cache = cache_get($this->stats_cid, 'cache_apachesolr');
        if (isset($cache->data)) {
          $this->stats = simplexml_load_string($cache->data);
        }
      }
      // Second pass to populate the cache if necessary.
      if (empty($this->stats)) {
        $response = $this->_sendRawGet($url);
        $this->stats = simplexml_load_string($response->data);
        if ($this->env_id) {
          cache_set($this->stats_cid, $response->data, 'cache_apachesolr');
        }
      }
    }
  }

  /**
   * Get information about the Solr Core.
   *
   * Returns a Simple XMl document
   */
  public function getStats() {
    if (!isset($this->stats)) {
      $this->setStats();
    }
    return $this->stats;
  }

  /**
   * Get summary information about the Solr Core.
   */
  public function getStatsSummary() {
    $stats = $this->getStats();
    $summary = array(
     '@pending_docs' => '',
     '@autocommit_time_seconds' => '',
     '@autocommit_time' => '',
     '@deletes_by_id' => '',
     '@deletes_by_query' => '',
     '@deletes_total' => '',
     '@schema_version' => '',
     '@core_name' => '',
    );

    if (!empty($stats)) {
      $docs_pending_xpath = $stats->xpath('//stat[@name="docsPending"]');
      $summary['@pending_docs'] = (int) trim($docs_pending_xpath[0]);
      $max_time_xpath = $stats->xpath('//stat[@name="autocommit maxTime"]');
      $max_time = (int) trim(current($max_time_xpath));
      // Convert to seconds.
      $summary['@autocommit_time_seconds'] = $max_time / 1000;
      $summary['@autocommit_time'] = format_interval($max_time / 1000);
      $deletes_id_xpath = $stats->xpath('//stat[@name="deletesById"]');
      $summary['@deletes_by_id'] = (int) trim($deletes_id_xpath[0]);
      $deletes_query_xpath = $stats->xpath('//stat[@name="deletesByQuery"]');
      $summary['@deletes_by_query'] = (int) trim($deletes_query_xpath[0]);
      $summary['@deletes_total'] = $summary['@deletes_by_id'] + $summary['@deletes_by_query'];
      $schema = $stats->xpath('/solr/schema[1]');
      $summary['@schema_version'] = trim($schema[0]);;
      $core = $stats->xpath('/solr/core[1]');
      $summary['@core_name'] = trim($core[0]);
    }

    return $summary;
  }

  /**
   * Clear cached Solr data.
   */
  public function clearCache() {
    // Don't clear cached data if the server is unavailable.
    if (@$this->ping()) {
      $this->_clearCache();
    }
    else {
      throw new Exception('No Solr instance available when trying to clear the cache.');
    }
  }

  protected function _clearCache() {
    if ($this->env_id) {
      cache_clear_all($this->env_id . ":stats:", 'cache_apachesolr', TRUE);
      cache_clear_all($this->env_id . ":luke:", 'cache_apachesolr', TRUE);
    }
    $this->luke = array();
    $this->stats = NULL;
  }

  /**
   * Constructor
   *
   * @param $url
   *   The URL to the Solr server, possibly including a core name.  E.g. http://localhost:8983/solr/
   *   or https://search.example.com/solr/core99/
   * @param $env_id
   *   The machine name of a corresponding saved configuration used for loading
   *   data like which facets are enabled.
   */
  public function __construct($url, $env_id = NULL) {
    $this->env_id = $env_id;
    $this->setUrl($url);

    // determine our default http timeout from ini settings
    $this->_defaultTimeout = (int) ini_get('default_socket_timeout');

    // double check we didn't get 0 for a timeout
    if ($this->_defaultTimeout <= 0) {
      $this->_defaultTimeout = 60;
    }
  }

  function getId() {
    return $this->env_id;
  }

  /**
   * Check the reponse code and thow an exception if it's not 200.
   *
   * @param stdClass $response
   *   response object.
   *
   * @return
   *  response object
   * @thows Exception
   */
  protected function checkResponse($response) {
    $code = (int) $response->code;
    if ($code != 200) {
      if ($code >= 400 && $code != 403 && $code != 404) {
        // Add details, like Solr's exception message.
        $response->status_message .= $response->data;
      }
      throw new Exception('"' . $code . '" Status: ' . $response->status_message);
    }
    return $response;
  }

  /**
   * Make a request to a servlet (a path) that's not a standard path.
   *
   * @param string $servlet
   *   A path to be added to the base Solr path. e.g. 'extract/tika'
   *
   * @param array $params
   *   Any request parameters when constructing the URL.
   *
   * @param array $options
   *  @see drupal_http_request() $options.
   *
   * @return
   *  response object
   *
   * @thows Exception
   */
  public function makeServletRequest($servlet, $params = array(), $options = array()) {
    // Add default params.
    $params += array(
      'wt' => 'json',
    );

    $url = $this->_constructUrl($servlet, $params);
    $response = $this->_makeHttpRequest($url, $options);
    return $this->checkResponse($response);
  }

  /**
   * Central method for making a GET operation against this Solr Server
   */
  protected function _sendRawGet($url, $options = array()) {
    $response = $this->_makeHttpRequest($url, $options);
    return $this->checkResponse($response);
  }

  /**
   * Central method for making a POST operation against this Solr Server
   */
  protected function _sendRawPost($url, $options = array()) {
    $options['method'] = 'POST';
    // Normally we use POST to send XML documents.
    if (!isset($options['headers']['Content-Type'])) {
      $options['headers']['Content-Type'] = 'text/xml; charset=UTF-8';
    }
    $response = $this->_makeHttpRequest($url, $options);
    return $this->checkResponse($response);
  }

  /**
   * Central method for making the actual http request to the Solr Server
   *
   * This is just a wrapper around drupal_http_request().
   */
  protected function _makeHttpRequest($url, $options = array()) {
    // Hacking starts here.
    // $result = drupal_http_request($url, $headers, $method, $content);
    $client_cert = '/etc/pantheon/system.pem';
    $port = variable_get('apachesolr_port', '443');
    $ch = curl_init();
    // Janktastic, but the SolrClient assumes http
    $url = str_replace('http://', 'https://', $url);
    curl_setopt($ch, CURLOPT_SSLCERT, $client_cert);

        
    // set URL and other appropriate options
    $opts = array(
      CURLOPT_URL => $url,
      CURLOPT_HEADER => 1,
      CURLOPT_PORT => $port,
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_HTTPHEADER => array('Content-type:text/xml; charset=utf-8'),
    );
    curl_setopt_array($ch, $opts);
    
    // If we are doing a delete request...
    if ($options['method'] == 'DELETE') {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    }
    // If we are doing a put request...
    if ($options['method'] == 'PUT') {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    }
    // If we are doing a put request...
    if ($options['method'] == 'POST') {
      curl_setopt($ch, CURLOPT_POST, 1);
    }
    if ($options['data'] != '') {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $options['data']);
    }

    $response = curl_exec($ch);

    if ($response == NULL) {
      // TODO; better error handling
      watchdog('pantheon_apachesolr', "Error !error connecting to !url on port !port", array('!error' => curl_error($ch), '!url' => $url, '!port' => $port), WATCHDOG_ERROR);
    }
    else {
      // mimick the $result object from drupal_http_request()
      $result = new stdClass();
      list($split, $result->data) = explode("\r\n\r\n", $response, 2);
      $split = preg_split("/\r\n|\n|\r/", $split);
      list($result->protocol, $result->code, $result->status_message) = explode(' ', trim(array_shift($split)), 3);
      // Parse headers.
      $result->headers = array();
      while ($line = trim(array_shift($split))) {
        list($header, $value) = explode(':', $line, 2);
        if (isset($result->headers[$header]) && $header == 'Set-Cookie') {
          // RFC 2109: the Set-Cookie response header comprises the token Set-
          // Cookie:, followed by a comma-separated list of one or more cookies.
          $result->headers[$header] .= ',' . trim($value);
        }
        else {
          $result->headers[$header] = trim($value);
        }
      }
    }

    $result = drupal_http_request($url, $options);

    if (!isset($result->code) || $result->code < 0) {
      $result->code = 0;
      $result->status_message = 'Request failed';
      $result->protocol = 'HTTP/1.0';
    }
    // Additional information may be in the error property.
    if (isset($result->error)) {
      $result->status_message .= ': ' . check_plain($result->error);
    }

    if (!isset($result->data)) {
      $result->data = '';
      $result->response = NULL;
    }
    else {
      $response = json_decode($result->data);
      if (is_object($response)) {
        foreach ($response as $key => $value) {
          $result->$key = $value;
        }
      }
    }
    return $result;
  }


  /**
   * Escape a value for special query characters such as ':', '(', ')', '*', '?', etc.
   *
   * NOTE: inside a phrase fewer characters need escaped, use {@link DrupalApacheSolrService::escapePhrase()} instead
   *
   * @param string $value
   * @return string
   */
  static public function escape($value)
  {
    //list taken from http://lucene.apache.org/java/docs/queryparsersyntax.html#Escaping%20Special%20Characters
    $pattern = '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\*|\?|:|\\\)/';
    $replace = '\\\$1';

    return preg_replace($pattern, $replace, $value);
  }

  /**
   * Escape a value meant to be contained in a phrase for special query characters
   *
   * @param string $value
   * @return string
   */
  static public function escapePhrase($value)
  {
    $pattern = '/("|\\\)/';
    $replace = '\\\$1';

    return preg_replace($pattern, $replace, $value);
  }

  /**
   * Convenience function for creating phrase syntax from a value
   *
   * @param string $value
   * @return string
   */
  static public function phrase($value)
  {
    return '"' . self::escapePhrase($value) . '"';
  }

  /**
   * Return a valid http URL given this server's host, port and path and a provided servlet name
   *
   * @param $servlet
   *  A string path to a Solr request handler.
   * @param $params
   * @param $parsed_url
   *   A url to use instead of the stored one.
   *
   * @return string
   */
  protected function _constructUrl($servlet, $params = array(), $added_query_string = NULL) {
    // PHP's built in http_build_query() doesn't give us the format Solr wants.
    $query_string = $this->httpBuildQuery($params);

    if ($query_string) {
      $query_string = '?' . $query_string;
      if ($added_query_string) {
        $query_string = $query_string . '&' . $added_query_string;
      }
    }
    elseif ($added_query_string) {
      $query_string = '?' . $added_query_string;
    }

    $url = $this->parsed_url;
    return $url['scheme'] . $url['user'] . $url['pass'] . $url['host'] . $url['port'] . $url['path'] . $servlet . $query_string;
  }

  /**
   * Get the Solr url
   *
   * @return string
   */
  public function getUrl() {
    return $this->_constructUrl('');
  }

  /**
   * Set the Solr url.
   *
   * @param $url
   *
   * @return $this
   */
  public function setUrl($url) {
    $parsed_url = parse_url($url);

    if (!isset($parsed_url['scheme'])) {
      $parsed_url['scheme'] = 'http';
    }
    $parsed_url['scheme'] .= '://';

    if (!isset($parsed_url['user'])) {
      $parsed_url['user'] = '';
    }
    $parsed_url['pass'] = isset($parsed_url['pass']) ? ':' . $parsed_url['pass'] : '';
    $parsed_url['port'] = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';

    if (isset($parsed_url['path'])) {
      // Make sure the path has a single leading/trailing slash.
      $parsed_url['path'] = '/' . ltrim($parsed_url['path'], '/');
      $parsed_url['path'] = rtrim($parsed_url['path'], '/') . '/';
    }
    else {
      $parsed_url['path'] = '/';
    }
    // For now we ignore query and fragment.
    $this->parsed_url = $parsed_url;
    // Force the update url to be rebuilt.
    unset($this->update_url);
    return $this;
  }

  /**
   * Raw update Method. Takes a raw post body and sends it to the update service. Post body
   * should be a complete and well formed xml document.
   *
   * @param string $rawPost
   * @param float $timeout Maximum expected duration (in seconds)
   *
   * @return response object
   *
   * @throws Exception If an error occurs during the service call
   */
  public function update($rawPost, $timeout = FALSE) {
    // @todo: throw exception if updates are disabled.
    if (empty($this->update_url)) {
      // Store the URL in an instance variable since many updates may be sent
      // via a single instance of this class.
      $this->update_url = $this->_constructUrl(self::UPDATE_SERVLET, array('wt' => 'json'));
    }
    $options['data'] = $rawPost;
    if ($timeout) {
      $options['timeout'] = $timeout;
    }
    return $this->_sendRawPost($this->update_url, $options);
  }

  /**
   * Add an array of Solr Documents to the index all at once
   *
   * @param array $documents Should be an array of ApacheSolrDocument instances
   * @param boolean $allowDups
   * @param boolean $overwritePending
   * @param boolean $overwriteCommitted
   *
   * @return response objecte
   *
   * @throws Exception If an error occurs during the service call
   */
  public function addDocuments($documents, $overwrite = NULL, $commitWithin = NULL) {
    $attr = '';

    if (isset($overwrite)) {
      $attr .= ' overwrite="' . empty($overwrite) ? 'false"' : 'true"';
    }
    if (isset($commitWithin)) {
      $attr .= ' commitWithin="' . intval($commitWithin) . '"';
    }

    $rawPost = "<add{$attr}>";
    foreach ($documents as $document) {
      if (is_object($document) && ($document instanceof ApacheSolrDocument)) {
        $rawPost .= ApacheSolrDocument::documentToXml($document);
      }
    }
    $rawPost .= '</add>';

    return $this->update($rawPost);
  }

  /**
   * Send a commit command.  Will be synchronous unless both wait parameters are set to false.
   *
   * @param boolean $optimize Defaults to true
   * @param boolean $waitFlush Defaults to true
   * @param boolean $waitSearcher Defaults to true
   * @param float $timeout Maximum expected duration (in seconds) of the commit operation on the server (otherwise, will throw a communication exception). Defaults to 1 hour
   *
   * @return response object
   *
   * @throws Exception If an error occurs during the service call
   */
  public function commit($optimize = true, $waitFlush = true, $waitSearcher = true, $timeout = 3600) {
    $optimizeValue = $optimize ? 'true' : 'false';
    $flushValue = $waitFlush ? 'true' : 'false';
    $searcherValue = $waitSearcher ? 'true' : 'false';

    $rawPost = '<commit optimize="' . $optimizeValue . '" waitFlush="' . $flushValue . '" waitSearcher="' . $searcherValue . '" />';

    $response = $this->update($rawPost, $timeout);
    $this->_clearCache();
    return $response;
  }

  /**
   * Create a delete document based on document ID
   *
   * @param string $id Expected to be utf-8 encoded
   * @param float $timeout Maximum expected duration of the delete operation on the server (otherwise, will throw a communication exception)
   *
   * @return response object
   *
   * @throws Exception If an error occurs during the service call
   */
  public function deleteById($id, $timeout = 3600) {
    return $this->deleteByMultipleIds(array($id), $timeout);
  }

  /**
   * Create and post a delete document based on multiple document IDs.
   *
   * @param array $ids Expected to be utf-8 encoded strings
   * @param float $timeout Maximum expected duration of the delete operation on the server (otherwise, will throw a communication exception)
   *
   * @return response object
   *
   * @throws Exception If an error occurs during the service call
   */
  public function deleteByMultipleIds($ids, $timeout = 3600) {
    $rawPost = '<delete>';

    foreach ($ids as $id) {
      $rawPost .= '<id>' . htmlspecialchars($id, ENT_NOQUOTES, 'UTF-8') . '</id>';
    }
    $rawPost .= '</delete>';

    return $this->update($rawPost, $timeout);
  }

  /**
   * Create a delete document based on a query and submit it
   *
   * @param string $rawQuery Expected to be utf-8 encoded
   * @param float $timeout Maximum expected duration of the delete operation on the server (otherwise, will throw a communication exception)
   * @return stdClass response object
   *
   * @throws Exception If an error occurs during the service call
   */
  public function deleteByQuery($rawQuery, $timeout = 3600) {
    $rawPost = '<delete><query>' . htmlspecialchars($rawQuery, ENT_NOQUOTES, 'UTF-8') . '</query></delete>';

    return $this->update($rawPost, $timeout);
  }

  /**
   * Send an optimize command.  Will be synchronous unless both wait parameters are set
   * to false.
   *
   * @param boolean $waitFlush
   * @param boolean $waitSearcher
   * @param float $timeout Maximum expected duration of the commit operation on the server (otherwise, will throw a communication exception)
   *
   * @return response object
   *
   * @throws Exception If an error occurs during the service call
   */
  public function optimize($waitFlush = true, $waitSearcher = true, $timeout = 3600) {
    $flushValue = $waitFlush ? 'true' : 'false';
    $searcherValue = $waitSearcher ? 'true' : 'false';

    $rawPost = '<optimize waitFlush="' . $flushValue . '" waitSearcher="' . $searcherValue . '" />';

    return $this->update($rawPost, $timeout);
  }

  /**
   * Like PHP's built in http_build_query(), but uses rawurlencode() and no [] for repeated params.
   */
  public function httpBuildQuery(array $query, $parent = '') {
    $params = array();

    foreach ($query as $key => $value) {
      $key = ($parent ? $parent : rawurlencode($key));

      // Recurse into children.
      if (is_array($value)) {
        $params[] = $this->httpBuildQuery($value, $key);
      }
      // If a query parameter value is NULL, only append its key.
      elseif (!isset($value)) {
        $params[] = $key;
      }
      else {
        $params[] = $key . '=' . rawurlencode($value);
      }
    }

    return implode('&', $params);
  }

  /**
   * Simple Search interface
   *
   * @param string $query The raw query string
   * @param array $params key / value pairs for other query parameters (see Solr documentation), use arrays for parameter keys used more than once (e.g. facet.field)
   *
   * @return response object
   *
   * @throws Exception If an error occurs during the service call
   */
  public function search($query = '', $params = array(), $method = 'GET') {
    if (!is_array($params)) {
      $params = array();
    }
    // Always use JSON. See http://code.google.com/p/solr-php-client/issues/detail?id=6#c1 for reasoning
    $params['wt'] = 'json';
    // Additional default params.
    $params += array(
      'json.nl' => self::NAMED_LIST_FORMAT,
    );
    if ($query) {
      $params['q'] = $query;
    }
    // PHP's built in http_build_query() doesn't give us the format Solr wants.
    $queryString = $this->httpBuildQuery($params);
    // Check string length of the query string, change method to POST
    // if longer than 4000 characters (typical server handles 4096 max).
    // @todo - make this a per-server setting.
    if (strlen($queryString) > variable_get('apachesolr_search_post_threshold', 4000)) {
      $method = 'POST';
    }

    if ($method == 'GET') {
      $searchUrl = $this->_constructUrl(self::SEARCH_SERVLET, array(), $queryString);
      return $this->_sendRawGet($searchUrl);
    }
    else if ($method == 'POST') {
      $searchUrl = $this->_constructUrl(self::SEARCH_SERVLET);
      $options['data'] = $queryString;
      $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
      return $this->_sendRawPost($searchUrl, $options);
    }
    else {
      throw new Exception("Unsupported method '$method' for search(), use GET or POST");
    }
  }
}
