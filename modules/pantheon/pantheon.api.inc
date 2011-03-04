<?php
// $Id$
/**
 * @file pantheon.api.inc
 * API functions for Pantheon
 */

define('YGG_API_PORT', 8443);
define('YGG_API', 'https://api.getpantheon.com');
define('HELIOS_CA_CERT', '/usr/share/ca-certificates/pantheon.crt');
define('PANTHEON_SYSTEM_CERT', '/etc/pantheon/system.pem');

/**
 * External API function to put config data in the Ygg API
 *
 * @params
 *
 * $site_uuid the site to hit
 *
 * Returns: an object of configuration
 */
function pantheon_ygg_config_get($site_uuid = 'self', $reset = FALSE) {
  static $config = array();
  if (!$config[$site_uuid] && !$reset) {
    $url = YGG_API ."/sites/$site_uuid/configuration";
    $result = pantheon_curl($url, NULL, YGG_API_PORT);
    
    // TODO: error checking?
    $config[$site_uuid] = json_decode($result['body']);
  }
  
   return $config[$site_uuid];
}


/**
 * External API function to put config data in the Ygg API
 *
 * @params
 *
 * $site_uuid the site to hit
 *
 * $path the REST url behind /sites/<site-uuid> to query
 * 
 * $data an array or object that will be JSONified and PUT
 * 
 */
function pantheon_ygg_config_set($site_uuid = 'self', $data) {
  $url = YGG_API ."/sites/$site_uuid/configuration";
  $json = json_encode($data);
  $result = pantheon_curl($url, $json, YGG_API_PORT, 'PUT');
  
  return $result;
}

/**
 * Post events to Ygg api.
 */
function pantheon_ygg_event_post($site_uuid = 'self', $data) {
  $url = YGG_API ."/sites/$site_uuid/events/";
  $json = json_encode($data);
  $result = pantheon_curl($url, $json, YGG_API_PORT, 'POST');
  
  return $result;
}

/**
 * Ygg API call to pull the services status.
 */
function pantheon_ygg_services_get($site_uuid = 'self', $reset = FALSE) {
  static $services = array();
  if (!isset($services[$site_uuid]) && !$reset) {
    $url = YGG_API ."/sites/$site_uuid/services";
    $result = pantheon_curl($url, NULL, YGG_API_PORT);
    
    // TODO: error checking?
    $services[$site_uuid] = json_decode($result['body']);
  }
  
   return $services[$site_uuid];
}


/**
 * Helper function for running CURLs
 */
function pantheon_curl($url, $data = NULL, $port = 443, $datamethod = 'POST') {
  // create a new cURL resource
  $ch = curl_init();
  
  // set URL and other appropriate options
  $opts = array(
    CURLOPT_URL => $url,
    CURLOPT_HEADER => 1,
    CURLOPT_PORT => $port,
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_SSLCERT => PANTHEON_SYSTEM_CERT,
    CURLOPT_CAINFO => HELIOS_CA_CERT,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
  );
  curl_setopt_array($ch, $opts);
  
  // If we are posting data...
  if ($data) {
    if ($datamethod == 'POST') {
      curl_setopt($ch, CURLOPT_POST, 1);
    }
    else {
      // This is sorta janky, but I want to re-use most of this function
      // As per: 
      // http://www.lornajane.net/posts/2009/PUTting-data-fields-with-PHP-cURL
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  }

  
  // grab URL and pass it to the browser
  $result = curl_exec($ch);
  
  if (curl_errno($ch) != 0) {
    $error = curl_error($ch);
    curl_close($ch);
    watchdog('pantheon', 'Fatal error contacting API: !error', array('!error' => $error), WATCHDOG_EMERGENCY);
    return FALSE;
  }

  list($headers, $body) = explode("\r\n\r\n", $result);
  
  $return = array(
    'result' => $result,
    'headers' => $headers,
    'body' => $body,
    'opts' => $opts,
    'data' => print_r($data, 1),
  );

  // close cURL resource, and free up system resources
  curl_close($ch);
  
  return $return;
}

/**
 * Helper function to get uuid for "self" from the cert.
 */
function pantheon_get_self_uuid() {
  $cert = file_get_contents(PANTHEON_SYSTEM_CERT);
  $ssl = openssl_x509_parse($cert);
  $uuid = array_shift(explode('.', $ssl['subject']['CN']));
  
  return $uuid;
}
