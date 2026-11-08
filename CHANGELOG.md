# Changelog

All notable changes to this project are documented here. The project follows [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-11-07

### Security
- Column arguments (`where`, `orWhere`, `like`, `orLike`, `between`, `orBetween`, `orderBy`, `whereIn`, `whereNull`, `count`, `pluck`, insert/update keys) accept identifiers only: `col`, `table.col`, or an already quoted name. SQL expressions must go through `whereRaw()` / `orderByRaw()`. This closes SQL injection through column names.
- Table names passed to `query()` are validated.
- Connection options are validated before building the DSN (driver allowlist; no `;`, `=`, whitespace or control characters in host, database, charset or socket; numeric port in range).
- Removed a script with hard-coded database credentials from the repository. **Rotate any password that appeared in it.**
- The connection debug output never includes the password or PDO options.

### Fixed
- `orderBy()` calls accumulate instead of replacing each other, and plain column names are quoted, so reserved words such as `key` and `group` work. The `orderBy('col DESC')` form keeps working.
- `where(['col' => null])` now produces `IS NULL` (it used to compare `= NULL`, which never matches).
- `insertBatch()` matches values by column name; rows with different key order no longer insert shifted values. Rows with different columns are rejected.
- `truncate()` returns `true` on success (it always returned `false`) and works on SQLite.
- `limit()` uses the portable `LIMIT n OFFSET m` and rejects negative values.
- `first()`, `exists()`, `count()` and `pluck()` no longer modify the builder.
- Parameters are bound with explicit types: `false` is sent as `0` (it was sent as an empty string), `null` as SQL NULL, plus `DateTimeInterface`, backed enums and `Stringable`.
- Empty `insert()` / `update()` data and array values in `where()` fail with clear exceptions.
- `getInstance()` without a configured default fails with a clear message.
- Identifiers are quoted per driver (double quotes on PostgreSQL), and the PostgreSQL DSN no longer receives a `charset` parameter.
- The documented `charset` option is honoured for MySQL/MariaDB.

### Added
- `where('col', 'op', value)` / `orWhere(...)` with an operator allowlist, plus `where('col', value)`.
- `whereIn()`, `whereNotIn()` (empty lists are handled), `whereNull()`, `whereNotNull()`.
- `count()`, `pluck()`, `cursor()` (generator), `increment()`, `decrement()`, `orderByRaw()`.
- Nested `transaction()` calls use savepoints, including inside transactions opened with raw PDO.
- `transaction($callback, $attempts)` retries deadlocks and serialization failures (MySQL 1213, SQLSTATE 40001/40P01) on the outermost transaction.
- `CeibaDB::listen()` / `clearListeners()`: observability hook with the SQL, parameter count and duration (never values).
- `CeibaDB::forget()`, `resetInstances()`, `instanceCount()` and `disconnect()` for long-running workers and test isolation.
- Connection options `timeout`, `persistent`, `unix_socket`, `charset` and `options` (PDO attributes); `mariadb` and `postgres` driver aliases.
- `insertBatch()` splits very large batches below the placeholder limit and runs them atomically.
- `CeibaDB::VERSION`.

### Changed
- `exists()` selects `1` instead of whole rows.
- Debug helpers (`toInsertSql`, `toUpdateSql`) quote identifiers like the executed SQL.
- Tests, examples and tooling are excluded from the Composer distribution.

## [1.0.1]

- Previous release.
