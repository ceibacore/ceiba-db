# CeibaDB

[![Packagist Version](https://img.shields.io/packagist/v/ceibacore/ceiba-db.svg)](https://packagist.org/packages/ceibacore/ceiba-db)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A lightweight, zero-dependency, PDO-backed database abstraction layer and fluent query builder for PHP 8.1+.

Designed for maximum developer ergonomics without the overhead of heavy ORMs. Supports MySQL, MariaDB, PostgreSQL, and SQLite.

---

## Installation

```bash
composer require ceibacore/ceiba-db
```

---

## Quick Start

### 1. Initialize Connection (Singleton)

```php
use CeibaDB;

// Configure singleton instance
$db = CeibaDB::getInstance([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'db'       => 'my_database',
    'username' => 'db_user',
    'password' => 'secret',
    'charset'  => 'utf8mb4',
    'prefix'   => 'cb_', // optional table prefix
]);
```

### 2. Query Builder (`CeibaQuery`)

```php
// SELECT
$users = $db->query('users')
    ->select(['id', 'name', 'email'])
    ->where(['status' => 1])
    ->orderby('created_at DESC')
    ->limit(20)
    ->get();

// INSERT
$userId = $db->query('users')->insert([
    'name'  => 'Jane Doe',
    'email' => 'jane@example.com',
]);

// UPDATE (requires at least one WHERE condition for safety)
$affected = $db->query('users')
    ->where(['id' => $userId])
    ->update(['name' => 'Jane Smith']);

// DELETE (requires at least one WHERE condition for safety)
$deleted = $db->query('users')
    ->where(['id' => $userId])
    ->delete();
```

### 3. Transactions

```php
$db->transaction(function (CeibaDB $db) {
    $db->query('accounts')->where(['id' => 1])->decrement('balance', 100);
    $db->query('accounts')->where(['id' => 2])->increment('balance', 100);
});
```

---

## Backward Compatibility

CeibaDB includes 100% backward compatibility aliases for `LemurDB` and `LemurQuery`:

```php
// Existing code using LemurDB continues to work transparently:
$db = LemurDB::getInstance();
```

---

## License

MIT License. See [LICENSE](LICENSE) for details.
