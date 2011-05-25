<?php

/**
 * Return a Redis connection object.
 */
function _xrender_get_redis() {
  static $redis;
  if (!isset($redis)) {
    $redis = new Redis('127.0.0.1', 6379);
  }
  return $redis;
}

/**
 * Kick off an asynchronous rendering job and return the SSI tag.
 */
function xrender() {
  global $user;

  $arguments = func_get_args();
  $function = $args[0];
  unset($arguments[0]);

  // Construct the xRender request.
  $request = array(
    'id' => uniqid(),
    'environment' => array(
      'user' => $user,
      'path' => $_GET['q'],
      'session' => $_SESSION,
    ),
    'function' => $function,
    'arguments' => $arguments,
  );

  // Send the xRender request.
  $redis = _xrender_get_redis();
  $redis->lPush('xrender-in-' . sha1(variable_get('drupal_private_key')), serialize($request) . "\n");

  // Sets the content source to Webdis.
  $content_src = 'http://localhost:7379/BLPOP/' . $request['id'] . '?type=text/html';

  // Return the appropriate ESI tag (disabled for now).
  //$tag = '<esi:include src="' . $content_src . '" />';

  // Return the appropriate SSI tag.
  $tag = '<!--# include virtual="' . $content_src . '" -->';

  return $tag;
}
