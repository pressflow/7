<?php

/**
 * Return a Redis connection object.
 */
function _xrender_get_redis() {
  static $redis;
  if (!isset($redis)) {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
  }
  return $redis;
}

/**
 * Kick off an asynchronous rendering job and return the SSI tag.
 */
function xrender() {
  global $user;

  $arguments = func_get_args();
  $function = $arguments[0];
  unset($arguments[0]);

  // Construct the xRender request.
  $request = array(
    'id' => uniqid(),
    'environment' => array(
      'user' => $user,
      'path' => $_GET['q'],
      'session' => isset($_SESSION) ? $_SESSION : array(),
    ),
    'function' => $function,
    'arguments' => $arguments,
  );

  // Send the xRender request.
  $redis = _xrender_get_redis();
  $redis->lPush('xrender-in-' . sha1(variable_get('drupal_private_key')), serialize($request) . "\n");

  // Sets the content source to Webdis.
  $ssi_content_path = '/BLPOP/xrender-out-' . $request['id'] . '/10?type=text/html';

  // Return the appropriate ESI tag (disabled for now).
  //$tag = '<esi:include src="' . $content_src . '" />';

  // Return the appropriate SSI tag.
  $tag = '<!--# include virtual="' . $ssi_content_path . '" -->';

  return $tag;
}
