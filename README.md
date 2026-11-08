# CeibaDB

[![Packagist Version](https://img.shields.io/packagist/v/ceibacore/ceiba-db.svg)](https://packagist.org/packages/ceibacore/ceiba-db)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Lightweight, zero-dependency PDO connection manager and fluent query builder for PHP 8.1+. Built for multi-tenant applications in the Ceiba Core ecosystem. MySQL/MariaDB and SQLite are covered by the test suite; PostgreSQL has driver-aware quoting and DSN but is not yet validated against a live server.

## Installation

```bash
composer require ceibacore/ceiba-db
```

## Connecting

```php
$db = CeibaDB::getInstance([
    'driver'   => 'mysql',        // mysql | mariadb | pgsql | postgres | sqlite
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'db'       => 'my_database',
    'username' => 'db_user',
    'password' => 'secret',
    'charset'  => 'utf8mb4',      // MySQL/MariaDB
    'prefix'   => 'cb_',          // optional table prefix
    'timeout'  => 5,              // optional, seconds
    // 'unix_socket' => '/run/mysqld/mysqld.sock',
    // 'persistent'  => true,
    // 'options'     => [PDO::MYSQL_ATTR_SSL_CA => '/path/ca.pem'],
]);
```

One instance is cached per configuration. In long-running workers serving many tenants, release instances you no longer need:

```php
CeibaDB::forget($tenantConfig);   // drop one cached connection
CeibaDB::instanceCount();         // monitor cached connections
$db->disconnect();                // close now; reopens lazily on next use
```

## Query builder

```php
$users = $db->query('users')
    ->select(['id', 'name', 'email'])          // raw select list (aggregates allowed)
    ->where(['status' => 1])                    // array form
    ->where('created_at', '>=', '2026-01-01')   // operator form
    ->whereIn('role', ['admin', 'editor'])
    ->whereNull('deleted_at')
    ->orderBy('name')->orderBy('id', 'DESC')    // accumulates
    ->limit(20, 40)                             // LIMIT 20 OFFSET 40
    ->get();

$db->query('users')->where(['id' => 5])->first();   // ?array
$db->query('users')->where('status', 1)->count();
$db->query('users')->pluck('email');
foreach ($db->query('logs')->cursor() as $row) { /* one row at a time */ }
```

`where(['col' => null])` means `IS NULL`; `where('col', '!=', null)` means `IS NOT NULL`. Supported operators: `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`.

### Writing

```php
$id = $db->query('users')->insert(['name' => 'Jane', 'email' => 'jane@example.com']); // 0 for UUID keys
$db->query('users')->insertBatch($rows);             // values matched by column name
$db->query('users')->where(['id' => $id])->update(['name' => 'Jane Smith']);
$db->query('accounts')->where(['id' => 1])->decrement('balance', 100);
$db->query('users')->where(['id' => $id])->delete();
```

`update()`, `delete()`, `increment()` and `decrement()` refuse to run without a WHERE condition.

### Security model

- Values are always bound parameters, with explicit types (`bool` → `0`/`1`, `null`, `DateTimeInterface`, backed enums, `Stringable`).
- Column arguments accept identifiers only: `col`, `table.col`, or an already quoted name. Anything else throws `InvalidArgumentException`.
- `select()`, `join()`, `whereRaw()` and `orderByRaw()` take raw SQL **by design**: never pass user input to them.
- Insert/update keys are validated as identifiers, but choosing which columns a user may write is the caller's job (allowlist them).
- `join()` does not add the table prefix: pass the full table name.

## Transactions

```php
$db->transaction(function (CeibaDB $db) {
    $db->query('orders')->insert([...]);
    $db->transaction(fn (CeibaDB $db) => $db->query('audit')->insert([...])); // SAVEPOINT
});

// Retry deadlocks / serialization failures up to 3 times (outermost transaction only).
$db->transaction(fn (CeibaDB $db) => $db->query('stock')->where(['sku' => $sku])->decrement('qty'), 3);
```

## Observability

```php
CeibaDB::listen(function (array $event) {
    // ['sql' => ..., 'params' => count, 'durationMs' => float, 'error' => ?class]
    if ($event['durationMs'] > 200) {
        error_log('Slow query: ' . $event['sql']);
    }
});
```

Parameter values are never included in events.

## Testing

```bash
composer test
```

Set `CEIBADB_TEST_MYSQL_HOST`, `CEIBADB_TEST_MYSQL_PORT`, `CEIBADB_TEST_MYSQL_DB`, `CEIBADB_TEST_MYSQL_USER` and `CEIBADB_TEST_MYSQL_PASS` to also run the MySQL/MariaDB contract tests. For tests, `CeibaDB::setInstance($pdo)` makes every `getInstance()` return that connection; call `CeibaDB::resetInstances()` in `tearDown()`.

## Backward compatibility

`LemurDB` and `LemurQuery` remain available as aliases of `CeibaDB` and `CeibaQuery`.

## License

MIT. See [LICENSE](LICENSE).
