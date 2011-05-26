<?php
function pantheon_install_tasks($install_state) {
  $tasks = array (
    'pantheon_configure' => array(),
    'pantheon_phone_home' => array(),
  );
  return $tasks;
}

// Most of the configuration was moved to pantheon.module

function pantheon_phone_home() {
  pantheon_ygg_event_post('self', array('Drupal' => 'New Site Installed!'));
}

/**
 * Set up base config
 */
function pantheon_configure() {
  $config = pantheon_ygg_config_get();
  $solr_server = array(
    'server_id' => '',
    'name' => '',
    'scheme' => 'http',
    'host' => '',
    'port' => '',
    'path' => '',
   );

  // Remove existing apachesolr servers
  db_delete('apachesolr_server')->execute();

  // Create solr server entries for each environment.
  foreach ($config as $project => $data) {
    foreach ($data->environments as $env_name => $env) {
      $solr_server['server_id'] = $env->solr->apachesolr_default_server;
      $solr_server['name'] = ucwords("$project $env_name");
      $solr_server['path'] = $env->solr->solr_path;
      $solr_server['host'] = $env->solr->solr_host;
      $solr_server['port'] = $env->solr->solr_port;
      db_insert('apachesolr_server')->fields($solr_server)->execute();
    }
  }

  // Set default Pantheon variables
  variable_set('cache', 1);
  variable_set('block_cache', 1);
  variable_set('cache_lifetime', '0');
  variable_set('page_cache_maximum_age', '900');
  variable_set('page_compression', 0);
  variable_set('preprocess_css', 1);
  variable_set('preprocess_js', 1);
  $search_active_modules = array(
    'apachesolr_search' => 'apachesolr_search',
    'user' => 'user',
    'node' => 0
  );
  variable_set('search_active_modules', $search_active_modules);
  variable_set('search_default_module', 'apachesolr_search');
  drupal_set_message(t('Pantheon defaults configured.'));
}


// DUPLICATE FUNCTIONS FROM PANTHEON.MODULE
//
// It sure would be great to include pantheon.api.inc here...

define('YGG_API_PORT', 8443);
define('YGG_API', 'https://api.getpantheon.com');
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
  if (!isset($config[$site_uuid]) && !$reset) {
    $url = YGG_API ."/sites/$site_uuid/configuration";
    $result = pantheon_curl($url, NULL, YGG_API_PORT);
    
    // TODO: error checking?
    $config[$site_uuid] = json_decode($result['body']);
  }
  
   return $config[$site_uuid];
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
    drupal_set_message(t('Fatal error contacting API: !error', array('!error' => $error)), 'error');
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

