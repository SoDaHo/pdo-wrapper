# Changelog

## [3.2.0] - 2026-10-10

### Added
- `ImplicitCommitException` and `LockOutsideTransactionException` (both `QueryException`).
- `ListenerTransactionException` (a `TransactionException`), `TransactionOpenException` (a `ConnectionException`).
- Option `redactParameters`: no bound value in payloads, debug messages, previous exceptions (`RedactedPdoException`).
- `AbstractDriver::implicitCommitOf(string $sql): ?string`; `MariaDbDriver` answers with MariaDB's statements.
- `reconnect()` takes `bool $dropTransaction = false`; `DatabaseException::__construct()` `bool $codesOfPrevious`.

### Changed
- `DatabaseInterface` declares the whole API (`insertWhen()`, `updateMultiple()`, ...); `InternalMethods` is gone.
- In a library transaction a statement that commits implicitly throws `ImplicitCommitException`, nothing is sent.
- Refused there unjudged: an executable comment, a byte from 0x80 on or a control character before the leading keywords.
- `CREATE [OR REPLACE] TEMPORARY SEQUENCE` counts as committing implicitly; temporary tables do not.
- A locking read (`lockForUpdate()`, `sharedLock()`) outside a transaction throws `LockOutsideTransactionException`.
- `reconnect()` while a library transaction is open throws `TransactionOpenException`, unless `dropTransaction`.
- Transaction control inside listeners follows one rule (`Traits\HasHooks`), else `ListenerTransactionException`.
- `transaction.end` listeners that keep beginning transactions are stopped after 32 levels (`LogicException`).
- The builder's `insert()` throws for a where, join, group, order, limit, offset, distinct or lock set before it.
- A key with a dot in a column => value array throws, so do such a `$keyColumn` and an `increment()` column.
- `options` with `ATTR_MULTI_STATEMENTS` switched on, or with `ATTR_STATEMENT_CLASS`, throw `ConnectionException`.
- A config key other than the nine of `MariaDbDriver` throws `ConnectionException`; `driver` belongs to `connect()`.
- A config value of the wrong type throws `ConnectionException` (was a `TypeError` or a cast).
- A `where()` operator that is no string throws `QueryException`.
- `updateMultiple()` checks every row before it sends one; a refused row writes nothing and fires no hook.
- `updateMultiple()` is refused in a library transaction that ended behind its back, or when PDO cannot tell its state.
- Named-lock methods throw for an answer other than the server's (`1`, `0`, `-1`, `NULL`); `namedLock()` counts it.
- `sum()` and `avg()` throw for a string that is no number.
- `ext-mbstring` is required: `select()` aliases are compared with `mb_strtolower()`.
- `CommitFailedException::settle()` is private.

### Fixed
- `where([...])` with a refused entry adds nothing; it kept the entries before it.
- Aliases with letters beyond ASCII in a grouped `count()`, in `distinct()->count()` and in `Database::json()->as()`.
- `having('COUNT(*)')` works with a `select()` entry `Database::raw('COUNT(*)')` (regression of 3.1.2).
- Error 2014 (unbuffered result open) no longer ends the transaction (regression of 3.1.2).
- `rollback()` ends a manual transaction a DDL statement or raw PDO ended, as `lost` (regression of 3.1.2).

### Security
- Every parameter that takes a value is `#[\SensitiveParameter]`, pinned by a reflection test over `src/`.
- Debug messages show no refused float step of `increment()` and no table name of `schema()`.
- With `redactParameters`: no lock timeout, insert id, `INF`/`NAN` in messages; listeners' `PDOException`s replaced.

### Upgrading from 3.1.2
- Send statements that commit implicitly outside of library transactions; put locking reads into `transaction()`.
- Move transaction control out of listeners; end the transaction before `reconnect()` or pass `dropTransaction: true`.
- Drop unknown config keys, `ATTR_MULTI_STATEMENTS` and `ATTR_STATEMENT_CLASS`; pass config values of the right type.
- Use plain column names as keys and strings as operators; start the builder's `insert()` from a fresh `table()`.
- A class implementing `DatabaseInterface` adds the new methods; a `reconnect()` override takes `$dropTransaction`.
- A helper that opens a transaction asks `currentTransaction() !== null || inTransaction()` first.

## [3.1.2] - 2026-10-09

### Fixed
- After a failed statement in a library transaction the driver asks the server; gone or unknown: nothing more is sent.
- Nothing is sent while PDO reports no transaction for one the library began; its end is `lost`.
- A state that cannot be read right after `COMMIT` or `ROLLBACK` is reported like a chained transaction.
- `query.before`, `query` and `error` listeners that recurse are stopped after 32 levels (`LogicException`).
- Builder aliases: a line break after `as`, letters beyond ASCII; a `where*()` column `a as b` is one name.
- An integer key in a column => value array throws `QueryException` (was a `TypeError`).
- `UniqueViolationException::$constraint` for a key name with a quote.
- `fromEnv()`: an empty value in `$_ENV` lets the process environment answer.
- `count()` throws for a value that is no number.

### Changed
- `host`, `database` and `username` given as `''` count as missing.
- Named-lock methods throw when the configured database name holds a `:`.
- One statement (`DO 1`) after each failed statement inside a library transaction; none after a deadlock or 1020.

## [3.1.1] - 2026-10-06

### Fixed
- The driver sets `completion_type` to `NO_CHAIN` when it connects and at `reconnect()`.
- A failed `COMMIT` on a session that may chain transactions ends as `lost`, not `rolled_back`.
- A `COMMIT` the server answers with an error and ends (a deadlock at commit) is `lost`, not `rolled_back`.

## [3.1.0] - 2026-10-06

### Added
- On `DatabaseInterface`: named locks, `namedLockHolder()`, `heldNamedLocks()`, `schema()`, `currentTransaction()`.
- Event `query.before`; `ConnectionException::$refusal` (`ConnectionRefusal`); `AbstractDriver::namedLockPrefix()`.

### Changed
- `reconnect()` throws `NamedLocksHeldException` while named locks are held, unless `dropNamedLocks: true`.
- Named-lock statements go through `AbstractDriver::query()`; `queryThen()` hands the step the statement and PDO.
- A class implementing `DatabaseInterface` adds the new methods; a `reconnect()` override takes `$dropNamedLocks`.

## [3.0.0] - 2026-10-05

### Removed
- The SQLite and PostgreSQL drivers and the builder's dialect switch; a MySQL server is refused.

### Changed
- The driver is `MariaDbDriver` (`mariadb`); MariaDB 10.11 or later and mysqlnd are checked when it connects.
- Fetched types are pinned: `ATTR_STRINGIFY_FETCHES` off, `ATTR_ORACLE_NULLS` `NULL_NATURAL`.
- Error 1020 ends the transaction like a deadlock.
- `limit()` on the builder's `update()` and `delete()` needs `orderBy()`; `whereIn([])` matches no row.
- A float is bound as the shortest text that reads back as the same float; `INF` and `NAN` throw.
- `insertIgnore()`, `upsert()` and `insertWhen()` with an update throw on a persistent connection.
- `sum()` and `avg()` return `float|string|null`; aggregates keep a row lock.

### Added
- `Database::json()`, `Database::value()`, `upsert()`, `upsertReturning()`, `insertWhenReturning()`.
- `schema()`, named locks, `off()`, `reconnect()`, `increment()`, `decrement()`, expressions in `orderBy()`.

### Upgrading from 2.x
- `Database::mysql()`/`MySqlDriver` are `Database::mariadb()`/`MariaDbDriver`; SQLite and PostgreSQL are gone.
- `->limit(n)->update()` and `->limit(n)->delete()` need `->orderBy(...)`.
- `new QueryBuilder($db, $table, ...)` is `$db->table($table)`.

Older versions: see the git tags.

[3.2.0]: https://github.com/sodaho/pdo-wrapper/compare/v3.1.2...v3.2.0
[3.1.2]: https://github.com/sodaho/pdo-wrapper/compare/v3.1.1...v3.1.2
[3.1.1]: https://github.com/sodaho/pdo-wrapper/compare/v3.1.0...v3.1.1
[3.1.0]: https://github.com/sodaho/pdo-wrapper/compare/v3.0.0...v3.1.0
[3.0.0]: https://github.com/sodaho/pdo-wrapper/compare/v2.1.0...v3.0.0
