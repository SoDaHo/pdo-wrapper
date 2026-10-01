# Changelog

## [Unreleased]

### Added
- `update()->limit(n)` on the query builder for MySQL/MariaDB, like `delete()->limit(n)` since 1.3.0: `UPDATE ... SET ... WHERE ... [ORDER BY ...] LIMIT n`, `orderBy()` allowed with it (order by a unique key so the batch is deterministic). On PostgreSQL, SQLite and ANSI `limit()` on `update()` still throws, with the same hint as for `delete()`.
- `transaction.end` hook: fires exactly once for every transaction this library ends, after the `transaction.commit` or `transaction.rollback` listeners, with `['outcome' => 'committed'|'rolled_back'|'lost', 'error' => ?Throwable]` (`DatabaseInterface::TRANSACTION_COMMITTED`, `TRANSACTION_ROLLED_BACK`, `TRANSACTION_LOST`). `lost` covers what the rollback listeners cannot: no rollback could be confirmed (lost connection, PDO no longer reporting the transaction, an unreadable connection state, a raw cleanup of a commit listener's transaction that did not end it, or a failed commit followed by a failed rollback, where the data may be committed, fail-closed, and `error` is the commit's exception); listeners must not expect queries to work then, and a transaction that may in fact still be open tells no second end when it is later committed or rolled back through the library. All listeners run; their failures arrive in `CommitHookException::$failures` after a commit (behind the commit listeners': first the ends of transactions commit listeners left open, then the committed transaction's end), as `TransactionException` after an explicit `rollback()` (a rollback listener's exception takes precedence), and only via the `error` hook (keys `hook`, `outcome`, `exception` added) on the automatic rollback in `transaction()`/`updateMultiple()` and on a `lost` reported there, so the exception that ended the transaction reaches the caller unchanged. A failing explicit `commit()`/`rollback()` fires nothing. A transaction a commit listener began through the library and leaves open gets its own `transaction.end` before the outer one. See the `HasHooks` header for the full contract, including transactions started inside listeners.

### Changed
- The message of `CommitHookException` now names both hooks: `Transaction committed, but a transaction.commit or transaction.end hook failed or the connection state after a commit hook could not be verified`.

## [1.3.1] - 2026-10-01

### Changed
- `orderBy()` throws a `QueryException` for a direction other than `ASC`/`DESC` (`'DESCENDING'`, `'down'`, `'DESC NULLS LAST'`) on every statement; before, a select silently sorted ascending and only `delete()->limit()` refused it. Case and surrounding whitespace are still tolerated. A direction taken from request input must be mapped to one of the two first.

### Fixed
- `false` is bound as `'0'` on MySQL/MariaDB and PostgreSQL (`true` was already `'1'`; SQLite binds booleans as `0`/`1` since 1.2). PDO sent it as `''`, which MySQL in strict mode rejected for a numeric column on insert and update and PostgreSQL rejected for a boolean or integer column everywhere. Wherever `''` was accepted before (a text or binary column), `'0'` is stored and compared now; rows that earlier versions wrote there with `false` hold `''` and no longer match `where('col', false)`, so keep passing `''` for them or convert them once (`UPDATE t SET col = '0' WHERE col = ''`, only on columns that hold booleans). A MySQL `BIT` column does not store a bound `0`/`1` as bits (`false` only landed as 0 there by accident): use `Database::raw('0')` or a `TINYINT(1)` column. A custom driver that inherits `bindAndExecute()` from `AbstractDriver` gets the same conversion; raw PDO use (`getPdo()`, re-executing a returned statement) is not converted, and the `query` and `error` hooks get the parameters as passed.

## [1.3.0] - 2026-10-01

### Added
- `delete()->limit(n)` on the query builder for MySQL/MariaDB: `DELETE ... [ORDER BY ...] LIMIT n`, `orderBy()` allowed with it (delete the oldest n rows, in batches; order by a unique key so the batch is deterministic). Before, `limit()` on `delete()` threw on every dialect; it still throws on PostgreSQL, SQLite and ANSI, where `DELETE ... LIMIT` is not portable, instead of silently deleting every matching row. An `orderBy()` direction other than `ASC`/`DESC` (`'DESCENDING'`, `'down'`) throws there too, because it decides which rows go (for selects it still falls back to `ASC`). A custom driver whose quote character is the backtick renders as MySQL unless it overrides `getDialect()`. `offset()`, `join()`, `groupBy()`, `having()` and an `orderBy()` without `limit()` still throw; `update()` is unchanged. `select()`, `distinct()` and a row lock on `update()`/`delete()` are ignored, as before (they cannot change which rows the statement hits).
- `insertWhen($table, $row, $condition, $bindings = [])` on every driver and in `DatabaseInterface`, `insertWhen($row, $condition, $bindings = [])` on the query builder: insert a row only when a trusted condition holds, in one statement (`INSERT INTO t (...) SELECT ?, ... WHERE (condition)`, with `FROM DUAL` on MySQL/MariaDB). Check and insert see one snapshot; concurrent statements can still both insert, so an invariant needs a `UNIQUE` constraint, a row lock or `SERIALIZABLE`. The condition may look at the target table (`NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL)`); the row's values are bound first, then the condition's bindings. Returns the inserted rows, 1 or 0 (`lastInsertId()` is meaningless after 0). Never build the condition from user input. On the builder, clauses set before `insertWhen()` throw (they are not part of the statement). A direct implementation of `DatabaseInterface` must add the method; a custom backtick driver renders `FROM DUAL` unless it overrides `getDialect()`.

### Fixed
- `orderBy()` tolerates surrounding whitespace and any case in the direction (`' desc'`, `'Desc '` sort descending; before, they silently fell back to `ASC`).
- Documented when `transaction.rollback` listeners run after a server-side error, measured on MySQL 8.0 and MariaDB 11.4 with mysqlnd: after a deadlock (1213, the server rolled the transaction back) and after a lock wait timeout (1205, the server rolled back only the statement) PDO still reports the transaction, `transaction()` rolls back and the listeners run; after a lost connection (2006/2013) the rollback fails, no listener runs, and PDO still reported the transaction.

## [1.2.0] - 2026-10-01

### Added
- `now()` and `utcNow()` on every driver and in `DatabaseInterface`: the database's current local or UTC timestamp at statement time, to the second, as a raw expression in the driver's dialect (MySQL `NOW()`/`UTC_TIMESTAMP()`, PostgreSQL `CAST(statement_timestamp() AS TIMESTAMP(0))`/`CAST(statement_timestamp() AT TIME ZONE 'UTC' AS TIMESTAMP(0))`, SQLite `datetime('now', 'localtime')`/`datetime('now')`), for use as a value in `insert()`, `update()` and `where()` (#1). Both are zoneless values: use them with `DATETIME`/`TIMESTAMP WITHOUT TIME ZONE`/`TEXT` columns; a zone-aware column (PostgreSQL `TIMESTAMPTZ`, MySQL `TIMESTAMP`) interprets `utcNow()` in the session's time zone. A direct implementation of `DatabaseInterface` must add `now()`, `utcNow()` and `inTransaction()`; drivers extending `AbstractDriver` inherit them, but its `now()`/`utcNow()` defaults render `CURRENT_TIMESTAMP`, so a custom driver should override them for its database.

- `whereRaw(string $sql, array $bindings = [])` on the query builder: a trusted SQL condition with its values bound in order, for what the other `where*()` methods cannot express (an expression on the left, an OR group, a database function); joined with AND, in parentheses. Never build the SQL from user input; a `RawExpression` is not accepted as a binding.
- `Database::connect(array $config = [])`: picks the driver from `$config['driver']` or `DB_DRIVER` (`mysql`/`mariadb`, `pgsql`/`postgres`/`postgresql`, `sqlite`) and delegates to `mysql()`, `postgres()` or `sqlite()` with the same keys and environment fallbacks; the SQLite path comes from `path`, else `database`, else `DB_SQLITE_PATH`. A missing or unknown driver throws a `ConnectionException`.
- `inTransaction()` on every driver and in `DatabaseInterface` (no more `getPdo()->inTransaction()`).
- `CommitHookException::$connectionInTransaction` (optional third constructor argument, default `false`): `true` when the connection is, or may still be, in a transaction after the `transaction.commit` hooks ran, because a transaction left open by a hook could not be rolled back (the rollback failed, or the connection was still in a transaction afterwards: MySQL `completion_type=CHAIN`) or the connection state could not be read (fail-closed: an unreadable state counts as `true`). Do not run further statements on that connection as if it were in autocommit: check `inTransaction()` and roll back, or discard the connection. An exception serialized by an earlier version does not carry the property.
- `lockForUpdate()` and `sharedLock()` on the query builder: `FOR UPDATE` / `LOCK IN SHARE MODE` on MySQL/MariaDB, `FOR UPDATE` / `FOR SHARE` on PostgreSQL, omitted on SQLite (no row locks). `get()`, `first()` and `exists()` render the lock, the aggregates (`count()`, `sum()`, …) drop it; in `get()`, `first()` and `exists()` a lock combined with `distinct()`, `groupBy()` or `having()` throws a `QueryException`.
- The query builder knows its SQL dialect (`QueryBuilder::DIALECT_*`, passed by the driver; an optional fourth constructor argument, derived from the quote character when omitted). Custom drivers: `getQuoteChar()` now also quotes the driver-level `insert()`/`update()`/`delete()` statements, and a backtick driver renders as MySQL (`<=>` for a bound `IS`, no `LIKE` escape clause) unless it overrides `getDialect()`.

### Changed
- A `Database::raw()` expression given as a **value** is inlined into the SQL instead of being bound as a string: in the drivers' `insert()`, `update()`, `updateMultiple()`, `delete()`, `findOne()` and `findAll()` and in the query builder's `where()`, `whereIn()`/`whereNotIn()`, `whereBetween()`/`whereNotBetween()`, `having()`, `update()` and `delete()`. Before, such a value was stored or compared literally as text (use a string for a literal); `raw()` in `select()` lists is unchanged. In `where()` the automatic `ESCAPE '\'` clause of `LIKE` on PostgreSQL and SQLite applies to raw patterns too (`having()` has no automatic escape clause). As with every raw expression: never build it from user input.
- `exists()` runs `SELECT 1 ... LIMIT 1` instead of `COUNT(*) > 0`. It keeps a requested row lock and an `offset()` (`offset(50)->exists()` answers "is there a next page?"; before, the offset was ignored). With `distinct()` the selected columns stay in the query, so an aggregate projection (`select([Database::raw('COUNT(*) AS n')])->distinct()`) yields a row even over an empty table; with `groupBy()` the aliased `select()` entries stay (a trailing `AS alias`, bare or quoted), so `having()` may refer to them. With `having()` but no `groupBy()` it is still evaluated as `count() > 0`, which ignores the offset.

### Fixed
- `min()` and `max()` document that they return the driver's native value (PostgreSQL returns numeric and date/time values as strings).
- SQLite quotes identifiers with backticks instead of double quotes. SQLite reads an unknown double-quoted name as a string literal, so a mistyped column name compared or sorted by a constant instead of failing; it now fails with `no such column`, as on MySQL and PostgreSQL. Visible in `toSql()` and in the SQL passed to `query` hooks on SQLite: tests that assert exact SQLite SQL strings need updating, and queries that silently used an unknown identifier now throw.
- SQLite binds integers as integers and booleans as `0`/`1` (PDO binds everything as text; `HAVING COUNT(*) > ?` with a text `1` was always false on SQLite and `false` was stored as `''`). Existing SQLite data written by earlier versions may need a one-time conversion: see README › SQLite › Upgrading to 1.2.
- `count()` ignored `distinct()` (`SELECT DISTINCT COUNT(*)` counted all rows) and `groupBy()` (the first group's count was returned). It now counts the distinct rows (`COUNT(DISTINCT col)` for a column) or the groups, `having()` included; `sum()`/`avg()`/`min()`/`max()` respect `distinct()` and throw a `QueryException` with `groupBy()` instead of returning one group's value. `distinct()->count()` throws a `QueryException` when the `select()` would repeat an output name in the counted derived table (two columns named alike, a wildcard next to other entries, a bare `*` over a join; alias the columns; raw entries are not inspected), on every driver: MySQL and MariaDB would reject the query, `count('column')` works regardless. With `groupBy()`, only aliased `select()` entries stay in the counted query (a trailing `AS alias`; in a `raw()` entry the name may also be double-quoted or backtick-quoted), one per alias, so `having()` and `groupBy()` may refer to them; other entries are dropped. `having()` without `groupBy()` treats the whole result as one group: `count()` is its row count, and `distinct()` only applies to `count('column')` then.
- MySQL and PostgreSQL connections reject a `;` or NUL in `host`, `database` and (MySQL) `charset` with a `ConnectionException` before connecting, and PostgreSQL values are quoted the libpq way: a `;` appended further DSN keys, a space inside a PostgreSQL value started another libpq parameter, and both could redirect the connection, credentials included, to another host.
- A `PDOException` thrown by a `query` hook was reported as a failed query (`Query failed`, `error` hook fired) although the statement had run. It now arrives as `QueryException` with the message `Query hook failed` and without the `error` hook; other exceptions from `query` hooks reach the caller unchanged, as before.
- `query()` ignored `PDO::prepare()` / `PDOStatement::execute()` returning `false` (non-exception error mode) and returned the statement as if it had run; it now throws `QueryException` (`Query failed`) and fires the `error` hook, as for exceptions.
- The operators `IS` and `IS NOT` produced invalid SQL on MySQL/MariaDB and PostgreSQL with a bound value. With a bound value they now mean null-safe equality and are rendered per dialect (SQLite `IS`, MySQL `<=>`, PostgreSQL `IS NOT DISTINCT FROM`), in `where()`, `having()` and joins; with a raw value (`Database::raw('TRUE')`, `raw('NULL')`) the SQL is passed through unchanged (before this release a raw value was bound as text there too, see **Changed**).
- `where(column: 'id', value: 5)` with named arguments (no operator) means equality instead of throwing.
- `offset()` without `limit()` produced invalid SQL on MySQL/MariaDB and SQLite; the builder now adds the "no limit" value those databases require.
- `where()` with two arguments treated a value that spells an operator (`'IS'` for Iceland, `'LIKE'`) as an operator and threw; the argument count now decides the form. With three arguments an invalid operator is reported as such (before, `where('name', 'A', null)` silently meant `name = 'A'`); a `null` value still throws.
- `select(['users.*'])` quoted the wildcard (`"users"."*"`, "no such column"); it is now `"users".*`.
- QueryBuilder `update([])` produced invalid SQL; it now throws "Cannot update with empty data" like the driver's `update()`.
- `whereIn()`, `whereNotIn()`, `whereBetween()` and `whereNotBetween()` with string-keyed arrays (e.g. `['min' => 1, 'max' => 5]`): the keys were renumbered or read as index 0/1, so `whereBetween()` silently matched nothing and `whereIn()` could bind the wrong values.

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

[Unreleased]: https://github.com/sodaho/pdo-wrapper/compare/v1.3.1...HEAD
[1.3.1]: https://github.com/sodaho/pdo-wrapper/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/sodaho/pdo-wrapper/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/sodaho/pdo-wrapper/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/sodaho/pdo-wrapper/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/sodaho/pdo-wrapper/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/sodaho/pdo-wrapper/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/sodaho/pdo-wrapper/releases/tag/v1.0.0
