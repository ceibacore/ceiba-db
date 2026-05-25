<?php

/**
 * LemurDB
 *
 * Minimal database wrapper that exposes a PDO-backed LemurQuery
 * with full CRUD support: SELECT, INSERT, UPDATE, DELETE.
 *
 * v1.1.0 — Added:
 *   - transaction()  : Atomic callable wrapper with auto commit/rollback.
 *   - join()         : INNER JOIN support on LemurQuery.
 *   - leftJoin()     : LEFT JOIN support on LemurQuery.
 *   - rightJoin()    : RIGHT JOIN support on LemurQuery.
 *   - whereRaw()     : Raw WHERE condition with bound params.
 *   - toJoinSql()    : Debug helper — renders JOIN clauses.
 */
if (!class_exists('LemurDB')) {
class LemurDB
{
    /**
     * Singleton instance.
     *
     * @var LemurDB|null
     */
    private static $instance = null;

    /**
     * Active PDO connection.
     *
     * @var PDO
     */
    private $pdo;

    /**
     * Connection configuration.
     *
     * @var array
     */
    private $config;

    /**
     * Create a new LemurDB instance.
     *
     * @param array $config
     * @param string $config['driver']   PDO driver name (e.g., mysql, pgsql).
     * @param string $config['host']     Database host.
     * @param int|string $config['port'] Database port.
     * @param string $config['db']       Database name.
     * @param string $config['username'] Username.
     * @param string $config['password'] Password.
     * @param string $config['prefix']   Optional table prefix.
     */
    private function __construct(array $config)
    {
        $this->config = $config;
        $dsn = $this->buildDsn($config);

        try {
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException("Connection Error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the singleton instance (creates it on first call).
     *
     * @param array $config Connection configuration.
     * @return LemurDB
     */
    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Build a PDO DSN string from config.
     *
     * @param array $c Connection configuration.
     * @return string
     */
    private function buildDsn(array $c): string
    {
        if (isset($c['driver']) && $c['driver'] === 'sqlite') {
            return "sqlite:" . $c['db'];
        }
        return "{$c['driver']}:host={$c['host']};port={$c['port']};dbname={$c['db']};charset=utf8mb4";
    }

    /**
     * Start a query builder for a table.
     *
     * @param string $table Table name without prefix.
     * @return LemurQuery
     */
    public function query(string $table): LemurQuery
    {
        return new LemurQuery($this->pdo, $table, $this->getPrefix());
    }

    /**
     * Get the table prefix.
     *
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->config['prefix'] ?? '';
    }

    /**
     * Execute a callable inside an atomic database transaction.
     *
     * Automatically commits on success or rolls back on any Throwable.
     * The LemurDB instance is passed to the callable for convenience.
     *
     * @param callable $callback  Function to execute. Receives this LemurDB instance.
     * @return mixed              Value returned by the callable.
     * @throws \Throwable         Re-throws any exception after rolling back.
     *
     * @example
     * $db->transaction(function (LemurDB $db) {
     *     $db->query('orders')->insert([...]);
     *     $db->query('invoices')->insert([...]);
     * });
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Expose the underlying PDO connection.
     *
     * Intended for advanced use cases (e.g. manual transaction management,
     * raw queries) that are not covered by the query builder API.
     *
     * @return PDO
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
}


/**
 * LemurQuery
 *
 * Fluent query builder for SELECT, INSERT, UPDATE, and DELETE.
 */
if (!class_exists('LemurQuery')) {
class LemurQuery
{
    /**
     * Active PDO connection.
     *
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * Fully qualified table name (with prefix).
     *
     * @var string
     */
    protected string $table;

    /**
     * Query parts and bound parameters.
     *
     * @var array
     */
    protected array $clauses = [
        'select' => '*',
        'joins' => [],
        'where' => [],
        'params' => [],
        'order' => '',
        'limit' => '',
        'between' => [],
    ];

    /**
     * Create a new LemurQuery.
     *
     * @param PDO    $pdo    Active PDO connection.
     * @param string $table  Table name without prefix.
     * @param string $prefix Optional table prefix.
     */
    public function __construct(PDO $pdo, string $table, string $prefix = '')
    {
        $this->pdo = $pdo;
        $this->table = $this->quoteIdentifier($prefix . $table);
    }

    /**
     * Quote a SQL identifier (table or column name).
     *
     * @param string $identifier
     * @return string
     */
    protected function quoteIdentifier(string $identifier): string
    {
        // Handle table.column format
        if (strpos($identifier, '.') !== false) {
            return implode('.', array_map([$this, 'quoteIdentifier'], explode('.', $identifier)));
        }
        return "`" . str_replace("`", "``", $identifier) . "`";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // JOIN METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add an INNER JOIN clause.
     *
     * @param string $table  Table to join (without prefix).
     * @param string $on     Raw ON condition, e.g. "orders.user_id = users.id".
     * @return $this
     *
     * @example
     * $db->query('orders')
     *    ->join('users', 'orders.user_id = users.id')
     *    ->select(['orders.id', 'users.name'])
     *    ->get();
     */
    public function join(string $table, string $on): static
    {
        return $this->addJoin('INNER', $table, $on);
    }

    /**
     * Add a LEFT JOIN clause.
     *
     * @param string $table  Table to join (without prefix).
     * @param string $on     Raw ON condition.
     * @return $this
     */
    public function leftJoin(string $table, string $on): static
    {
        return $this->addJoin('LEFT', $table, $on);
    }

    /**
     * Add a RIGHT JOIN clause.
     *
     * @param string $table  Table to join (without prefix).
     * @param string $on     Raw ON condition.
     * @return $this
     */
    public function rightJoin(string $table, string $on): static
    {
        return $this->addJoin('RIGHT', $table, $on);
    }

    /**
     * Internal: registers a JOIN clause.
     *
     * @param string $type   JOIN type: INNER, LEFT, RIGHT.
     * @param string $table  Table name (without prefix).
     * @param string $on     ON condition.
     * @return $this
     */
    private function addJoin(string $type, string $table, string $on): static
    {
        $this->clauses['joins'][] = strtoupper($type) . " JOIN {$table} ON {$on}";
        return $this;
    }

    /**
     * Add an ORDER BY clause.
     *
     * @param string $column    Column to sort by.
     * @param string $direction "ASC" or "DESC".
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->clauses['order'] = " ORDER BY {$column} {$dir}";
        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SELECT METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Set select fields.
     *
     * @param string|array $fields "*" or a list of fields.
     * @return $this
     */
    public function select(string|array $fields = '*'): static
    {
        $this->clauses['select'] = is_array($fields)
            ? implode(', ', $fields)
            : $fields;
        return $this;
    }

    /**
     * Add equality WHERE conditions (AND).
     *
     * @param array $conditions Key/value pairs, e.g. ['status' => 1].
     * @return $this
     */
    public function where(array $conditions): static
    {
        foreach ($conditions as $column => $value) {
            $quoted = $this->quoteIdentifier($column);
            $this->addWhere("{$quoted} = ?", [$value], 'AND');
            $this->clauses['params'][] = $value;
        }
        return $this;
    }

    /**
     * Add a BETWEEN condition (AND).
     *
     * @param string $column Column name.
     * @param mixed  $start  Start value.
     * @param mixed  $end    End value.
     * @return $this
     */
    public function between(string $column, mixed $start, mixed $end): static
    {
        $this->addWhere("{$column} BETWEEN ? AND ?", [$start, $end], 'AND');
        $this->clauses['params'][] = $start;
        $this->clauses['params'][] = $end;
        return $this;
    }

    /**
     * Add equality WHERE conditions (OR).
     *
     * @param array $conditions Key/value pairs, e.g. ['status' => 1].
     * @return $this
     */
    public function orWhere(array $conditions): static
    {
        foreach ($conditions as $column => $value) {
            $this->addWhere("{$column} = ?", [$value], 'OR');
            $this->clauses['params'][] = $value;
        }
        return $this;
    }

    /**
     * Add a LIKE condition.
     *
     * @param string $column  Column name.
     * @param string $pattern LIKE pattern (e.g. "%usb%").
     * @param string $boolean "AND" or "OR".
     * @return $this
     */
    public function like(string $column, string $pattern, string $boolean = 'AND'): static
    {
        $this->addWhere("{$column} LIKE ?", [$pattern], $boolean);
        $this->clauses['params'][] = $pattern;
        return $this;
    }

    /**
     * Add a LIKE condition with OR.
     *
     * @param string $column  Column name.
     * @param string $pattern LIKE pattern (e.g. "%usb%").
     * @return $this
     */
    public function orLike(string $column, string $pattern): static
    {
        return $this->like($column, $pattern, 'OR');
    }

    /**
     * Add a raw WHERE condition with manually bound parameters.
     *
     * Use this for complex expressions that the fluent API cannot express,
     * such as IS NULL, IS NOT NULL, IN (...), or subqueries.
     *
     * @param string $sql     Raw SQL fragment, e.g. "status IS NOT NULL".
     * @param array  $params  Positional bound parameters for the fragment.
     * @param string $boolean "AND" or "OR".
     * @return $this
     *
     * @example
     * $db->query('orders')
     *    ->whereRaw('canceled_at IS NULL')
     *    ->whereRaw('amount > ?', [100])
     *    ->get();
     */
    public function whereRaw(string $sql, array $params = [], string $boolean = 'AND'): static
    {
        $this->addWhere($sql, $params, $boolean);
        foreach ($params as $p) {
            $this->clauses['params'][] = $p;
        }
        return $this;
    }

    /**
     * Add a BETWEEN condition with OR.
     *
     * @param string $column Column name.
     * @param mixed  $start  Start value.
     * @param mixed  $end    End value.
     * @return $this
     */
    public function orBetween(string $column, mixed $start, mixed $end): static
    {
        $this->addWhere("{$column} BETWEEN ? AND ?", [$start, $end], 'OR');
        $this->clauses['params'][] = $start;
        $this->clauses['params'][] = $end;
        return $this;
    }

    /**
     * Add LIMIT / OFFSET.
     *
     * @param int $limit  Max rows.
     * @param int $offset Offset (default 0).
     * @return $this
     */
    public function limit(int $limit, int $offset = 0): static
    {
        $this->clauses['limit'] = " LIMIT {$offset}, {$limit}";
        return $this;
    }

    /**
     * Execute the SELECT query and return all results.
     *
     * @return array
     */
    public function get(): array
    {
        $stmt = $this->pdo->prepare($this->toSql());
        $stmt->execute($this->clauses['params']);
        return $stmt->fetchAll();
    }

    /**
     * Execute the SELECT query and return a single row.
     *
     * @return array|null
     */
    public function first(): ?array
    {
        $this->limit(1);
        $stmt = $this->pdo->prepare($this->toSql());
        $stmt->execute($this->clauses['params']);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Check if any rows exist matching the current query.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->first() !== null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INSERT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Insert a single row into the table.
     *
     * @param array $data Associative array of column => value.
     * @return int Last inserted ID.
     *
     * @example
     * $db->query('users')->insert([
     *     'name'  => 'John',
     *     'email' => 'john@example.com',
     * ]);
     */
    public function insert(array $data): int
    {
        [$sql, $params] = $this->buildInsertSql([$data]);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert multiple rows in a single query.
     *
     * @param array $rows Array of associative arrays (same keys required).
     * @return int Number of rows inserted.
     *
     * @example
     * $db->query('users')->insertBatch([
     *     ['name' => 'Alice', 'email' => 'alice@example.com'],
     *     ['name' => 'Bob',   'email' => 'bob@example.com'],
     * ]);
     */
    public function insertBatch(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }
        [$sql, $params] = $this->buildInsertSql($rows);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Build INSERT SQL for one or more rows.
     *
     * @param array $rows
     * @return array [string $sql, array $params]
     */
    private function buildInsertSql(array $rows): array
    {
        $columns = array_keys($rows[0]);
        $columnList = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $valueSets = implode(', ', array_fill(0, count($rows), $placeholder));

        $sql = "INSERT INTO {$this->table} ({$columnList}) VALUES {$valueSets}";
        $params = array_merge(...array_map('array_values', $rows));

        return [$sql, $params];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update rows matching the current WHERE clauses.
     *
     * ⚠️  Requires at least one WHERE condition to prevent full-table updates.
     *      Call where() / orWhere() before update().
     *
     * @param array $data Associative array of column => value to update.
     * @return int Number of affected rows.
     *
     * @throws \RuntimeException if no WHERE clause is set.
     *
     * @example
     * $db->query('users')
     *    ->where(['id' => 5])
     *    ->update(['name' => 'Jane', 'status' => 'active']);
     */
    public function update(array $data): int
    {
        if (empty($this->clauses['where'])) {
            throw new \RuntimeException(
                "LemurQuery::update() requires at least one WHERE condition. " .
                "Use where() before calling update() to avoid full-table updates."
            );
        }

        $setClauses = array_map(
            fn($col) => $this->quoteIdentifier($col) . " = ?",
            array_keys($data)
        );

        $sql = "UPDATE {$this->table} SET " . implode(', ', $setClauses);
        $sql .= " WHERE " . $this->renderWhere();
        $params = array_merge(array_values($data), $this->clauses['params']);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Delete rows matching the current WHERE clauses.
     *
     * ⚠️  Requires at least one WHERE condition to prevent full-table deletes.
     *      Call where() / orWhere() before delete().
     *
     * @return int Number of affected rows.
     *
     * @throws \RuntimeException if no WHERE clause is set.
     *
     * @example
     * $db->query('users')
     *    ->where(['id' => 5])
     *    ->delete();
     */
    public function delete(): int
    {
        if (empty($this->clauses['where'])) {
            throw new \RuntimeException(
                "LemurQuery::delete() requires at least one WHERE condition. " .
                "Use where() before calling delete() to avoid full-table deletes."
            );
        }

        $sql = "DELETE FROM {$this->table} WHERE " . $this->renderWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->clauses['params']);
        return $stmt->rowCount();
    }

    /**
     * Truncate the entire table (removes ALL rows, resets AUTO_INCREMENT).
     *
     * ⚠️  This operation cannot be undone. No WHERE clause applies.
     *
     * @return bool True on success.
     *
     * @example
     * $db->query('cache')->truncate();
     */
    public function truncate(): bool
    {
        return (bool) $this->pdo->exec("TRUNCATE TABLE {$this->table}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DEBUG HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the SQL string for the current SELECT query.
     *
     * @return string
     */
    public function toSql(): string
    {
        $sql = "SELECT {$this->clauses['select']} FROM {$this->table}";

        if (!empty($this->clauses['joins'])) {
            $sql .= " " . implode(" ", $this->clauses['joins']);
        }

        if (!empty($this->clauses['where'])) {
            $sql .= " WHERE " . $this->renderWhere();
        }

        $sql .= $this->clauses['order'] . $this->clauses['limit'];
        return $sql;
    }

    /**
     * Return only the registered JOIN clauses as a SQL string (debug only).
     *
     * @return string
     */
    public function toJoinSql(): string
    {
        return implode(' ', $this->clauses['joins']);
    }

    /**
     * Build the SQL string for the current UPDATE query (debug only).
     *
     * @param array $data Column => value pairs to update.
     * @return string
     */
    public function toUpdateSql(array $data): string
    {
        $setClauses = array_map(fn($col) => "{$col} = ?", array_keys($data));
        $sql = "UPDATE {$this->table} SET " . implode(', ', $setClauses);

        if (!empty($this->clauses['where'])) {
            $sql .= " WHERE " . $this->renderWhere();
        }

        return $sql;
    }

    /**
     * Build the SQL string for the current DELETE query (debug only).
     *
     * @return string
     */
    public function toDeleteSql(): string
    {
        $sql = "DELETE FROM {$this->table}";

        if (!empty($this->clauses['where'])) {
            $sql .= " WHERE " . $this->renderWhere();
        }

        return $sql;
    }

    /**
     * Build the SQL string for an INSERT query (debug only).
     *
     * @param array $data Single row: column => value pairs.
     * @return string
     */
    public function toInsertSql(array $data): string
    {
        $columns = array_keys($data);
        $columnList = implode(', ', $columns);
        $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        return "INSERT INTO {$this->table} ({$columnList}) VALUES {$placeholder}";
    }

    /**
     * Get bound parameters in order (for SELECT / WHERE clauses).
     *
     * @return array
     */
    public function getParams(): array
    {
        return $this->clauses['params'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add a WHERE clause entry with boolean connector.
     *
     * @param string $sql     Condition SQL (e.g. "col = ?").
     * @param array  $params  Bound params for the condition.
     * @param string $boolean "AND" or "OR".
     * @return void
     */
    protected function addWhere(string $sql, array $params, string $boolean): void
    {
        $this->clauses['where'][] = [
            'bool' => strtoupper($boolean) === 'OR' ? 'OR' : 'AND',
            'sql' => $sql,
            'params' => $params,
        ];
    }

    /**
     * Render WHERE clauses with correct boolean connectors.
     *
     * @return string
     */
    protected function renderWhere(): string
    {
        $parts = [];
        foreach ($this->clauses['where'] as $i => $entry) {
            $prefix = ($i === 0) ? '' : ' ' . $entry['bool'] . ' ';
            $parts[] = $prefix . $entry['sql'];
        }
        return implode('', $parts);
    }
}
}
