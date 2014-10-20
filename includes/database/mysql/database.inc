<?php

/**
 * @file
 * Database interface code for MySQL database servers.
 */

/**
 * Return a mysqli object that is ready to be used.
 *
 * @param bool $create_new_connection
 *   If FALSE, it will not create a new connection (cache_get).
 * @param string $table
 *   Database table name.
 * @param array $cids
 *   An array of cache IDs.
 *
 * @return mysqli
 *   returns a mysqli object on success or FALSE on failure.
 */
function drupal_mysqli_get_object($create_new_connection = TRUE, $table = '', array $cids = array()) {
  // Bail out if not mysql that is async capable.
  $mysqli = FALSE;
  if (   !function_exists('mysqli_init')
      || !defined('MYSQLI_ASYNC')
      || Database::getConnection()->databaseType() !== 'mysql'
    ) {
    return $mysqli;
  }

  // Use the advanced drupal_static() pattern for $db_info.
  static $db_info;
  if (!isset($db_info)) {
    $db_info = &drupal_static(__FUNCTION__);

    // 'var' stores variables about the mysql database.
    if (!isset($db_info['var'])) {
      $db_info['var'] = db_query("SELECT @@global.max_connections AS max_connections, @@global.wait_timeout AS wait_timeout")->fetchAssoc();
      // Limit total DB connections to 90 or 80% of the max; whatever is smaller.
      $db_info['var']['max_connections'] = floor(min(90, $db_info['var']['max_connections'] * 0.8));
      // Set wait timeout to 90 seconds or the current value; whatever is smaller.
      $db_info['var']['wait_timeout'] = min(90, $db_info['var']['wait_timeout']);
      $db_info['var']['connect_timeout'] = 2;
      $db_info['var']['innodb_lock_wait_timeout'] = 2;
    }

    // 'connection' stores the info needed to make a new connection to mysql.
    if (!isset($db_info['connection'])) {
      // Use default connection info.
      $connection_info = Database::getConnectionInfo();
      $db_info['connection'] = reset($connection_info);
      if (empty($db_info['connection']['port'])) {
        $db_info['connection']['port'] = NULL;
      }
      else {
        $db_info['connection']['port'] = (int)$db_info['connection']['port'];
      }
      if (empty($db_info['connection']['unix_socket'])) {
        $db_info['connection']['unix_socket'] = NULL;
      }
      if (empty($db_info['connection']['password'])) {
        $db_info['connection']['password'] = NULL;
      }
    }

    // 'pool' stores a collection of open mysql connections.
    if (!isset($db_info['pool'])) {
      $db_info['pool'] = array();
    }
  }

  // Make sure a table/cid pair is used by the same connection in order to avoid
  // record level deadlocks.
  if (empty($create_new_connection) && !empty($db_info['pool']) && !empty($table) && !empty($cids)) {
    $match = FALSE;
    foreach ($db_info['pool'] as $values) {
      // Match the table.
      if ($table == $values[1]) {
        // Match the cache id.
        $intersect = array_intersect($cids, $values[2]);
        if (!empty($intersect)) {
          // Wait for the query to finish.
          @$values[0]->reap_async_query();
          $match = $values[0];
        }
      }
    }
    if (!empty($match)) {
      drupal_mysqli_ping($match, $db_info, $table, $cids);
      return $match;
    }
  }

  // Try to reuse an old connection.
  if (!empty($db_info['pool'])) {
    $mysqli_pool = array();
    foreach ($db_info['pool'] as $values) {
      $mysqli_pool[] = $values[0];
    }
    $links = $errors = $reject = $mysqli_pool;
    $ready = @mysqli_poll($links, $errors, $reject, 0, 1);
    if (!empty($reject)) {
      // A non async connection is ready; use the first one.
      $mysqli = reset($reject);
      drupal_mysqli_ping($mysqli, $db_info, $table, $cids);
      return $mysqli;
    }
    if (!empty($links)) {
      // An async connection is ready; use the first one.
      $mysqli = reset($links);
      @$mysqli->reap_async_query();
      drupal_mysqli_ping($mysqli, $db_info, $table, $cids);
      return $mysqli;
    }

    // All current connections are in use.
    if (count($db_info['pool']) < 6) {
      // Create a new DB connection.
      $mysqli = drupal_mysqli_new_connection($db_info);
      if (!empty($mysqli)) {
        $db_info['pool'][$mysqli->thread_id] = array($mysqli, $table, $cids);
      }
      return $mysqli;
    }
    else {
      // Wait for a db connection to be ready.
      $ready = FALSE;
      while (!$ready) {
        $mysqli_pool = array();
        foreach ($db_info['pool'] as $values) {
          $mysqli_pool[] = $values[0];
        }
        $links = $errors = $reject = $mysqli_pool;
        $ready = @mysqli_poll($links, $errors, $reject, 0, 5000);
        if (!$ready && !empty($reject)) {
          $ready = TRUE;
        }
      }
      if (!empty($reject)) {
        // A non async connection is ready; use the first one.
        $mysqli = reset($reject);
        drupal_mysqli_ping($mysqli, $db_info, $table, $cids);
        return $mysqli;
      }
      if (!empty($links)) {
        // An async connection is ready; use the first one.
        $mysqli = reset($links);
        @$mysqli->reap_async_query();
        drupal_mysqli_ping($mysqli, $db_info, $table, $cids);
        return $mysqli;
      }
    }
  }

  if (empty($db_info['pool']) && $create_new_connection) {
    $mysqli = drupal_mysqli_new_connection($db_info);
    if (!empty($mysqli)) {
      $db_info['pool'][$mysqli->thread_id] = array($mysqli, $table, $cids);
    }
  }
  return $mysqli;
}

/**
 * Create a new MySQLi connection.
 *
 * @param array $db_info
 *   static var from 'drupal_mysqli_get_object'.
 *
 * @return mysqli
 *   returns a mysqli object on success or FALSE on failure.
 */
function drupal_mysqli_new_connection(array $db_info) {
  // Get Threads_connected, max_connections, & wait_timeout from the DB.
  $db_info['var'] += db_query("SHOW STATUS WHERE Variable_name LIKE 'Threads_connected'")->fetchAllKeyed();
  $db_info['var'] = array_change_key_case($db_info['var'], CASE_LOWER);
  if ($db_info['var']['threads_connected'] >= $db_info['var']['max_connections']) {
    // Bail out if the DB has a lot of connections currently.
    return FALSE;
  }

  // Create new MySQL connection.
  $mysqli = new mysqli();
  $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, $db_info['var']['connect_timeout']);
  @$mysqli->real_connect($db_info['connection']['host'], $db_info['connection']['username'], $db_info['connection']['password'], $db_info['connection']['database'], $db_info['connection']['port'], $db_info['connection']['unix_socket']);

  if (empty($mysqli) || !empty($mysqli->connect_errno) || empty($mysqli->host_info)) {
    // Bail out if the DB didn't connect.
    return FALSE;
  }
  if (!empty($mysqli)) {
    // Get connection ready for usage.
    $mysqli->set_charset('utf8');
    if (!isset($db_info['connection']['init_commands'])) {
      $db_info['connection']['init_commands'] = array();
    }
    $db_info['connection']['init_commands'] += array(
      'sql_mode' => "SET sql_mode = 'ANSI,STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER'",
      'isolation' => "SET SESSION tx_isolation='READ-UNCOMMITTED'",
      'wait_timeout' => 'SET SESSION wait_timeout = ' . $db_info['var']['wait_timeout'],
      'innodb_lock' => 'SET SESSION innodb_lock_wait_timeout = ' . $db_info['var']['innodb_lock_wait_timeout'],
    );
    foreach ($db_info['connection']['init_commands'] as $query) {
      $good = $mysqli->query($query);
      if (empty($good)) {
        // Bail out if the these queries failed.
        return FALSE;
      }
    }
  }
  // Make sure all async queries finish before php is killed.
  // Using a nested register_shutdown_function makes sure this is executed last.
  register_shutdown_function('register_shutdown_function', 'drupal_mysqli_close', $mysqli);
  return $mysqli;
}

/**
 * Reconnect to the MySQL database if the connection has been lost.
 *
 * Will also record the table and cache ID used.
 *
 * @param mysqli $mysqli
 *   mysqlnd connection object. Passed by reference.
 * @param array $db_info
 *   static var from 'drupal_mysqli_get_object'. Passed by reference.
 * @param string $table
 *   table name.
 * @param array $cids
 *   An array of cache IDs.
 */
function drupal_mysqli_ping(mysqli &$mysqli, array &$db_info, $table, array $cids) {
  $timeout_check = max(1, $db_info['var']['wait_timeout'] - 5);
  $timer = ceil(timer_read('page') / 1000);
  if ($timer > $timeout_check) {
    if (empty($mysqli) || !@$mysqli->ping()) {
      unset($db_info['pool'][$mysqli->thread_id]);
      $mysqli = drupal_mysqli_new_connection($db_info);
      if (empty($mysqli) || !@$mysqli->ping()) {
        $mysqli = FALSE;
      }
    }
  }
  if (!empty($mysqli)) {
    $db_info['pool'][$mysqli->thread_id] = array($mysqli, $table, $cids);
  }
}

/**
 * Wait for the result from an async query and then unset the connection.
 *
 * @param mysqli $mysqli
 *   mysqlnd connection object. Passed by reference.
 */
function drupal_mysqli_close(mysqli &$mysqli) {
  @$mysqli->reap_async_query();
  unset($mysqli);
  $mysqli = FALSE;
}

/**
 * @addtogroup database
 * @{
 */

class DatabaseConnection_mysql extends DatabaseConnection {

  /**
   * Flag to indicate if the cleanup function in __destruct() should run.
   *
   * @var boolean
   */
  protected $needsCleanup = FALSE;

  public function __construct(array $connection_options = array()) {
    // This driver defaults to transaction support, except if explicitly passed FALSE.
    $this->transactionSupport = !isset($connection_options['transactions']) || ($connection_options['transactions'] !== FALSE);

    // MySQL never supports transactional DDL.
    $this->transactionalDDLSupport = FALSE;

    $this->connectionOptions = $connection_options;

    // The DSN should use either a socket or a host/port.
    if (isset($connection_options['unix_socket'])) {
      $dsn = 'mysql:unix_socket=' . $connection_options['unix_socket'];
    }
    else {
      // Default to TCP connection on port 3306.
      $dsn = 'mysql:host=' . $connection_options['host'] . ';port=' . (empty($connection_options['port']) ? 3306 : $connection_options['port']);
    }
    $dsn .= ';dbname=' . $connection_options['database'];
    // Allow PDO options to be overridden.
    $connection_options += array(
      'pdo' => array(),
    );
    $connection_options['pdo'] += array(
      // So we don't have to mess around with cursors and unbuffered queries by default.
      PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => TRUE,
      // Because MySQL's prepared statements skip the query cache, because it's dumb.
      PDO::ATTR_EMULATE_PREPARES => TRUE,
    );

    parent::__construct($dsn, $connection_options['username'], $connection_options['password'], $connection_options['pdo']);

    // Force MySQL to use the UTF-8 character set. Also set the collation, if a
    // certain one has been set; otherwise, MySQL defaults to 'utf8_general_ci'
    // for UTF-8.
    if (!empty($connection_options['collation'])) {
      $this->exec('SET NAMES utf8 COLLATE ' . $connection_options['collation']);
    }
    else {
      $this->exec('SET NAMES utf8');
    }

    // Set MySQL init_commands if not already defined.  Default Drupal's MySQL
    // behavior to conform more closely to SQL standards.  This allows Drupal
    // to run almost seamlessly on many different kinds of database systems.
    // These settings force MySQL to behave the same as postgresql, or sqlite
    // in regards to syntax interpretation and invalid data handling.  See
    // http://drupal.org/node/344575 for further discussion. Also, as MySQL 5.5
    // changed the meaning of TRADITIONAL we need to spell out the modes one by
    // one.
    $connection_options += array(
      'init_commands' => array(),
    );
    $connection_options['init_commands'] += array(
      'sql_mode' => "SET sql_mode = 'ANSI,STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER'",
    );
    // Set connection options.
    $this->exec(implode('; ', $connection_options['init_commands']));
  }

  public function __destruct() {
    if ($this->needsCleanup) {
      $this->nextIdDelete();
    }
  }

  public function queryRange($query, $from, $count, array $args = array(), array $options = array()) {
    return $this->query($query . ' LIMIT ' . (int) $from . ', ' . (int) $count, $args, $options);
  }

  public function queryTemporary($query, array $args = array(), array $options = array()) {
    $tablename = $this->generateTemporaryTableName();
    $this->query('CREATE TEMPORARY TABLE {' . $tablename . '} Engine=MEMORY ' . $query, $args, $options);
    return $tablename;
  }

  public function driver() {
    return 'mysql';
  }

  public function databaseType() {
    return 'mysql';
  }

  public function mapConditionOperator($operator) {
    // We don't want to override any of the defaults.
    return NULL;
  }

  public function nextId($existing_id = 0) {
    $new_id = $this->query('INSERT INTO {sequences} () VALUES ()', array(), array('return' => Database::RETURN_INSERT_ID));
    // This should only happen after an import or similar event.
    if ($existing_id >= $new_id) {
      // If we INSERT a value manually into the sequences table, on the next
      // INSERT, MySQL will generate a larger value. However, there is no way
      // of knowing whether this value already exists in the table. MySQL
      // provides an INSERT IGNORE which would work, but that can mask problems
      // other than duplicate keys. Instead, we use INSERT ... ON DUPLICATE KEY
      // UPDATE in such a way that the UPDATE does not do anything. This way,
      // duplicate keys do not generate errors but everything else does.
      $this->query('INSERT INTO {sequences} (value) VALUES (:value) ON DUPLICATE KEY UPDATE value = value', array(':value' => $existing_id));
      $new_id = $this->query('INSERT INTO {sequences} () VALUES ()', array(), array('return' => Database::RETURN_INSERT_ID));
    }
    $this->needsCleanup = TRUE;
    return $new_id;
  }

  public function nextIdDelete() {
    // While we want to clean up the table to keep it up from occupying too
    // much storage and memory, we must keep the highest value in the table
    // because InnoDB  uses an in-memory auto-increment counter as long as the
    // server runs. When the server is stopped and restarted, InnoDB
    // reinitializes the counter for each table for the first INSERT to the
    // table based solely on values from the table so deleting all values would
    // be a problem in this case. Also, TRUNCATE resets the auto increment
    // counter.
    try {
      $max_id = $this->query('SELECT MAX(value) FROM {sequences}')->fetchField();
      // We know we are using MySQL here, no need for the slower db_delete().
      $this->query('DELETE FROM {sequences} WHERE value < :value', array(':value' => $max_id));
    }
    // During testing, this function is called from shutdown with the
    // simpletest prefix stored in $this->connection, and those tables are gone
    // by the time shutdown is called so we need to ignore the database
    // errors. There is no problem with completely ignoring errors here: if
    // these queries fail, the sequence will work just fine, just use a bit
    // more database storage and memory.
    catch (PDOException $e) {
    }
  }

  /**
   * Overridden to work around issues to MySQL not supporting transactional DDL.
   */
  protected function popCommittableTransactions() {
    // Commit all the committable layers.
    foreach (array_reverse($this->transactionLayers) as $name => $active) {
      // Stop once we found an active transaction.
      if ($active) {
        break;
      }

      // If there are no more layers left then we should commit.
      unset($this->transactionLayers[$name]);
      if (empty($this->transactionLayers)) {
        if (!PDO::commit()) {
          throw new DatabaseTransactionCommitFailedException();
        }
      }
      else {
        // Attempt to release this savepoint in the standard way.
        try {
          $this->query('RELEASE SAVEPOINT ' . $name);
        }
        catch (PDOException $e) {
          // However, in MySQL (InnoDB), savepoints are automatically committed
          // when tables are altered or created (DDL transactions are not
          // supported). This can cause exceptions due to trying to release
          // savepoints which no longer exist.
          //
          // To avoid exceptions when no actual error has occurred, we silently
          // succeed for MySQL error code 1305 ("SAVEPOINT does not exist").
          if ($e->errorInfo[1] == '1305') {
            // If one SAVEPOINT was released automatically, then all were.
            // Therefore, clean the transaction stack.
            $this->transactionLayers = array();
            // We also have to explain to PDO that the transaction stack has
            // been cleaned-up.
            PDO::commit();
          }
          else {
            throw $e;
          }
        }
      }
    }
  }
}


/**
 * @} End of "addtogroup database".
 */
