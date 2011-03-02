<?php
function pantheon_install_tasks($install_state) {
  $tasks = array ('configure_pantheon' => array());
  return $tasks;
}

function configure_pantheon() {
	setup_apachesolr();
}

function setup_apachesolr() {
  $solr_server = array(
    'server_id' => '',
    'name' => '',
    'scheme' => 'http',
    'host' => 'localhost',
    'port' => '8983',
    'path' => '',
   );

  // Remove existing apachesolr servers
  db_delete('apachesolr_server')->execute();

  // Create solr server entries for each environment.
  foreach (array('dev','test','live') as $env) {
    $solr_server['server_id'] = 'pantheon_' . $env;
    $solr_server['name'] = 'Pantheon ' . $env;
    $solr_server['path'] = '/pantheon_' . $env;
    db_insert('apachesolr_server')->fields($solr_server)->execute();
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
}

