# LemurDB

A minimal, lightweight PHP database wrapper that exposes a fluent query builder backed by PDO.

---

## Requirements

- PHP 8.x
- PDO extension enabled
- A supported PDO driver (e.g., `pdo_mysql`, `pdo_pgsql`)

---

## Installation

Copy `LemurDB.php` into your project and require it:

```php
require_once 'LemurDB.php';
```

---

## Getting Started

LemurDB uses a **Singleton pattern** — the connection is created once and reused across your application.

```php
$db = LemurDB::getInstance([
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'db'       => 'my_database',
    'username' => 'root',
    'password' => 'secret',
    'prefix'   => 'app_',   // optional table prefix
]);
```

> ⚠️ Pass the config only on the **first** call. Subsequent `getInstance()` calls return the existing connection.

---

## Query Builder — `LemurQuery`

All queries start with `$db->query('table_name')`, which returns a chainable `LemurQuery` instance.

### `select()`

```php
// Select all columns (default)
$db->query('users')->select()->get();

// Select specific columns
$db->query('users')->select(['id', 'name', 'email'])->get();

// Raw select string
$db->query('users')->select('id, name, email')->get();
```

---

### `where()` / `orWhere()`

```php
// Single AND condition
$db->query('users')
    ->where(['status' => 'active'])
    ->get();

// Multiple AND conditions
$db->query('users')
    ->where(['status' => 'active', 'role' => 'admin'])
    ->get();

// OR condition
$db->query('users')
    ->where(['status' => 'active'])
    ->orWhere(['status' => 'pending'])
    ->get();
```

---

### `like()` / `orLike()`

```php
// AND LIKE
$db->query('products')
    ->like('name', '%cable%')
    ->get();

// OR LIKE
$db->query('products')
    ->like('name', '%cable%')
    ->orLike('description', '%cable%')
    ->get();
```

---

### `between()` / `orBetween()`

```php
// AND BETWEEN
$db->query('orders')
    ->between('total', 100, 500)
    ->get();

// OR BETWEEN
$db->query('orders')
    ->between('total', 100, 500)
    ->orBetween('total', 1000, 2000)
    ->get();
```

---

### `orderby()`

```php
$db->query('users')
    ->orderby('created_at DESC')
    ->get();
```

---

### `limit()`

```php
// First 10 rows
$db->query('users')->limit(10)->get();

// Rows 21–30 (pagination: offset 20, limit 10)
$db->query('users')->limit(10, 20)->get();
```

---

## Chaining Example

```php
$results = $db->query('products')
    ->select(['id', 'name', 'price'])
    ->where(['status' => 'active'])
    ->like('name', '%usb%')
    ->between('price', 5, 100)
    ->orderby('price ASC')
    ->limit(20, 0)
    ->get();
```

---

## Debugging — `toSql()` and `getParams()`

Inspect the generated SQL and bound parameters before execution:

```php
$query = $db->query('users')
    ->select(['id', 'name'])
    ->where(['status' => 'active'])
    ->limit(5);

echo $query->toSql();
// SELECT id, name FROM app_users WHERE status = ? LIMIT 0, 5

print_r($query->getParams());
// Array ( [0] => active )
```

---

## Table Prefix

If a `prefix` is set in the config, it is automatically prepended to all table names:

```php
// Config: 'prefix' => 'app_'
$db->query('users');
// Queries the table: app_users
```

---

## PDO Configuration

LemurDB configures PDO with these defaults out of the box:

| Option | Value |
|---|---|
| Error mode | `ERRMODE_EXCEPTION` |
| Fetch mode | `FETCH_ASSOC` |
| Emulate prepares | `false` (native prepared statements) |
| Charset | `utf8mb4` |

---

## Architecture

```
LemurDB  (Singleton)
  └─► LemurQuery  (Fluent builder)
        ├─ select()
        ├─ where() / orWhere()
        ├─ like()  / orLike()
        ├─ between() / orBetween()
        ├─ orderby()
        ├─ limit()
        ├─ get()       ← executes and returns results
        ├─ toSql()     ← returns SQL string (debug)
        └─ getParams() ← returns bound params (debug)
```

---

## Security

- All user-supplied values are **bound as parameters** via PDO prepared statements — SQL injection is not possible through the query builder API.
- Native prepared statements are enforced (`ATTR_EMULATE_PREPARES => false`).

---

## Limitations

- **SELECT only** — INSERT, UPDATE, and DELETE are not implemented in this version.
- **No JOIN support** — the `joins` clause is reserved in the builder but not exposed via a public method yet.
- Only tested with **MySQL/MariaDB**. PostgreSQL and SQLite may require DSN adjustments.

---

## License

MIT
