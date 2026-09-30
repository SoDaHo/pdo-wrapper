# Changelog

## [Unreleased]

### Added
- `now()` and `utcNow()` on every driver and in `DatabaseInterface`: the database's current local or UTC timestamp at statement time, to the second, as a raw expression in the driver's dialect (MySQL `NOW()`/`UTC_TIMESTAMP()`, PostgreSQL `CAST(statement_timestamp() AS TIMESTAMP(0))`/`CAST(statement_timestamp() AT TIME ZONE 'UTC' AS TIMESTAMP(0))`, SQLite `datetime('now', 'localtime')`/`datetime('now')`), for use as a value in `insert()`, `update()` and `where()` (#1). Both are zoneless values: use them with `DATETIME`/`TIMESTAMP WITHOUT TIME ZONE`/`TEXT` columns; a zone-aware column (PostgreSQL `TIMESTAMPTZ`, MySQL `TIMESTAMP`) interprets `utcNow()` in the session's time zone. Migration note for the rare direct implementation of `DatabaseInterface`: it must add both methods (drivers extending `AbstractDriver` inherit them).

- `lockForUpdate()` and `sharedLock()` on the query builder: `FOR UPDATE` / `LOCK IN SHARE MODE` on MySQL/MariaDB, `FOR UPDATE` / `FOR SHARE` on PostgreSQL, omitted on SQLite (no row locks). `exists()` keeps the lock, aggregates drop it; combined with `distinct()`, `groupBy()` or `having()` the lock throws a `QueryException`.
- The query builder knows its SQL dialect (`QueryBuilder::DIALECT_*`, passed by the driver; an optional fourth constructor argument, derived from the quote character when omitted).

### Changed
- A `Database::raw()` expression given as a **value** in `insert()`, `update()`, `where()`, `whereIn()`, `whereBetween()` or `having()` (drivers and query builder) is inlined into the SQL instead of being bound as a string. Before, such a value was stored or compared literally as text; `raw()` in `select()` lists is unchanged. The automatic `ESCAPE '\'` clause of `LIKE` on PostgreSQL and SQLite applies to raw patterns too. As with every raw expression: never build it from user input.

### Fixed
- A `PDOException` thrown by a `query` hook was reported as a failed query (`Query failed`, `error` hook fired) although the statement had run. It now arrives as `QueryException` with the message `Query hook failed` and without the `error` hook; other exceptions from `query` hooks reach the caller unchanged, as before.
- `query()` ignored `PDO::prepare()` / `PDOStatement::execute()` returning `false` (non-exception error mode) and returned the statement as if it had run; it now throws `QueryException` (`Query failed`) and fires the `error` hook, as for exceptions.
- The operators `IS` and `IS NOT` produced invalid SQL on MySQL/MariaDB and PostgreSQL with a bound value. With a bound value they now mean null-safe equality and are rendered per dialect (SQLite `IS`, MySQL `<=>`, PostgreSQL `IS NOT DISTINCT FROM`), in `where()`, `having()` and joins; with a raw value (`Database::raw('TRUE')`, `raw('NULL')`) the SQL is passed through unchanged, as before.
- `exists()` runs `SELECT 1 ... LIMIT 1` instead of `COUNT(*)` and keeps a requested row lock.
- `where(column: 'id', value: 5)` with named arguments (no operator) means equality instead of throwing.
- `offset()` without `limit()` produced invalid SQL on MySQL/MariaDB and SQLite; the builder now adds the "no limit" value those databases require.
- `where()` with two arguments treated a value that spells an operator (`'IS'` for Iceland, `'LIKE'`) as an operator and threw; the argument count now decides the form. With three arguments an invalid operator is reported as such (before, `where('name', 'A', null)` silently meant `name = 'A'`); a `null` value still throws.
- `select(['users.*'])` quoted the wildcard (`"users"."*"`, "no such column"); it is now `"users".*`.
- QueryBuilder `update([])` produced invalid SQL; it now throws "Cannot update with empty data" like the driver's `update()`.
- `whereIn()` and `whereBetween()` with string-keyed arrays (e.g. `['min' => 1, 'max' => 5]`): the keys were renumbered or read as index 0/1, so `whereBetween()` silently matched nothing and `whereIn()` could bind the wrong values.

## [1.1.1] - 2026-09-30

### Added
- Weekly dependency scan of `composer.lock` with osv-scanner (`dep-cve-scan.yml`).
- `SECURITY.md` with the private reporting channel.

### Changed
- CI: every action is pinned to a commit SHA and the job container to its image digest; the job token is read-only.
- `composer.lock` is committed, resolved for PHP 8.2 (`config.platform.php`), so CI installs and caches a fixed set of dev dependencies.

### Fixed
- PostgreSQL: `insert()` inside a transaction on a table without a `{table}_id_seq` sequence (composite or UUID key, or an explicit `id` before the sequence was used in the session; after an earlier sequence-based insert on the same connection an explicit-id insert still returns that earlier value) aborted the transaction; the following `COMMIT` silently became a `ROLLBACK` and nothing was stored. The sequence probe now runs in a savepoint; a probe that PDO reports as `false` (non-exception error mode) is handled the same way, and a failing savepoint statement throws a `QueryException`.
- QueryBuilder: `update()` and `delete()` silently ignored `join()` (all variants), `groupBy()` and `having()`, so a delete narrowed down by a join hit every matching row of the base table. They now throw a `QueryException`, as for `limit()` and `orderBy()`. Compatibility note: the guard also rejects combinations that happen to be row-neutral, such as `groupBy()` on the primary key.
- A throwing `transaction.begin` hook left the transaction it was told about open. A rollback is now attempted directly (best effort, without `transaction.rollback` hooks); the hook's exception reaches the caller as before.
- `beginTransaction()` and `rollback()` ignored `PDO::beginTransaction()` / `PDO::rollBack()` returning `false` (non-exception error mode) and ran their hooks; they now throw `TransactionException`, as `commit()` does since 1.1.0.

## [1.1.0] - 2026-09-30

### Added
- `CommitHookException`: the transaction is committed, but a `transaction.commit` hook failed or the connection state could not be verified or cleaned up after a hook. `getPrevious()` is the first failure, `$failures` lists all of them.

### Changed
- All `transaction.commit` hooks run, even if one throws, and their failures arrive together as `CommitHookException`. Hooks are independent: dependent steps belong in one hook.
- A failure after the commit no longer causes a rollback attempt. A transaction left open by a commit hook is now reported as a failure (`CommitHookException`, even if no hook threw; 1.0 returned normally) and rolled back directly, without `transaction.rollback` hooks; if that fails (or the connection state cannot be read), the remaining commit hooks are skipped.
- Migration: a failing commit hook's exception no longer surfaces unwrapped; catch `CommitHookException` and read `getPrevious()`. It extends `DatabaseException`, not `TransactionException`; catch it before a broad `catch (DatabaseException)`.

### Fixed
- `transaction()` and `updateMultiple()` attempted a rollback after a successful commit when a commit hook threw (firing `transaction.rollback` if the hook had left its own transaction open).
- A `PDOException` thrown by a commit hook was reported as "Failed to commit transaction".
- `commit()` ignored `PDO::commit()` returning `false` (non-exception error mode) and ran the commit hooks; it now throws `TransactionException`.

## [1.0.1] - 2026-06-01

### Changed
- CI: jobs run in `shivammathur/node` containers; `actions/checkout` and `actions/cache` updated. No library changes.

## [1.0.0] - 2026-03-15

### Added
- **Connection layer** with MySQL, MariaDB, PostgreSQL, and SQLite drivers.
  - Configuration via array or environment variables (`$_ENV`, `getenv()`).
  - PDO options with sensible defaults (exceptions, associative fetch, real prepared statements).
- **Query execution** with `query()` and `execute()` methods.
  - Prepared statements with parameter binding.
  - Query duration tracking.
- **Transaction support** with `beginTransaction()`, `commit()`, `rollback()`.
  - `transaction()` helper with auto-commit/rollback.
  - Rollback failures do not mask the original exception.
- **Event hooks** for query logging and error handling.
  - Events: `query`, `error`, `transaction.begin`, `transaction.commit`, `transaction.rollback`.
- **CRUD helper methods**:
  - `insert()` - Insert row and return last insert ID.
  - `update()` - Update rows with WHERE conditions (safety check).
  - `delete()` - Delete rows with WHERE conditions (safety check).
  - `findOne()` - Find single row by conditions.
  - `findAll()` - Find all rows matching conditions.
  - `updateMultiple()` - Batch update by key column within a transaction.
- **Fluent QueryBuilder**:
  - `select()`, `distinct()`
  - `where()`, `whereIn()`, `whereNotIn()`, `whereBetween()`, `whereNotBetween()`
  - `whereNull()`, `whereNotNull()`, `whereLike()`, `whereNotLike()`
  - `join()`, `leftJoin()`, `rightJoin()`
  - `orderBy()`, `limit()`, `offset()`
  - `groupBy()`, `having()`
  - `get()`, `first()`, `exists()`
  - `count()`, `sum()`, `avg()`, `min()`, `max()`
  - `insert()`, `update()`, `delete()`
  - `toSql()` for debugging.
- **`Database::raw()`** for explicit raw SQL expressions (aggregates, complex queries).
- **Security**:
  - Operator whitelist validation.
  - Identifier quoting with proper escaping.
  - Safety checks preventing UPDATE/DELETE without WHERE.
- **Exception hierarchy**: `DatabaseException`, `ConnectionException`, `QueryException`, `TransactionException`.
- **PostgreSQL**: `insert()` reads the new ID from the `{table}_id_seq` sequence and returns 0 for tables without one (composite or UUID keys).
- **SQLite**: Foreign key constraints enabled by default.
- **CI**: GitHub Actions with PHP 8.2-8.5, MySQL 8.0/8.4, MariaDB 10.11/11.4, PostgreSQL 15/16/17.
- **Quality**: PHPStan level 9, PHP-CS-Fixer (PSR-12).

[Unreleased]: https://github.com/sodaho/pdo-wrapper/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/sodaho/pdo-wrapper/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/sodaho/pdo-wrapper/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/sodaho/pdo-wrapper/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/sodaho/pdo-wrapper/releases/tag/v1.0.0
