# Changelog

## [Unreleased]

Work on 2.0 (branch `2.x`). What breaks is collected under "Upgrading from 1.x" as it is built.

### Added
- `Database::fromEnv(array $overrides = [])`: creates the connection from the environment and is the one place in the library that reads it - `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and, for SQLite, `DB_SQLITE_PATH`, from `$_ENV` first, then `getenv()`. A key in `$overrides` (the keys of `connect()`) counts instead of its variable, also with `null` or an empty value. The SQLite file never comes from `DB_DATABASE`.

### Changed
- PHP 8.5 is required (`"php": "^8.5"`, 1.x: `^8.2`). The test suite fails on deprecations and notices.
- The query builder quotes aliases: `'name as UserName'` renders `"name" as "UserName"` (backticks on MySQL/MariaDB and SQLite), for a column and for a table (`table('users as U')`, `join('posts as P', ...)`). The alias is then the same name wherever the builder refers to it - `orderBy('UserName')`, `groupBy('UserName')` and `'U.name'` are rendered quoted and did not find a bare alias with an upper-case letter on PostgreSQL -, and a reserved word is a valid alias (`'title as order'`). `distinct()->count()` on PostgreSQL treats two aliases that differ only in case as two names.
- `transaction()` and `updateMultiple()` end only the transaction they began. Once the callback or a listener has ended it through the library (`commit()` or `rollback()`), a transaction that is open afterwards - one an end listener began in response, a second one of the callback, one begun on raw PDO - is no longer committed when the callback returns or rolled back when it throws: it was never theirs. The callback that returns gets a `CommitFailedException` with outcome `lost`, without a `COMMIT` being sent; the one that throws gets its exception back. Before, the foreign transaction was committed or rolled back in the name of the one that was over, and `rolled_back` could stand at an exception although the callback's own commit had gone through. A driver whose `beginTransaction()` override does not call the parent is treated as before. Not told apart: a transaction ended and begun again on raw PDO alone.
- `beginTransaction()` tells the end of a transaction that ended behind the library's back (`lost`) and now looks again afterwards: a transaction an end listener of that `lost` began and lost the same way is told too instead of being buried under the new one. A third in a row throws a `TransactionException`, nothing is begun, and the next call tells that end.
- `beginTransaction()` throws a `TransactionException` when a `transaction.begin` listener has ended the transaction it was told about - with a `commit()` or `rollback()` through the library, or behind its back (an implicit commit by a DDL statement on MySQL/MariaDB, raw PDO; that end is then told as `lost`). It is checked after every listener, and no further one runs: the caller - the callback of `transaction()`, the batch of `updateMultiple()` - and the remaining listeners would go on in autocommit, or inside a transaction somebody began afterwards. Before, they did exactly that. After a throwing `transaction.begin` listener only the transaction just begun is rolled back, not one the listener began itself; if that listener ended the transaction behind the library's back before it threw, the end is told as `lost` as well. The `transaction.begin` listeners are called by `beginTransaction()` itself and no longer through `trigger()`, like the commit and end listeners before: a custom driver that overrides `trigger()` does not see the event.
- `Database::mysql()`, `postgres()`, `sqlite()` and `connect()` no longer read the environment: they use what they are given and nothing else. The config array of `mysql()`, `postgres()` and `connect()` is a required argument, and `sqlite()` takes a string (default `:memory:`) and no `null`. A required value that was not passed throws a `ConnectionException`, whatever the environment says.
- `new QueryBuilder(...)` with an unknown dialect throws a `QueryException` (`Query failed`, the name in `getDebugMessage()`) instead of an `\InvalidArgumentException` with the name in its message. With that, no exception message of the library names a value: values stand in the debug message, in the previous exception and in the hooks only.

### Fixed
- MySQL/MariaDB: a statement with an implicit commit (most DDL) commits the open transaction even when it fails itself (`CREATE TABLE` for a table that exists), and the server's error does not tell the client, so PDO kept reporting the transaction. When that failure left the callback of `transaction()` right away - or `rollback()` was called after it -, the `ROLLBACK` went through over nothing and `transaction.end` reported `rolled_back` although the rows written before the statement were committed. Before a `ROLLBACK` that follows a failed statement the driver now asks the server (one no-op statement on raw PDO, no hook sees it); when the transaction is gone, nothing is sent, no `transaction.rollback` listener runs and `transaction.end` reports `lost`. When the question itself fails while the connection goes on working (a proxy that rejects the statement), the `ROLLBACK` is sent to clean up, but it confirms nothing: `lost` as well. A failed statement that cost only itself still ends in `rolled_back`; after a deadlock nothing changes. A lock wait timeout that ended the transaction (`innodb_rollback_on_timeout`) and leaves the callback right away is told as `lost` now as well, without `transaction.rollback` listeners - as it already was when the callback swallowed it and the commit was refused. When PDO reports no transaction at the moment of the `rollback()`, nothing is asked and nothing changes. A custom driver can do the same by overriding the new protected `refreshTransactionState()` (a driver that already has a method of that name has to rename it). Not covered: with autocommit switched off, a statement sent after the failed DDL statement opens the next transaction, and the question finds that one in its place.

### Upgrading from 1.x
- **PHP version:** `"php": "^8.2"` (1.x) becomes `"php": "^8.5"`. Stay on `^1.6` until the application runs on PHP 8.5.
- **Connections from the environment:** the factories no longer fill in `DB_*` values; `Database::fromEnv()` does. Most old calls fail loudly on 2.0 (an `ArgumentCountError` without the config array, a `TypeError` for `sqlite(null)`, a `ConnectionException` "Missing required config" for a value that used to come from the environment) - **except SQLite, which changes silently: `Database::sqlite()` and `Database::connect(['driver' => 'sqlite'])` used to open the file named in `DB_SQLITE_PATH` and now open an in-memory database, which works and forgets everything when the connection closes.** Search for both calls before upgrading.

  | 1.x | 2.0 |
  |---|---|
  | `Database::connect()` | `Database::fromEnv()` |
  | `Database::mysql()` | `Database::fromEnv(['driver' => 'mysql'])` |
  | `Database::postgres()` | `Database::fromEnv(['driver' => 'pgsql'])` |
  | `Database::mysql(['password' => $secret])` (the rest from `DB_*`) | `Database::fromEnv(['driver' => 'mysql', 'password' => $secret])` |
  | `Database::connect(['driver' => 'mysql'])` (the rest from `DB_*`) | `Database::fromEnv(['driver' => 'mysql'])` |
  | `Database::sqlite()` with `DB_SQLITE_PATH` set | `Database::fromEnv(['driver' => 'sqlite'])` |
  | `Database::connect(['driver' => 'sqlite'])` with `DB_SQLITE_PATH` set | `Database::fromEnv(['driver' => 'sqlite'])` |
  | `Database::sqlite(null)` | `Database::sqlite()` (in memory) or `Database::fromEnv(['driver' => 'sqlite'])` |

  Also not filled in any more: a `password` or `port` that came from `DB_PASSWORD`/`DB_PORT` next to an otherwise complete config array - the connection is then tried without a password and on the driver's default port. And one difference to the old fallback: a key passed to `fromEnv()` wins over its variable also when it is `null` (in 1.x a `null` in the config fell back to the environment).
- **Transactions - `transaction()` and `updateMultiple()` end only their own:**

  | What the code does | 1.x | 2.0 |
  |---|---|---|
  | callback: `$db->commit(); $db->beginTransaction(); ...` and returns | the second transaction was committed | `CommitFailedException` (`lost`), the second transaction stays open |
  | callback: `$db->rollback(); $db->beginTransaction(); ...` and throws | the second transaction was rolled back | the callback's exception, the second transaction stays open |
  | a `transaction.end` or `error` listener begins a transaction after the callback's own `rollback()` | committed or rolled back with the callback | left open: the listener ends it itself |
  | a `query` or `error` listener ends the transaction of `updateMultiple()` while the batch runs | what was open afterwards was committed or rolled back | `CommitFailedException` (`lost`) or the batch's exception; what is open is left alone |
  | a `transaction.begin` listener ends the transaction it was told about (`commit()`, `rollback()`, a DDL statement on MySQL/MariaDB, raw PDO) | the remaining begin listeners and the callback ran in autocommit, or inside a transaction begun afterwards, which was then committed | `TransactionException` from `beginTransaction()`; no further begin listener and no callback runs; an end behind the library's back is told as `lost` |
  | a `transaction.begin` listener ends the transaction on raw PDO and throws | nothing was told | `transaction.end` reports `lost`, with the exception the caller gets |
  | a `transaction.begin` listener ends the transaction, begins another and throws | the second transaction was rolled back, without an event | the listener's exception; the second transaction stays open and tells its end when it is ended |
  | `beginTransaction()` after a transaction ended behind the library's back, and an end listener leaves another such transaction behind | the second one's end was never told | told as `lost` as well; with a third in a row `beginTransaction()` throws and begins nothing |

  A connection left in a transaction makes the next `beginTransaction()` fail. `transaction()` is for one transaction: call it once per transaction, or run several yourself with `beginTransaction()` and `commit()`.
- **Aliases:** the rendered SQL changes on every database (`toSql()`, the `query` hook): `` `name` as `total` `` instead of `` `name` as total ``. Results change on PostgreSQL only, for a string alias with an upper-case letter:
  - the result key keeps its case: `select(['name as UserName'])` returned rows with the key `username` on 1.x (PostgreSQL folds a bare alias) and returns `UserName` on 2.0, as MySQL/MariaDB and SQLite always did;
  - a reference must be written like the alias: `table('users as U')->where('u.id', 1)` and `join('posts as P', 'p.user_id', ...)` worked on 1.x and fail on 2.0 (`missing FROM-clause entry`) - write `'U.id'`, `'P.user_id'`;
  - `select(['display_name as Username'])->orderBy('username')` sorted by the alias on 1.x. On 2.0 `"username"` is no longer that alias: the statement fails, or - if the table has a column `username` - sorts by that column without a word. Write `orderBy('Username')`.

  Or write such aliases in lower case and keep the 1.x behaviour. Nothing else changes for lower-case aliases or for an alias inside `Database::raw()`.
- **Unknown dialect:** `catch (\InvalidArgumentException $e)` around `new QueryBuilder($db, $table, $quoteChar, $dialect)` becomes `catch (QueryException $e)`; the dialect's name moved from `getMessage()` to `getDebugMessage()`.
- **Custom drivers that override `trigger()`:** `transaction.begin` no longer passes through it (`transaction.commit` and `transaction.end` never did). Register a listener with `on()` instead.
- **MySQL driver options:** write `Pdo\Mysql::ATTR_MULTI_STATEMENTS` / `Pdo\Mysql::ATTR_FOUND_ROWS` in `options`; the `PDO::MYSQL_ATTR_*` constants are deprecated in PHP 8.5 (both names are the same number, so old code keeps working, with a deprecation notice from PHP).

## [1.6.0] - 2026-10-02

### Added
- `CommitFailedException` (a `TransactionException`): what `commit()` throws when the commit itself fails or is refused, so that "the commit failed" can be told from "the transaction could not be started". Its public `$outcome` says what became of the transaction, in the words of `transaction.end`: `rolled_back` when the rollback after the failed commit is confirmed (nothing is committed), `lost` when it is not (the commit may or may not have taken effect), `null` after a `commit()` the caller issues itself (directly, in a callback, in a hook), unless that commit told the end itself. `transaction()` and `updateMultiple()` always set one for the commit they run themselves - the value their `transaction.end` listeners received with the exception as error, or `lost` when no end was told with it -, and nothing writes into an exception afterwards. Only `rolled_back` means that nothing is committed.
- `UniqueViolationException` (a `QueryException`): thrown instead of a plain `QueryException` when a statement violates a unique key or the primary key (MySQL/MariaDB error 1062, PostgreSQL SQLSTATE 23505, SQLite `UNIQUE constraint failed`). `$constraint` is the name the database reports - the index name on MySQL/MariaDB (`PRIMARY` for the primary key; without the table MySQL puts in front), the constraint name on PostgreSQL, `null` on SQLite and wherever the name cannot be read reliably from the server's English message (another message language; a table or key name that contains a dot on MySQL since 8.0.19, which prints `table.key`, or a name with a dot wherever the server version cannot be read). A custom driver opts in by overriding `isUniqueViolation()` and `violatedConstraint()`.
- `insertIgnore()` on the driver and on the query builder: inserts a row unless it collides with a unique key or the primary key and returns 1 or 0; every other failure throws as in `insert()`. `ON CONFLICT DO NOTHING` on PostgreSQL and SQLite (on PostgreSQL that also skips a conflict with an exclusion constraint, and a duplicate on a `DEFERRABLE` unique constraint throws), `ON DUPLICATE KEY UPDATE col = col` on MySQL/MariaDB (not `INSERT IGNORE`, which also swallows other errors); there the existing row stays locked until the transaction ends and the table's update triggers run for it, and on a connection opened with `ATTR_FOUND_ROWS` the method throws, because the server reports one affected row for an existing row too. On the builder, clauses other than `select()` throw, as with `insertWhen()`.
- `Database::raw($sql, $bindings)`: a raw expression used as a value may carry bound values of its own (`['run_at' => Database::raw('run_at + ?', [$delay])]`). They are bound exactly where the expression stands among the statement's other values - in `insert()`/`update()` data, `where()`, `whereIn()`, `whereBetween()`, the value of `having()`, the CRUD methods' `$where`, `insertWhen()` and `insertIgnore()`. In `select()`, `groupBy()` and as the column of `having()` an expression with bindings throws a `QueryException`. `RawExpression` has a public `$bindings` list.
- `where($column, 'IS', $value)` and `'IS NOT'` accept `null` as the value: the null-safe comparison now covers "equal to this value, or both NULL" for a value that may be null. Every other operator still throws on `null`.
- `#[\SensitiveParameter]` on the config arrays of `Database::mysql()`, `postgres()`, `connect()` and of the MySQL/PostgreSQL driver constructors: PHP keeps the password out of stack traces.
- README: the `$where` arrays of the CRUD methods know equality only and the query builder is the way for everything else; the table "Database Differences" gained the rows an `update()` returns (MySQL/MariaDB count changed rows, the others matched ones), the evaluation order of several assignments in one `update()` (left to right on MySQL/MariaDB, from the old row elsewhere; the SET list follows the array order), `LIKE` and upper/lower case, the position of NULL in `orderBy()`, `rightJoin()` on SQLite before 3.39, `rows` in the `query` hook after a SELECT (always 0 on SQLite) and `insert()` into a table without auto-increment.

### Changed
- A commit that fails once it was sent and leaves no transaction behind (PostgreSQL after a `COMMIT` rejected by a deferred constraint, a commit after a raw `COMMIT` or a MySQL DDL statement) tells `transaction.end` as `lost` at once, on a manual `commit()` too. Before, a manual `commit()` told it only when the next transaction began; `transaction()` is unchanged. A transaction begun on raw PDO whose commit through the library fails that way is told as `lost` now as well (its successful commit already told `committed`).
- `whereIn()` and `whereNotIn()` throw a `QueryException` for a `null` element: `IN` never matches NULL, and `NOT IN` with a NULL in the list silently matched no row at all.
- An empty SQLite path (`Database::sqlite('')`, `new SqliteDriver('')`, `connect()` with an empty `path`) throws a `ConnectionException`; SQLite would open a private temporary database for it and delete it when the connection closes.
- An environment variable that is set but empty counts as not set, like `DB_PORT` already did: `DB_HOST=`, `DB_DATABASE=` or `DB_USERNAME=` report the missing value instead of connecting with an empty one. An empty `DB_SQLITE_PATH` throws a `ConnectionException` (it opened the same throwaway database as an empty path). A value in `$_ENV` that is no scalar counts as empty instead of being read as the text `Array`.

### Upgrading
What can break code that ran on 1.5:
- a class that implements `DatabaseInterface` directly must add `insertIgnore()` (drivers that extend `AbstractDriver` inherit it);
- code that compares the class of an exception exactly (`$e::class === TransactionException::class`, `=== QueryException::class`) sees the new subclasses for a failed commit and for a duplicate key; `catch` and `instanceof` are not affected;
- input that was accepted before and throws now: a `null` element in `whereIn()`/`whereNotIn()`, an empty SQLite path (also from an empty `DB_SQLITE_PATH`), an empty `DB_HOST`/`DB_DATABASE`/`DB_USERNAME` (they used to reach the server as empty values);
- a `transaction.end` listener sees the `lost` of a failed manual `commit()` that left no transaction right at that commit, not at the next `beginTransaction()`.

## [1.5.0] - 2026-10-02

### Added
- `groupBy()` accepts `Database::raw()`, alone or inside the array, to group by an expression (`groupBy(Database::raw('DATE(created_at)'))`).
- README: the table "Database Differences" lists what differs between MySQL/MariaDB, PostgreSQL and SQLite; SECURITY.md and the README name what the library cannot protect (parameters in logs, overridden PDO options, identifiers from request input without a whitelist).

### Changed
- `commit()` refuses a transaction the server has already ended: on PostgreSQL after a statement error that no savepoint caught (the server would answer `COMMIT` with a silent `ROLLBACK`), on MySQL/MariaDB after a deadlock, or - with autocommit, the default - a lock wait timeout under `innodb_rollback_on_timeout` (the `COMMIT` would succeed and commit nothing). It throws a `TransactionException` (`Failed to commit transaction`, `getPrevious()` is the statement's error) instead of sending the `COMMIT`: after a MySQL/MariaDB deadlock always, otherwise after asking the server with one probe statement, sent only when a statement failed in that transaction. While PDO still reports the transaction, `transaction()`/`updateMultiple()` roll back and `transaction.end` reports `rolled_back` (a manual `commit()` stays refused until `rollback()`). Before, a callback that swallowed such an error got `committed`. Failures on raw PDO are not seen; where nothing is left to roll back, the refusal itself reports `transaction.end` as `lost`: after a statement on raw PDO following a deadlock (committed on its own), and after a lock wait timeout that ended the transaction (later statements ran in autocommit).
- After a MySQL/MariaDB deadlock the library accepts nothing on that connection but the end of the transaction: `query()` sends nothing and throws a `QueryException` (`getPrevious()` is the deadlock; no hook fires), because the statement would run outside the transaction and be committed on its own, and `beginTransaction()` refuses. PostgreSQL does the same by itself in an aborted transaction. `rollback()` ends it; a transaction begun through the library also when PDO no longer reports it (then as `lost`). A lock wait timeout holds nothing back.
- A session with MySQL/MariaDB `completion_type=CHAIN` is reported instead of leaving the caller in a transaction nobody commits: after a commit as `CommitHookException` (first failure `Connection is in a new transaction`, commit hooks skipped, `connectionInTransaction` true), after a rollback as `TransactionException` once the rollback and end hooks ran (through the `error` hook where another exception reaches the caller). The message of `CommitHookException` names that case now (`..., the connection state after the commit could not be verified, or the connection is in a new chained transaction`).
- MySQL/MariaDB connections switch multi-statements off by default; the driver option in `options` switches them back on.
- Every `LIKE` / `NOT LIKE` of the builder is rendered as `LIKE ? ESCAPE ?` with the backslash bound, on every database, in `having()` and in join conditions too. `Database::escapeLike()` now also holds under MySQL's `NO_BACKSLASH_ESCAPES` and in `having()` on SQLite; `toSql()` returns one more parameter per `LIKE`.
- A `QueryException` instead of a silently wrong result for: a parameter that is an array, a resource, an object without `__toString()` (PDO bound `Array` / `Resource id #n`; an object was a PHP `Error`) or a `Database::raw()` expression passed to `query()`/`execute()` (it was bound as its own text), also reported to the `error` hook with code 0; a negative `limit()` or `offset()`; `null` in `whereBetween()` / `whereNotBetween()` and in `having()` (except with `IS` / `IS NOT`); a numeric key in `where([...])` (was a `TypeError`).
- A `ConnectionException` for a `port` that is not a whole number between 1 and 65535 (also from `DB_PORT`, where `abc` silently became the default port and `1e3` port 1000; surrounding whitespace is ignored) and for a SQLite path with a NUL byte.
- With `PDO::ERRMODE_WARNING` and an error handler that throws, a failed statement arrives as `QueryException` and fires the `error` hook like in every other error mode, and a failed `lastInsertId()` as `QueryException` (both left the library as the handler's exception).

### Upgrading
What can break code that ran on 1.4:
- a script that sends several statements in one `getPdo()->exec()` call on MySQL/MariaDB (switch multi-statements on in `options`);
- code that takes SQL and parameters from `toSql()` and counts or rewrites them (one more parameter per `LIKE`);
- input that was accepted before and throws now: negative limits, `null` bounds, unbindable parameters, an invalid `DB_PORT`;
- a `transaction.end` listener or caller that saw `committed` for a transaction the server had ended: it sees a `TransactionException` and `rolled_back` or `lost` now;
- a callback or an `error` hook that runs statements on the same connection after a MySQL/MariaDB deadlock: they throw until the rollback (give a database-logging hook its own connection);
- code that caught the error handler's exception in `PDO::ERRMODE_WARNING`: a failed statement is a `QueryException` there too now;
- statement auditing or a proxy that counts statements: after a failed statement inside a transaction, one probe statement precedes the `COMMIT` (`SELECT 1` on PostgreSQL; `DO 1` on MySQL/MariaDB unless the failure was a deadlock; none on SQLite);
- a custom driver that binds streams overrides `unbindableParameter()`.

### Fixed
- A transaction that ended behind the library's back (an implicit commit by a MySQL DDL statement, a statement on raw PDO) and was followed by another `beginTransaction()` - also the one inside `updateMultiple()` or a nested `transaction()` - never got its `transaction.end`; the next transaction's `committed` could be taken for it. It is told as `lost` now, before the next transaction begins.
- `insert()` returns the id of its own row when a `query` hook inserts on the same connection: the id is read before the hook runs.
- A failing `lastInsertId()` inside a PostgreSQL transaction (an unknown sequence) is remembered like a failed statement: the commit is refused instead of rolling back silently.
- PostgreSQL `insert()` reads the sequence in the table's own schema, quoted like the table (`"shop"."users_id_seq"`): a same-named table on the `search_path` no longer answers, and mixed-case table names get their id.
- Aggregates no longer depend on the result key: with `PDO::ATTR_CASE` set, `count()` returned 0 and `sum()`/`min()`/`max()` null.
- `distinct()->count()` no longer rejects two PostgreSQL columns whose names differ only in case.
- The debug message of a failed query keeps its parameter list when a parameter is not valid UTF-8.

## [1.4.0] - 2026-10-01

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

[Unreleased]: https://github.com/sodaho/pdo-wrapper/compare/v1.4.0...HEAD
[1.4.0]: https://github.com/sodaho/pdo-wrapper/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/sodaho/pdo-wrapper/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/sodaho/pdo-wrapper/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/sodaho/pdo-wrapper/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/sodaho/pdo-wrapper/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/sodaho/pdo-wrapper/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/sodaho/pdo-wrapper/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/sodaho/pdo-wrapper/releases/tag/v1.0.0
