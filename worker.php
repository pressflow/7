<?php

/**
 * @file
 * Long-running process to handle xRender jobs.
 */

/**
 * Root directory of Drupal installation.
 */
define('DRUPAL_ROOT', getcwd());

if (!isset($_SERVER['REMOTE_ADDR'])) {
  $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

include_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

// @todo Only allow running under the CLI.

echo '[xRender] Core system bootstrapped.' . PHP_EOL;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
echo '[xRender] Connected to Redis.' . PHP_EOL;

$list_in = 'xrender-in-' . sha1(variable_get('drupal_private_key'));
echo '[xRender] Using list: ' . $list_in . PHP_EOL;

// @todo Base max handled requests on memory consumption.
$handled = 0;

echo '[xRender] Memory usage: ' . round(memory_get_usage() / 1024 / 1024) . 'M' . PHP_EOL;
echo '[xRender] Ready for jobs.' . PHP_EOL;

while ($handled < variable_get('xrender_worker_max_requests', 1000) && $message = $redis->blPop($list_in, 0)) {
  $job = $message[1];
  echo '[xRender] Job received:' . PHP_EOL;
  print_r($job);
  echo PHP_EOL;

  $job_data = $job->getData();

  // Re-establish the environment.
  $GLOBALS['user'] = $job_data['environment']['user'];
  $_GET['q'] = $job_data['environment']['path'];
  $_SESSION = $job_data['environment']['session'];

  $response = call_user_func_array($job_data['function'], $job_data['arguments']);

  echo '[xRender] Job finished:' . PHP_EOL;
  echo $response;
  echo PHP_EOL;

  $redis->lPush('xrender-out-' . $job_data['id'], $response . "\n");

  echo '[xRender] Memory usage: ' . round(memory_get_usage() / 1024 / 1024) . 'M' . PHP_EOL;

  ++$handled;
}
