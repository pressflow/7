<?php

/**
 * @file
 * Select builder for MySQL database engine.
 */

/**
 * @addtogroup database
 * @{
 */

class SelectQuery_mysql extends SelectQuery {

  /**
   * Container for MySQL specific SELECT keywords.
   */
  protected $select_keywords = array();

  /**
   * Straight Join against another table in the database.
   *
   * @param $table
   *   The table against which to join.
   * @param $alias
   *   The alias for the table. In most cases this should be the first letter
   *   of the table, or the first letter of each "word" in the table.
   * @param $condition
   *   The condition on which to join this table. If the join requires values,
   *   this clause should use a named placeholder and the value or values to
   *   insert should be passed in the 4th parameter. For the first table joined
   *   on a query, this value is ignored as the first table is taken as the base
   *   table. The token %alias can be used in this string to be replaced with
   *   the actual alias. This is useful when $alias is modified by the database
   *   system, for example, when joining the same table more than once.
   * @param $arguments
   *   An array of arguments to replace into the $condition of this join.
   *
   * @return
   *   The unique alias that was assigned for this table.
   */
  public function straightJoin($table, $alias = NULL, $condition = NULL, $arguments = array()) {
    return $this->addJoin('STRAIGHT', $table, $alias, $condition, $arguments);
  }

  /**
   * Sets a SELECT keyword for the query.
   */
  public function setSelectKeyword($keyword_name, $keyword_status = TRUE) {
    $this->select_keywords[$keyword_name] = $keyword_status;
    return $this;
  }

  /**
   * Overrides SelectQuery::__toString().
   *
   * Handles MySQL specific keywords and STRAIGHT_JOIN.
   */
  public function __toString() {
    // For convenience, we compile the query ourselves if the caller forgot
    // to do it. This allows constructs like "(string) $query" to work. When
    // the query will be executed, it will be recompiled using the proper
    // placeholder generator anyway.
    if (!$this->compiled()) {
      $this->compile($this->connection, $this);
    }

    // Create a sanitized comment string to prepend to the query.
    $comments = $this->connection->makeComment($this->comments);

    // SELECT
    $query = $comments . 'SELECT ';
    if ($this->select_keywords && ($select_keywords = array_filter($this->select_keywords))) {
      $query .= implode(' ', array_keys($select_keywords)) . ' ';
    }
    if ($this->distinct) {
      $query .= 'DISTINCT ';
    }

    // FIELDS and EXPRESSIONS
    $fields = array();
    foreach ($this->tables as $alias => $table) {
      if (!empty($table['all_fields'])) {
        $fields[] = $this->connection->escapeTable($alias) . '.*';
      }
    }
    foreach ($this->fields as $alias => $field) {
      // Always use the AS keyword for field aliases, as some
      // databases require it (e.g., PostgreSQL).
      $fields[] = (isset($field['table']) ? $this->connection->escapeTable($field['table']) . '.' : '') . $this->connection->escapeField($field['field']) . ' AS ' . $this->connection->escapeAlias($field['alias']);
    }
    foreach ($this->expressions as $alias => $expression) {
      $fields[] = $expression['expression'] . ' AS ' . $this->connection->escapeAlias($expression['alias']);
    }
    $query .= implode(', ', $fields);

    // FROM - We presume all queries have a FROM, as any query that doesn't
    // won't need the query builder anyway.
    $query .= "\nFROM ";
    foreach ($this->tables as $alias => $table) {
      $query .= "\n";
      if (isset($table['join type'])) {
        $query .= $table['join type'] == 'STRAIGHT' ? ' STRAIGHT_JOIN ' : $table['join type'] . ' JOIN ';
      }

      // If the table is a subquery, compile it and integrate it into this query.
      if ($table['table'] instanceof SelectQueryInterface) {
        // Run preparation steps on this sub-query before converting to string.
        $subquery = $table['table'];
        $subquery->preExecute();
        $table_string = '(' . (string) $subquery . ')';
      }
      else {
        $table_string = '{' . $this->connection->escapeTable($table['table']) . '}';
      }

      // Don't use the AS keyword for table aliases, as some databases don't
      // support it (e.g., Oracle).
      $query .=  $table_string . ' ' . $this->connection->escapeTable($table['alias']);

      if (!empty($table['condition'])) {
        $query .= ' ON ' . $table['condition'];
      }
    }

    // WHERE
    if (count($this->where)) {
      // There is an implicit string cast on $this->condition.
      $query .= "\nWHERE " . $this->where;
    }

    // GROUP BY
    if ($this->group) {
      $query .= "\nGROUP BY " . implode(', ', $this->group);
    }

    // HAVING
    if (count($this->having)) {
      // There is an implicit string cast on $this->having.
      $query .= "\nHAVING " . $this->having;
    }

    // ORDER BY
    if ($this->order) {
      $query .= "\nORDER BY ";
      $fields = array();
      foreach ($this->order as $field => $direction) {
        $fields[] = $field . ' ' . $direction;
      }
      $query .= implode(', ', $fields);
    }

    // RANGE
    // There is no universal SQL standard for handling range or limit clauses.
    // Fortunately, all core-supported databases use the same range syntax.
    // Databases that need a different syntax can override this method and
    // do whatever alternate logic they need to.
    if (!empty($this->range)) {
      $query .= "\nLIMIT " . (int) $this->range['length'] . " OFFSET " . (int) $this->range['start'];
    }

    // UNION is a little odd, as the select queries to combine are passed into
    // this query, but syntactically they all end up on the same level.
    if ($this->union) {
      foreach ($this->union as $union) {
        $query .= ' ' . $union['type'] . ' ' . (string) $union['query'];
      }
    }

    if ($this->forUpdate) {
      $query .= ' FOR UPDATE';
    }

    return $query;
  }
}

/**
 * @} End of "addtogroup database".
 */
