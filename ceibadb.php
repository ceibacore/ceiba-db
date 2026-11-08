<?php

/**
 * CeibaDB — lightweight PDO connection manager and fluent query builder.
 *
 * Global classes (CeibaDB, CeibaQuery) with LemurDB/LemurQuery aliases for
 * backward compatibility. See CHANGELOG.md for the version history.
 */
if (!class_exists('CeibaDB')) {
    class CeibaDB
    {
        public const VERSION = '1.2.0';

        /** Accepted driver names => PDO driver. */
        private const DRIVERS = [
            'mysql'    => 'mysql',
            'mariadb'  => 'mysql',
            'pgsql'    => 'pgsql',
            'postgres' => 'pgsql',
            'sqlite'   => 'sqlite',
        ];

        private const DEFAULT_PORTS = ['mysql' => 3306, 'pgsql' => 5432];

        /** @var array<string, CeibaDB> Instances keyed by configuration hash. */
        private static array $instances = [];

        /** Default instance (first configured one, or the one set by setInstance()). */
        private static ?CeibaDB $instance = null;

        /** When true, getInstance() always returns the instance given to setInstance(). */
        private static bool $isMocked = false;

        /** @var list<callable(array): void> */
        private static array $listeners = [];

        /** @var (callable(array): PDO)|null Supplies the connection for every new instance. */
        private static $pdoResolver = null;

        /** @var PDO|callable|null */
        private $pdo;

        private array $config;

        private int $savepoints = 0;

        /**
         * @param array $config driver, host, port, db, username, password, prefix, charset,
         *                      unix_socket, timeout, persistent, options (PDO attributes)
         */
        private function __construct(array $config, mixed $pdo = null)
        {
            $this->config = $config;
            if ($pdo !== null) {
                $this->pdo = $pdo;
                return;
            }
            $this->connect();
        }

        /** Opens the PDO connection described by the configuration. */
        private function connect(): void
        {
            $config = $this->config;
            $dsn = self::buildDsn($config);
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            if (isset($config['timeout'])) {
                $options[PDO::ATTR_TIMEOUT] = max(1, (int) $config['timeout']);
            }
            if (!empty($config['persistent'])) {
                $options[PDO::ATTR_PERSISTENT] = true;
            }
            if (isset($config['options']) && is_array($config['options'])) {
                $options = array_replace($options, $config['options']);
            }
            // Exceptions are part of the contract (transactions, guards); never configurable.
            $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

            try {
                $this->pdo = new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', $options);
            } catch (PDOException $e) {
                $base = "Connection Error: " . $e->getMessage();
                $debugEnabled = filter_var((string) (getenv('CEIBADB_DEBUG') ?: getenv('LEMURDB_DEBUG') ?: '0'), FILTER_VALIDATE_BOOLEAN);

                if ($debugEnabled) {
                    $debugInfo = self::buildDebugInfo(self::redactConfig($config), $dsn);
                    error_log('[CeibaDB] ' . $base . ' | ' . $debugInfo);
                    throw new \RuntimeException($base . " | " . $debugInfo, 0, $e);
                }

                throw new \RuntimeException($base, 0, $e);
            }
        }

        private static function redactConfig(array $config): array
        {
            $safe = $config;
            if (array_key_exists('password', $safe)) {
                $safe['password'] = '[REDACTED]';
            }
            unset($safe['options']);
            return $safe;
        }

        private static function buildDebugInfo(array $safeConfig, string $dsn): string
        {
            $pdoDrivers = class_exists('PDO') ? implode(',', PDO::getAvailableDrivers()) : 'unavailable';
            $exts = function_exists('get_loaded_extensions')
                ? implode(',', array_filter(get_loaded_extensions(), static function ($ext) {
                    $e = strtolower((string) $ext);
                    return $e === 'pdo' || strpos($e, 'pdo_') === 0 || $e === 'mysqli' || strpos($e, 'mysql') !== false;
                }))
                : 'unavailable';

            return 'ceibadb_debug=' . json_encode([
                'php'         => PHP_VERSION,
                'pdo_drivers' => $pdoDrivers,
                'db_exts'     => $exts,
                'dsn'         => $dsn,
                'config'      => $safeConfig,
            ], JSON_UNESCAPED_SLASHES);
        }

        /**
         * Returns the instance for this configuration, creating and connecting it once.
         * With an empty configuration, returns the default instance.
         */
        public static function getInstance(array $config = []): self
        {
            // A mock set by setInstance() wins (test isolation); empty config means "default".
            if (self::$instance !== null && (self::$isMocked || empty($config))) {
                return self::$instance;
            }
            if (empty($config)) {
                throw new \RuntimeException('CeibaDB is not configured: call CeibaDB::getInstance($config) first.');
            }

            $key = self::configKey($config);
            if (!isset(self::$instances[$key])) {
                $resolver = self::$pdoResolver;
                $db = $resolver === null
                    ? new self($config)
                    : new self($config, static fn (): PDO => $resolver($config));
                self::$instances[$key] = $db;
                if (self::$instance === null) {
                    self::$instance = $db;
                }
            }
            return self::$instances[$key];
        }

        /**
         * Sets the default instance from an existing PDO (or a resolver returning one).
         * While set, getInstance() returns it for any configuration (test isolation).
         * Undo with resetInstances().
         */
        public static function setInstance(\PDO|callable $pdo, array $config = []): void
        {
            self::$instance = new self(array_merge([
                'driver' => 'sqlite',
                'db'     => ':memory:',
                'prefix' => 'ase_',
            ], $config), $pdo);
            self::$isMocked = true;
        }

        /**
         * Drops the cached instance for this configuration (long-running workers serving
         * many tenants). Its connection closes once no other reference remains.
         */
        public static function forget(array $config): void
        {
            $key = self::configKey($config);
            if (isset(self::$instances[$key])) {
                if (self::$instance === self::$instances[$key] && !self::$isMocked) {
                    self::$instance = null;
                }
                unset(self::$instances[$key]);
            }
        }

        /**
         * Makes every instance created from now on use the PDO returned by $resolver
         * instead of opening its own connection, while keeping its own configuration
         * (prefix, driver). The resolver receives that configuration and runs on each
         * pdo() call, so it can follow a host connection that is replaced (a framework
         * test connection, a reconnect). Cached instances are dropped so they rebind.
         * Pass null to go back to dedicated connections.
         *
         * @param (callable(array): PDO)|null $resolver
         */
        public static function usePdoResolver(?callable $resolver): void
        {
            self::$pdoResolver = $resolver;
            self::$instances = [];
            if (!self::$isMocked) {
                self::$instance = null;
            }
        }

        /** True while usePdoResolver() supplies connections. */
        public static function hasPdoResolver(): bool
        {
            return self::$pdoResolver !== null;
        }

        /** Clears every cached instance, the default instance, the mock flag and the PDO resolver. */
        public static function resetInstances(): void
        {
            self::$instances = [];
            self::$instance = null;
            self::$isMocked = false;
            self::$pdoResolver = null;
        }

        /** Number of cached connections (operational visibility). */
        public static function instanceCount(): int
        {
            return count(self::$instances);
        }

        /**
         * Registers a query listener. Each executed statement produces:
         * ['sql' => string, 'params' => int (count, never values), 'durationMs' => float,
         *  'error' => ?string (exception class)]. Listener failures never affect queries.
         */
        public static function listen(callable $listener): void
        {
            self::$listeners[] = $listener;
        }

        public static function clearListeners(): void
        {
            self::$listeners = [];
        }

        /** @internal Called by CeibaQuery after each statement. */
        public static function dispatchQuery(string $sql, int $paramCount, float $durationMs, ?\Throwable $error = null): void
        {
            if (self::$listeners === []) {
                return;
            }
            $event = ['sql' => $sql, 'params' => $paramCount, 'durationMs' => round($durationMs, 3),
                'error' => $error === null ? null : get_class($error)];
            foreach (self::$listeners as $listener) {
                try {
                    $listener($event);
                } catch (\Throwable) {
                    // Observability must never break data access.
                }
            }
        }

        private static function configKey(array $config): string
        {
            return hash('sha256', serialize($config));
        }

        /** Builds a validated, driver-specific DSN. */
        private static function buildDsn(array $c): string
        {
            $requested = strtolower((string) ($c['driver'] ?? 'mysql'));
            $driver = self::DRIVERS[$requested] ?? null;
            if ($driver === null) {
                throw new \InvalidArgumentException("Unsupported CeibaDB driver: {$requested}");
            }

            if ($driver === 'sqlite') {
                $path = (string) ($c['db'] ?? '');
                if ($path === '' || preg_match('/[\x00-\x1F]/', $path)) {
                    throw new \InvalidArgumentException('A valid SQLite database path is required');
                }
                return 'sqlite:' . $path;
            }

            foreach (['host', 'db', 'charset', 'unix_socket'] as $field) {
                if (isset($c[$field]) && preg_match('/[;=\s\x00-\x1F]/', (string) $c[$field])) {
                    throw new \InvalidArgumentException("Invalid characters in CeibaDB connection option '{$field}'");
                }
            }
            $port = $c['port'] ?? self::DEFAULT_PORTS[$driver];
            if (!preg_match('/^\d{1,5}$/', (string) $port) || (int) $port < 1 || (int) $port > 65535) {
                throw new \InvalidArgumentException('Invalid CeibaDB port');
            }
            if (empty($c['db'])) {
                throw new \InvalidArgumentException('A database name is required');
            }

            if ($driver === 'pgsql') {
                return "pgsql:host=" . ($c['host'] ?? '127.0.0.1') . ";port={$port};dbname={$c['db']}";
            }

            $target = !empty($c['unix_socket'])
                ? "unix_socket={$c['unix_socket']}"
                : "host=" . ($c['host'] ?? '127.0.0.1') . ";port={$port}";
            $charset = $c['charset'] ?? 'utf8mb4';
            return "mysql:{$target};dbname={$c['db']};charset={$charset}";
        }

        /** Starts a query builder for a table (name without prefix). */
        public function query(string $table): CeibaQuery
        {
            return new CeibaQuery($this->pdo(), $table, $this->getPrefix());
        }

        public function getPrefix(): string
        {
            return $this->config['prefix'] ?? '';
        }

        /**
         * Runs the callable atomically and returns its result.
         *
         * - Commits on success, rolls back on any Throwable and rethrows it.
         * - Called inside an active transaction (from CeibaDB or raw PDO), it uses a
         *   SAVEPOINT, so nested units of work roll back on their own.
         * - $attempts > 1 retries the outermost transaction on deadlocks and
         *   serialization failures only (MySQL 1213, SQLSTATE 40001/40P01).
         *
         * @param callable(CeibaDB): mixed $callback
         */
        public function transaction(callable $callback, int $attempts = 1): mixed
        {
            if ($attempts < 1) {
                throw new \InvalidArgumentException('Transaction attempts must be at least 1');
            }
            $pdo = $this->pdo();
            if ($pdo->inTransaction()) {
                return $this->savepoint($pdo, $callback);
            }

            for ($try = 1; ; $try++) {
                $pdo->beginTransaction();
                try {
                    $result = $callback($this);
                    $pdo->commit();
                    return $result;
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        try {
                            $pdo->rollBack();
                        } catch (\Throwable) {
                            // Server already rolled back (deadlock); keep the original error.
                        }
                    }
                    if ($try < $attempts && self::isRetryable($e)) {
                        usleep(min(1_000_000, 25_000 * (2 ** ($try - 1))));
                        continue;
                    }
                    throw $e;
                }
            }
        }

        private function savepoint(PDO $pdo, callable $callback): mixed
        {
            $name = 'ceibadb_sp_' . (++$this->savepoints);
            $pdo->exec("SAVEPOINT {$name}");
            try {
                $result = $callback($this);
                $pdo->exec("RELEASE SAVEPOINT {$name}");
                return $result;
            } catch (\Throwable $e) {
                try {
                    $pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
                    $pdo->exec("RELEASE SAVEPOINT {$name}");
                } catch (\Throwable) {
                    // The enclosing transaction may already be gone; the original error matters.
                }
                throw $e;
            }
        }

        private static function isRetryable(\Throwable $e): bool
        {
            if (!$e instanceof PDOException) {
                return false;
            }
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            return in_array($state, ['40001', '40P01'], true) || $driverCode === 1213;
        }

        /**
         * The underlying PDO connection, for raw statements not covered by the builder.
         */
        public function pdo(): PDO
        {
            if ($this->pdo === null) {
                $this->connect();
            }
            if (is_callable($this->pdo)) {
                $resolved = ($this->pdo)();
                if (!$resolved instanceof \PDO) {
                    throw new \RuntimeException("PDO resolver did not return a PDO instance.");
                }
                return $resolved;
            }
            return $this->pdo;
        }

        /**
         * Releases this instance's own connection (reopened lazily on next use).
         * Has no effect on connections supplied through setInstance().
         */
        public function disconnect(): void
        {
            if ($this->pdo instanceof PDO && !self::$isMocked) {
                $this->pdo = null;
            }
        }
    }
}


/**
 * CeibaQuery
 *
 * Fluent query builder for SELECT, INSERT, UPDATE and DELETE.
 * Column arguments accept identifiers only (`col`, `table.col`, or already quoted
 * names); use whereRaw()/orderByRaw() for expressions. select() and join() take
 * raw SQL fragments by design.
 */
if (!class_exists('CeibaQuery')) {
    class CeibaQuery
    {
        private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];
        private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_$]*(\.[A-Za-z_][A-Za-z0-9_$]*)?$/';
        private const QUOTED_IDENTIFIER = '/^(`[^`]+`|"[^"]+")(\.(`[^`]+`|"[^"]+"))?$/';
        /** Stay well below the 65,535 placeholders MySQL and PostgreSQL allow per statement. */
        private const MAX_PARAMS_PER_STATEMENT = 60000;

        protected PDO $pdo;

        /** Fully qualified, quoted table name (with prefix). */
        protected string $table;

        protected string $driver;

        protected array $clauses = [
            'select'  => '*',
            'joins'   => [],
            'where'   => [],
            'params'  => [],
            'order'   => '',
            'limit'   => '',
            'between' => [],
        ];

        public function __construct(PDO $pdo, string $table, string $prefix = '')
        {
            $this->pdo = $pdo;
            try {
                $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            } catch (\Throwable) {
                $this->driver = 'mysql';
            }
            if (!preg_match(self::IDENTIFIER, $prefix . $table)) {
                throw new \InvalidArgumentException("Invalid table name: {$prefix}{$table}");
            }
            $this->table = $this->quoteIdentifier($prefix . $table);
        }

        /** Quotes an identifier (or table.column) for the connection's driver. */
        protected function quoteIdentifier(string $identifier): string
        {
            if (strpos($identifier, '.') !== false) {
                return implode('.', array_map([$this, 'quoteIdentifier'], explode('.', $identifier)));
            }
            $q = $this->driver === 'pgsql' ? '"' : '`';
            return $q . str_replace($q, $q . $q, $identifier) . $q;
        }

        /** Validates and quotes a column argument; rejects SQL expressions. */
        protected function column(string $column): string
        {
            $column = trim($column);
            if (preg_match(self::IDENTIFIER, $column)) {
                return $this->quoteIdentifier($column);
            }
            if (preg_match(self::QUOTED_IDENTIFIER, $column)) {
                return $column;
            }
            throw new \InvalidArgumentException(
                "Invalid column identifier '{$column}'. Use whereRaw()/orderByRaw() for SQL expressions."
            );
        }

        // ─────────────────────────────────────────────────────────────────────────
        // JOIN METHODS (raw table and condition; tables are NOT prefixed automatically)
        // ─────────────────────────────────────────────────────────────────────────

        public function join(string $table, string $on): static
        {
            return $this->addJoin('INNER', $table, $on);
        }

        public function leftJoin(string $table, string $on): static
        {
            return $this->addJoin('LEFT', $table, $on);
        }

        public function rightJoin(string $table, string $on): static
        {
            return $this->addJoin('RIGHT', $table, $on);
        }

        private function addJoin(string $type, string $table, string $on): static
        {
            $this->clauses['joins'][] = strtoupper($type) . " JOIN {$table} ON {$on}";
            return $this;
        }

        // ─────────────────────────────────────────────────────────────────────────
        // ORDER / LIMIT
        // ─────────────────────────────────────────────────────────────────────────

        /**
         * Adds a sort key; successive calls accumulate. "column DESC" is accepted.
         */
        public function orderBy(string $column, string $direction = 'ASC'): static
        {
            $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $column = trim($column);
            if (preg_match('/^(.+?)\s+(ASC|DESC)$/i', $column, $parts)) {
                $column = $parts[1];
                $dir = strtoupper($parts[2]);
            }
            return $this->orderByRaw($this->column($column) . " {$dir}");
        }

        /** Adds a raw ORDER BY fragment (trusted SQL only). */
        public function orderByRaw(string $sql): static
        {
            $this->clauses['order'] .= ($this->clauses['order'] === '' ? ' ORDER BY ' : ', ') . $sql;
            return $this;
        }

        public function limit(int $limit, int $offset = 0): static
        {
            if ($limit < 0 || $offset < 0) {
                throw new \InvalidArgumentException('LIMIT and OFFSET must be non-negative');
            }
            $this->clauses['limit'] = " LIMIT {$limit} OFFSET {$offset}";
            return $this;
        }

        // ─────────────────────────────────────────────────────────────────────────
        // SELECT / WHERE
        // ─────────────────────────────────────────────────────────────────────────

        /** Sets the select list (raw SQL: columns, aliases or aggregates). */
        public function select(string|array $fields = '*'): static
        {
            $this->clauses['select'] = is_array($fields) ? implode(', ', $fields) : $fields;
            return $this;
        }

        /**
         * AND conditions: where(['col' => value, ...]), where('col', value) or
         * where('col', '>=', value). A null value compares with IS NULL / IS NOT NULL.
         */
        public function where(array|string $column, mixed $operator = null, mixed $value = null): static
        {
            return $this->addConditions('AND', $column, $operator, $value, func_num_args());
        }

        /** OR conditions, with the same forms as where(). */
        public function orWhere(array|string $column, mixed $operator = null, mixed $value = null): static
        {
            return $this->addConditions('OR', $column, $operator, $value, func_num_args());
        }

        private function addConditions(string $boolean, array|string $column, mixed $operator, mixed $value, int $argc): static
        {
            if (is_array($column)) {
                foreach ($column as $name => $item) {
                    $this->addComparison($boolean, (string) $name, '=', $item);
                }
                return $this;
            }
            if ($argc < 2) {
                throw new \InvalidArgumentException('where() needs a value');
            }
            return $argc === 2
                ? $this->addComparison($boolean, $column, '=', $operator)
                : $this->addComparison($boolean, $column, (string) $operator, $value);
        }

        private function addComparison(string $boolean, string $column, string $operator, mixed $value): static
        {
            $op = strtoupper(trim($operator));
            if (!in_array($op, self::OPERATORS, true)) {
                throw new \InvalidArgumentException("Unsupported operator: {$operator}");
            }
            $col = $this->column($column);
            if ($value === null) {
                if ($op === '=') {
                    $this->addWhere("{$col} IS NULL", [], $boolean);
                    return $this;
                }
                if ($op === '!=' || $op === '<>') {
                    $this->addWhere("{$col} IS NOT NULL", [], $boolean);
                    return $this;
                }
                throw new \InvalidArgumentException("Operator {$op} cannot compare with NULL");
            }
            if (is_array($value)) {
                throw new \InvalidArgumentException("Array value for '{$column}': use whereIn()");
            }
            $this->addWhere("{$col} {$op} ?", [$value], $boolean);
            $this->clauses['params'][] = $value;
            return $this;
        }

        /** IN list; an empty list matches nothing (or everything when negated). */
        public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): static
        {
            $col = $this->column($column);
            if ($values === []) {
                $this->addWhere($not ? '1 = 1' : '1 = 0', [], $boolean);
                return $this;
            }
            foreach ($values as $item) {
                if (is_array($item) || $item === null) {
                    throw new \InvalidArgumentException('whereIn() values must be non-null scalars');
                }
            }
            $values = array_values($values);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $this->addWhere("{$col} " . ($not ? 'NOT IN' : 'IN') . " ({$placeholders})", $values, $boolean);
            array_push($this->clauses['params'], ...$values);
            return $this;
        }

        public function whereNotIn(string $column, array $values, string $boolean = 'AND'): static
        {
            return $this->whereIn($column, $values, $boolean, true);
        }

        public function whereNull(string $column, string $boolean = 'AND'): static
        {
            $this->addWhere($this->column($column) . ' IS NULL', [], $boolean);
            return $this;
        }

        public function whereNotNull(string $column, string $boolean = 'AND'): static
        {
            $this->addWhere($this->column($column) . ' IS NOT NULL', [], $boolean);
            return $this;
        }

        public function between(string $column, mixed $start, mixed $end): static
        {
            return $this->addBetween('AND', $column, $start, $end);
        }

        public function orBetween(string $column, mixed $start, mixed $end): static
        {
            return $this->addBetween('OR', $column, $start, $end);
        }

        private function addBetween(string $boolean, string $column, mixed $start, mixed $end): static
        {
            $this->addWhere($this->column($column) . ' BETWEEN ? AND ?', [$start, $end], $boolean);
            $this->clauses['params'][] = $start;
            $this->clauses['params'][] = $end;
            return $this;
        }

        public function like(string $column, string $pattern, string $boolean = 'AND'): static
        {
            $this->addWhere($this->column($column) . ' LIKE ?', [$pattern], $boolean);
            $this->clauses['params'][] = $pattern;
            return $this;
        }

        public function orLike(string $column, string $pattern): static
        {
            return $this->like($column, $pattern, 'OR');
        }

        /** Raw WHERE fragment with positional parameters (trusted SQL only). */
        public function whereRaw(string $sql, array $params = [], string $boolean = 'AND'): static
        {
            $this->addWhere($sql, $params, $boolean);
            foreach ($params as $p) {
                $this->clauses['params'][] = $p;
            }
            return $this;
        }

        // ─────────────────────────────────────────────────────────────────────────
        // READ
        // ─────────────────────────────────────────────────────────────────────────

        public function get(): array
        {
            return $this->run($this->toSql(), $this->clauses['params'])->fetchAll();
        }

        /** First row or null; the builder itself is not modified. */
        public function first(): ?array
        {
            $query = clone $this;
            $query->limit(1);
            $row = $query->run($query->toSql(), $query->clauses['params'])->fetch();
            return $row !== false ? $row : null;
        }

        public function exists(): bool
        {
            $query = clone $this;
            $query->clauses['select'] = '1';
            $query->clauses['order'] = '';
            $query->limit(1);
            return $query->run($query->toSql(), $query->clauses['params'])->fetchColumn() !== false;
        }

        /** Row count for the current conditions (ordering and limits are ignored). */
        public function count(string $column = '*'): int
        {
            $query = clone $this;
            $query->clauses['select'] = 'COUNT(' . ($column === '*' ? '*' : $this->column($column)) . ')';
            $query->clauses['order'] = '';
            $query->clauses['limit'] = '';
            return (int) $query->run($query->toSql(), $query->clauses['params'])->fetchColumn();
        }

        /** Values of one column. */
        public function pluck(string $column): array
        {
            $query = clone $this;
            $query->clauses['select'] = $this->column($column);
            return $query->run($query->toSql(), $query->clauses['params'])->fetchAll(PDO::FETCH_COLUMN);
        }

        /**
         * Iterates rows one at a time. Rows are not accumulated in a PHP array; for
         * true streaming on MySQL also disable PDO::MYSQL_ATTR_USE_BUFFERED_QUERY.
         *
         * @return \Generator<int, array>
         */
        public function cursor(): \Generator
        {
            $statement = $this->run($this->toSql(), $this->clauses['params']);
            while (($row = $statement->fetch()) !== false) {
                yield $row;
            }
        }

        // ─────────────────────────────────────────────────────────────────────────
        // WRITE
        // ─────────────────────────────────────────────────────────────────────────

        /**
         * Inserts one row. Returns the auto-increment ID, or 0 when the table has
         * none (UUID keys) or the driver cannot report it.
         */
        public function insert(array $data): int
        {
            [$sql, $params] = $this->buildInsertSql([$data]);
            $this->run($sql, $params);
            try {
                return (int) $this->pdo->lastInsertId();
            } catch (\Throwable) {
                return 0;
            }
        }

        /**
         * Inserts several rows with the same columns. Values are matched by column
         * name. Large batches are split below the driver placeholder limit and run
         * in one transaction (or inside the caller's).
         */
        public function insertBatch(array $rows): int
        {
            if ($rows === []) {
                return 0;
            }
            $rows = array_values($rows);
            $first = $rows[0];
            if (!is_array($first) || $first === []) {
                throw new \InvalidArgumentException('insertBatch() rows must be non-empty arrays');
            }
            $perStatement = max(1, intdiv(self::MAX_PARAMS_PER_STATEMENT, count($first)));
            $chunks = array_chunk($rows, $perStatement);
            if (count($chunks) === 1) {
                [$sql, $params] = $this->buildInsertSql($rows);
                return $this->run($sql, $params)->rowCount();
            }

            $ownTransaction = !$this->pdo->inTransaction();
            if ($ownTransaction) {
                $this->pdo->beginTransaction();
            }
            try {
                $inserted = 0;
                foreach ($chunks as $chunk) {
                    [$sql, $params] = $this->buildInsertSql($chunk);
                    $inserted += $this->run($sql, $params)->rowCount();
                }
                if ($ownTransaction) {
                    $this->pdo->commit();
                }
                return $inserted;
            } catch (\Throwable $e) {
                if ($ownTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }

        /** @return array{0: string, 1: list<mixed>} */
        private function buildInsertSql(array $rows): array
        {
            $first = $rows[0];
            if (!is_array($first) || $first === []) {
                throw new \InvalidArgumentException('Insert data must be a non-empty array');
            }
            $columns = array_keys($first);
            $expected = $columns;
            sort($expected);
            $params = [];
            foreach ($rows as $index => $row) {
                $keys = is_array($row) ? array_keys($row) : [];
                sort($keys);
                if ($keys !== $expected) {
                    throw new \InvalidArgumentException("Insert row {$index} must have the same columns as the first row");
                }
                foreach ($columns as $column) {
                    $params[] = $row[$column];
                }
            }
            $columnList = implode(', ', array_map(fn ($c) => $this->column((string) $c), $columns));
            $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $valueSets = implode(', ', array_fill(0, count($rows), $placeholder));
            return ["INSERT INTO {$this->table} ({$columnList}) VALUES {$valueSets}", $params];
        }

        /** Updates rows matching the WHERE clauses (at least one is required). */
        public function update(array $data): int
        {
            $this->guardWhere('update');
            if ($data === []) {
                throw new \InvalidArgumentException('update() needs at least one column');
            }
            $set = implode(', ', array_map(fn ($c) => $this->column((string) $c) . ' = ?', array_keys($data)));
            $sql = "UPDATE {$this->table} SET {$set} WHERE " . $this->renderWhere();
            return $this->run($sql, array_merge(array_values($data), $this->clauses['params']))->rowCount();
        }

        /**
         * Atomically adds to a numeric column (plus optional extra columns).
         * Requires at least one WHERE condition.
         */
        public function increment(string $column, int|float $amount = 1, array $extra = []): int
        {
            $this->guardWhere('increment');
            $col = $this->column($column);
            $sets = ["{$col} = {$col} + ?"];
            $params = [$amount];
            foreach ($extra as $name => $value) {
                $sets[] = $this->column((string) $name) . ' = ?';
                $params[] = $value;
            }
            $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . ' WHERE ' . $this->renderWhere();
            return $this->run($sql, array_merge($params, $this->clauses['params']))->rowCount();
        }

        public function decrement(string $column, int|float $amount = 1, array $extra = []): int
        {
            return $this->increment($column, -$amount, $extra);
        }

        /** Deletes rows matching the WHERE clauses (at least one is required). */
        public function delete(): int
        {
            $this->guardWhere('delete');
            $sql = "DELETE FROM {$this->table} WHERE " . $this->renderWhere();
            return $this->run($sql, $this->clauses['params'])->rowCount();
        }

        /**
         * Removes ALL rows (resets auto-increment where supported). Cannot be undone.
         * SQLite has no TRUNCATE; DELETE FROM is used instead.
         */
        public function truncate(): bool
        {
            $sql = $this->driver === 'sqlite' ? "DELETE FROM {$this->table}" : "TRUNCATE TABLE {$this->table}";
            $this->run($sql, []);
            return true;
        }

        private function guardWhere(string $operation): void
        {
            if (empty($this->clauses['where'])) {
                throw new \RuntimeException(
                    "CeibaQuery::{$operation}() requires at least one WHERE condition. " .
                    "Use where() before calling {$operation}() to avoid full-table changes."
                );
            }
        }

        // ─────────────────────────────────────────────────────────────────────────
        // DEBUG HELPERS
        // ─────────────────────────────────────────────────────────────────────────

        public function toSql(): string
        {
            $sql = "SELECT {$this->clauses['select']} FROM {$this->table}";
            if (!empty($this->clauses['joins'])) {
                $sql .= " " . implode(" ", $this->clauses['joins']);
            }
            if (!empty($this->clauses['where'])) {
                $sql .= " WHERE " . $this->renderWhere();
            }
            return $sql . $this->clauses['order'] . $this->clauses['limit'];
        }

        public function toJoinSql(): string
        {
            return implode(' ', $this->clauses['joins']);
        }

        public function toUpdateSql(array $data): string
        {
            $set = implode(', ', array_map(fn ($c) => $this->column((string) $c) . ' = ?', array_keys($data)));
            $sql = "UPDATE {$this->table} SET {$set}";
            if (!empty($this->clauses['where'])) {
                $sql .= " WHERE " . $this->renderWhere();
            }
            return $sql;
        }

        public function toDeleteSql(): string
        {
            $sql = "DELETE FROM {$this->table}";
            if (!empty($this->clauses['where'])) {
                $sql .= " WHERE " . $this->renderWhere();
            }
            return $sql;
        }

        public function toInsertSql(array $data): string
        {
            return $this->buildInsertSql([$data])[0];
        }

        public function getParams(): array
        {
            return $this->clauses['params'];
        }

        // ─────────────────────────────────────────────────────────────────────────
        // INTERNAL HELPERS
        // ─────────────────────────────────────────────────────────────────────────

        /** Prepares, binds with explicit types, executes and reports to listeners. */
        private function run(string $sql, array $params): \PDOStatement
        {
            $started = hrtime(true);
            $error = null;
            try {
                $statement = $this->pdo->prepare($sql);
                foreach (array_values($params) as $index => $value) {
                    [$bound, $type] = self::bindable($value);
                    $statement->bindValue($index + 1, $bound, $type);
                }
                $statement->execute();
                return $statement;
            } catch (\Throwable $e) {
                $error = $e;
                throw $e;
            } finally {
                if (method_exists('CeibaDB', 'dispatchQuery')) {
                    CeibaDB::dispatchQuery($sql, count($params), (hrtime(true) - $started) / 1e6, $error);
                }
            }
        }

        /** @return array{0: mixed, 1: int} */
        private static function bindable(mixed $value): array
        {
            return match (true) {
                $value === null                         => [null, PDO::PARAM_NULL],
                is_bool($value)                         => [$value ? 1 : 0, PDO::PARAM_INT],
                is_int($value)                          => [$value, PDO::PARAM_INT],
                is_float($value)                        => [(string) $value, PDO::PARAM_STR],
                is_string($value)                       => [$value, PDO::PARAM_STR],
                $value instanceof \DateTimeInterface    => [$value->format('Y-m-d H:i:s'), PDO::PARAM_STR],
                $value instanceof \BackedEnum           => self::bindable($value->value),
                $value instanceof \Stringable           => [(string) $value, PDO::PARAM_STR],
                default => throw new \InvalidArgumentException('Unsupported parameter type: ' . get_debug_type($value)),
            };
        }

        protected function addWhere(string $sql, array $params, string $boolean): void
        {
            $this->clauses['where'][] = [
                'bool'   => strtoupper($boolean) === 'OR' ? 'OR' : 'AND',
                'sql'    => $sql,
                'params' => $params,
            ];
        }

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

// ── Backward Compatibility Aliases ─────────────────────────────────────────
if (!class_exists('LemurDB', false)) {
    class_alias('CeibaDB', 'LemurDB');
}
if (!class_exists('LemurQuery', false)) {
    class_alias('CeibaQuery', 'LemurQuery');
}
