# sodaho/pdo-wrapper

PDO wrapper for MariaDB with a query builder, CRUD methods, transactions that report their outcome, named locks and
event hooks.

## Requirements

- PHP ^8.5 with ext-pdo, ext-pdo_mysql built on mysqlnd, ext-mbstring
- MariaDB 10.11 or later (the test setup runs 10.11, 11.4 and 12.3)

## Installation

```bash
composer require sodaho/pdo-wrapper
```

## Quick start

```php
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;

$db = Database::mariadb(['host' => '127.0.0.1', 'database' => 'app', 'username' => 'app', 'password' => $secret]);
$id = $db->insert('users', ['email' => 'a@example.test', 'name' => 'A']);
$user = $db->table('users')->where('id', $id)->first();
$db->transaction(function (DatabaseInterface $db) use ($id): void {
    $db->table('users')->where('id', $id)->lockForUpdate()->first();
    $db->update('users', ['name' => 'B'], ['id' => $id]);
});
```

## Reference

Type against `Sodaho\PdoWrapper\DatabaseInterface`: its method docs are the full contract, the hook contract is in
`Traits\HasHooks`. The statement, CRUD and builder methods throw a `QueryException` when a statement fails, the
transaction methods a `TransactionException`.

### Database (static factory)

| Signature | Description |
|---|---|
| `mariadb(array $config): MariaDbDriver` | Connects with `$config` (see Configuration); reads no environment. |
| `connect(array $config): MariaDbDriver` | `driver` must be `mariadb`; the other keys go to `mariadb()`. |
| `fromEnv(array $overrides = []): MariaDbDriver` | Reads `DB_*` (Configuration); a passed key wins, `null` too. |
| `raw(string $value, array $bindings = []): RawExpression` | SQL written as given; `$bindings` fill its `?`. |
| `value(string $column): RawExpression` | ``VALUE(`col`)``: the inserted value, inside the `$update` of an upsert. |
| `json(string $column, string $path): JsonExpression` | A value inside a JSON column, as text. |
| `escapeLike(string $value): string` | Escapes `\`, `%` and `_` for a LIKE pattern. |

### Statements (DatabaseInterface)

| Signature | Description |
|---|---|
| `query(string $sql, array $params = []): PDOStatement` | Prepares, binds, executes; fires the statement hooks. |
| `execute(string $sql, array $params = []): int` | `query()`, returns the affected rows. |
| `lastInsertId(?string $name = null): string\|false` | PDO's last insert id. |
| `getPdo(): PDO` | The PDO object; statements sent on it fire no hooks and are not checked. |
| `table(string $table): QueryBuilder` | A query builder for the table. |
| `schema(): Schema` | Tables, columns, indexes and constraints from `information_schema`. |
| `now(): RawExpression` | `NOW()`, in the connection's time zone. |
| `utcNow(): RawExpression` | `UTC_TIMESTAMP()`, a zoneless value. |

A parameter is `null`, a scalar or a `Stringable`; an array, a resource, another object, a `RawExpression`, `INF` and
`NAN` are refused before the statement is sent. Values are bound as text, a bool as `'1'`/`'0'`, a float as the
shortest text that reads back as the same float.

### CRUD (DatabaseInterface)

| Signature | Description |
|---|---|
| `insert(string $table, array $data): int` | Inserts a row; returns the id, 0 without AUTO_INCREMENT. |
| `update(string $table, array $data, array $where): int` | `$where`: equalities, AND, no `null`; empty throws. |
| `delete(string $table, array $where): int` | Empty `$where` throws. |
| `findOne(string $table, array $where): ?array` | `SELECT * ... LIMIT 1`; empty `$where` throws. |
| `findAll(string $table, array $where = []): array` | The matching rows; without `$where` all rows. |
| `insertIgnore(string $table, array $data): int` | 1 inserted, 0 on a duplicate key; other failures throw. |
| `upsert(string $table, array $row, array $update): int` | `ON DUPLICATE KEY UPDATE`; 1 inserted, 2 updated, 0 same. |
| `updateMultiple(string $table, array $rows, string $keyColumn = 'id'): int` | An UPDATE per row, by its key. |

```php
insertWhen(string $table, array $data, string $condition, array $bindings = [], array $update = []): int
insertWhenReturning(string $table, array $data, string $condition, array $bindings = [], array $update = [],
    array $columns = ['*']): ?array
upsertReturning(string $table, array $row, array $update, array $columns = ['*']): array
```

- `insertWhen()`: `INSERT ... SELECT ... FROM DUAL WHERE (condition)`; returns 1 or 0, with `$update` MariaDB's count
  (1 inserted, 2 updated, 0 neither). `insertWhenReturning()` returns the row, `null` when the condition was false.
- `upsertReturning()`: the inserted row, or the existing one after the update.
- Keys of `$data`, `$row`, `$where` are plain column names: an integer key or a dot throws. A `RawExpression` value is
  written into the SQL, its own bindings bound in place.
- `insertIgnore()`, `upsert()` and `insertWhen()` with `$update` throw on a connection opened with
  `Pdo\Mysql::ATTR_FOUND_ROWS` or on a persistent one: the server's count cannot tell the cases apart there.
- `updateMultiple()` checks the rows before the first is sent; a refused row leaves nothing written. Without an open
  transaction it runs in its own, with the outcomes of `transaction()`.

### Transactions, connection, named locks, hooks (DatabaseInterface)

| Signature | Description |
|---|---|
| `beginTransaction(): void` | Begins a transaction; no nesting. |
| `commit(): void` | Commits (see Transactions). |
| `rollback(): void` | Rolls back; also ends a library transaction PDO no longer reports, as `lost`. |
| `transaction(Closure $callback): mixed` | Commits when the callback returns, rolls back when it throws. |
| `inTransaction(): bool` | `PDO::inTransaction()`. |
| `currentTransaction(): ?int` | Number of the open transaction begun through the driver, else `null`. |
| `reconnect(bool $dropNamedLocks = false, bool $dropTransaction = false): void` | New connection, same settings. |
| `namedLock(string $name, int $timeout = 0): bool` | `GET_LOCK()`; false when another connection held it. |
| `releaseNamedLock(string $name): bool` | `RELEASE_LOCK()`; false when this connection did not hold it. |
| `isNamedLockHeld(string $name): bool` | Asks the server whether this connection holds it. |
| `namedLockHolder(string $name): ?int` | Connection id of the holder, `null` when free. |
| `heldNamedLocks(): array` | The names the driver holds by its own record, in the order taken. |
| `on(string $event, callable $callback): static` | Registers a listener; an unknown event throws. |
| `off(string $event, callable $callback): static` | Removes the callback's registrations (`===`); unknown throws. |

A named lock belongs to the connection, not to a transaction: COMMIT and ROLLBACK do not release it. The name is
prefixed with the configured database and `:` (`app:login:7`, up to 192 bytes with the prefix); with a `:` in the
database name the lock methods throw. An empty name, a name with a NUL byte and a negative timeout throw a
`QueryException`; taking a lock this connection holds throws `NamedLockReentryException`. The constants
`TRANSACTION_COMMITTED`, `TRANSACTION_ROLLED_BACK` and `TRANSACTION_LOST` name the outcomes.

### Drivers

| Signature | Description |
|---|---|
| `MariaDbDriver::__construct(array $config)` | What `Database::mariadb()` calls. |
| `AbstractDriver::__construct(?Closure $connect = null, bool $redactParameters = false)` | Base of a custom driver. |

`$connect` returns the PDO object, at construction and for `reconnect()`; without it the driver sets `$pdo` itself
and cannot reconnect. `beginTransaction()`, `commit()` and `rollback()` are `final`. A custom driver adapts through
the protected methods `failureToRemember()`, `transactionIsOver()`, `transactionEndedBy()`,
`refreshTransactionState()`, `implicitCommitOf()`, `namedLockPrefix()`, `isUniqueViolation()`, `violatedConstraint()`,
`quoteIdentifier()` and `knownEvents()` (events of its own, fired with `trigger()`).

### QueryBuilder (`$db->table('users')`)

`QueryBuilder::__construct(DatabaseInterface $db, string $table)` is what `table()` returns. Clause methods return
the builder; `$column` of the where methods may be an expression without bindings (`Database::json()`,
`Database::raw('LOWER(email)')`).

```php
// Clauses
select(string|array $columns = '*'): self       // 'id, name', ['id', 'u.name as n', Database::raw('COUNT(*)')]
distinct(): self                                // SELECT DISTINCT
where(string|RawExpression|array $column, mixed $operatorOrValue = null, mixed $value = null): self
whereRaw(string $sql, array $bindings = []): self                      // trusted SQL in parentheses, AND-joined
whereIn(string|RawExpression $column, array $values): self             // [] renders 1 = 0; null in it throws
whereNotIn(string|RawExpression $column, array $values): self          // [] throws
whereBetween(string|RawExpression $column, array $values): self        // two values, no null
whereNotBetween(string|RawExpression $column, array $values): self     // as whereBetween()
whereNull(string|RawExpression $column): self                          // IS NULL
whereNotNull(string|RawExpression $column): self                       // IS NOT NULL
whereLike(string|RawExpression $column, string $pattern): self         // LIKE ? ESCAPE ?, backslash as escape
whereNotLike(string|RawExpression $column, string $pattern): self      // NOT LIKE ? ESCAPE ?
join(string $table, string $first, string $operator, string $second): self        // INNER JOIN
leftJoin(string $table, string $first, string $operator, string $second): self    // LEFT JOIN
rightJoin(string $table, string $first, string $operator, string $second): self   // RIGHT JOIN
orderBy(string|RawExpression $column, string $direction = 'ASC'): self  // ASC or DESC; a string is a column name
limit(int $limit): self                                                 // negative throws
offset(int $offset): self                                               // negative throws
groupBy(string|RawExpression|array $columns): self                      // a string is split at commas into names
having(string|RawExpression $column, string $operator, mixed $value): self   // a string names a column or the
                                                                             // text of a selected expression
lockForUpdate(): self                           // FOR UPDATE; a read outside a transaction throws
sharedLock(): self                              // LOCK IN SHARE MODE; the same
// Execution
get(): array                                    // the rows
first(): ?array                                 // the first row or null
exists(): bool                                  // SELECT 1 ... LIMIT 1, keeping offset() and a lock
count(string $column = '*'): int                // with distinct() distinct rows, with groupBy() the groups
sum(string $column): float|string|null          // as MariaDB delivers it: integer and DECIMAL sums as string
avg(string $column): float|string|null          // as sum()
min(string $column): mixed                      // in the column's type; null without a value
max(string $column): mixed                      // as min()
insert(array $data): int                        // as the driver's insert()
insertWhen(array $data, string $condition, array $bindings = [], array $update = []): int
insertWhenReturning(array $data, string $condition, array $bindings = [], array $update = [],
    array $columns = ['*']): ?array
insertIgnore(array $data): int                                          // as the driver's
upsert(array $row, array $update): int                                  // as the driver's
upsertReturning(array $row, array $update, array $columns = ['*']): array   // as the driver's
update(array $data): int                        // needs a where; limit() needs orderBy() and the other way round
delete(): int                                   // as update()
increment(string $column, int|float $by = 1, array $extra = []): int   // col = col + CAST(? AS ...)
decrement(string $column, int|float $by = 1, array $extra = []): int   // as increment(), subtracting
toSql(): array                                  // [sql, params] without executing
```

`where('id', 5)`, `where('age', '>', 18)`, `where(['active' => 1])`, `where('nick', 'IS', $nick)`. With two arguments
the second is the value, with three the operator: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IS`,
`IS NOT` (null-safe, `<=>`). Another operator, or one that is no string, throws; a `null` value needs `IS`/`IS NOT`.

- The insert methods throw when a where, join, `groupBy()`, `having()`, `orderBy()`, `limit()`, `offset()`,
  `distinct()` or a lock is set on the builder; `select()` is ignored.
- `update()` and `delete()` throw for `offset()`, a join, `groupBy()` and `having()`.
- `sum()`, `avg()`, `min()` and `max()` throw with `groupBy()`. A lock together with `distinct()`, `groupBy()` or
  `having()` throws.
- `increment()` casts an int to `SIGNED` and a float to `DECIMAL(65,30)`; `$extra` must not set the column again.

### Schema (`$db->schema()`)

| Signature | Description |
|---|---|
| `__construct(DatabaseInterface $db)` | What `schema()` returns. |
| `tables(): array` | Base and system-versioned tables of the current database. |
| `hasTable(string $table): bool` | Compared as the server compares table names. |
| `columns(string $table): array` | `name`, `type`, `nullable`, `default` (SQL text or `null`), `extra`. |
| `indexes(string $table): array` | `name`, `columns`, `unique`, `primary`; the primary key first. |
| `constraints(string $table): array` | `name`, `type` (`PRIMARY KEY`, `UNIQUE`, `FOREIGN KEY`, `CHECK`). |

`columns()`, `indexes()` and `constraints()` throw a `QueryException` for an unknown table. What is shown depends on
the user's privileges.

### RawExpression, JsonExpression (`Sodaho\PdoWrapper\Query`)

| Signature | Description |
|---|---|
| `RawExpression::__construct(string $value, array $bindings = [])` | A binding that is a `RawExpression` throws. |
| `RawExpression::__toString(): string` | `$value`; public readonly `$value`, `$bindings`. |
| `JsonExpression::__construct(string $column, string $path, array $fallbacks = [])` | What `Database::json()` builds. |
| `JsonExpression::orColumn(string $column): self` | ``COALESCE(<value>, `column`)``. |
| `JsonExpression::as(string $alias): RawExpression` | The expression as a `select()` entry with an alias. |

A JSON path is `$` followed by `.name` and `[n]` steps (up to 9 digits); another form throws. The value is text,
compared under `utf8mb4_bin`; a missing field is `NULL`, a JSON `null` the text `'null'`. In `having()` use the
alias. An index: a virtual column with the same expression and `COLLATE utf8mb4_bin`; from MariaDB 11.8 a `where()`
on the expression uses it.

## Configuration

Keys of `Database::mariadb()`, `connect()`, the `fromEnv()` overrides and `MariaDbDriver::__construct()`:

| Key | Type | Default | Allowed | Refused |
|---|---|---|---|---|
| `host` | string | required | not empty | empty, `;` or NUL, no string |
| `database` | string | required | not empty | empty, `;` or NUL, no string |
| `username` | string | required | not empty | empty, no string |
| `password` | ?string | `null` | `''` is a password | no string |
| `port` | int\|string\|null | `3306` | 1 to 65535, as int or digits | other values |
| `charset` | ?string | `utf8mb4` | a charset name | `;` or NUL, no string |
| `options` | ?array | `[]` | `PDO::ATTR_* => value`; replace the defaults | see below |
| `pdoClass` | ?string | `PDO` | an instantiable class that is or extends `PDO` | other values |
| `redactParameters` | bool | `false` | `true`, `false` | other values, `null` included |
| `driver` | string | none | `mariadb` (`connect()`, `fromEnv()`) | other names; the key in `mariadb()` |

- An unknown key throws a `ConnectionException` that names it in `getDebugMessage()`. `null` for `port`, `charset`,
  `options`, `pdoClass` and `password` means the default.
- Defaults of `options`: `ATTR_ERRMODE` exception, `ATTR_DEFAULT_FETCH_MODE` assoc, `ATTR_EMULATE_PREPARES` false,
  `ATTR_STRINGIFY_FETCHES` false, `Pdo\Mysql::ATTR_MULTI_STATEMENTS` false.
- Refused `options`: `ATTR_STRINGIFY_FETCHES` or `Pdo\Mysql::ATTR_MULTI_STATEMENTS` with a value other than `false` or
  the integer `0` (`'0'`, `null` and `0.0` are refused), `ATTR_STATEMENT_CLASS` with whatever value, keys that are no
  integers.
- Refused when the connection opens (`ConnectionException::$refusal`): a client that is not mysqlnd (`NotMysqlnd`),
  a server that is not MariaDB (`NotMariaDb`), MariaDB before 10.11 (`MariaDbTooOld`), `ATTR_ORACLE_NULLS` other than
  `NULL_NATURAL` (`NullMode`). Then the driver sets `completion_type` to `NO_CHAIN`, and throws if that fails.
- `fromEnv()` reads `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` from `$_ENV`,
  then from `getenv($key, true)`; a variable that is set but empty counts as not set. `charset`, `options`,
  `pdoClass` and `redactParameters` have no variable.
- Fetched types on 64-bit PHP: INT, BIGINT, TINYINT(1) `int` (BIGINT UNSIGNED above `PHP_INT_MAX` `string`); FLOAT,
  DOUBLE `float`; DECIMAL, `SUM()`/`AVG()` of integers `string`; DATETIME, VARCHAR, TEXT, JSON `string`; NULL `null`.

## Hooks

| Event | When | Payload |
|---|---|---|
| `query.before` | At the start of `query()`, before the library's checks | `sql`, `params` |
| `query` | After the statement ran | `sql`, `params`, `duration`, `rows` |
| `error` | A failed statement (`code` 0: value refused) | `sql`, `params`, `error`, `code`, `sqlState`, `driverCode` |
| `transaction.begin` | After a BEGIN that went through | `transaction` (int), `depth` (int) |
| `transaction.commit` | After a COMMIT that went through | `transaction`, `depth` (?int) |
| `transaction.rollback` | After a ROLLBACK the library confirmed | `transaction`, `depth` (?int) |
| `transaction.end` | Once per transaction the library ends | `outcome`, `error`, `transaction`, `depth` |

- `transaction.end` runs after the commit or rollback listeners. `transaction` numbers the transactions begun through
  the driver from 1; one begun on raw PDO carries `null`.
- A throwing `query.before`, `query`, `error`, `transaction.begin` or `transaction.rollback` listener stops the
  listeners after it, and its exception reaches the caller (a `PDOException` from `query.before` or `query` as
  `QueryException` "Query hook failed", from `transaction.begin` or `transaction.rollback` as `TransactionException`).
  Not so on the automatic rollback of `transaction()`/`updateMultiple()`: a rollback listener's exception is dropped
  and the exception that ended the transaction reaches the caller. An `error` listener that throws while the library
  only reports a failure (one the caller does not get as the thrown one, below) is ignored.
- A failing `transaction.commit` or `transaction.end` listener does not stop the next one; after a commit their
  failures arrive together in a `CommitHookException`. The remaining commit listeners are skipped, and listed as
  failures, when a commit listener left a raw transaction open that cannot be rolled back, the state cannot be read,
  or the session chained a new transaction to the COMMIT.
- `transaction.end` listener failures: after a COMMIT they arrive only in the `CommitHookException`. After a ROLLBACK
  they are told to `error` (`sql` `''`, `params` `[]`, `error`, `code`, `sqlState`, `driverCode`, then `hook`,
  `outcome`, `exception`; one whose codes cannot be read is dropped). After a manual `rollback()` the first one is
  also thrown as a `TransactionException`, unless a rollback listener threw, the session chained a transaction to
  the ROLLBACK or the state after it cannot be read: that exception wins. On the automatic rollback and on `lost`
  they reach only `error`. After a ROLLBACK, a chained transaction or unreadable state that is not thrown is told
  to `error` the same way, without `hook`; after a COMMIT it joins the `CommitHookException`. An `error` listener
  that throws there is ignored.
- Statement listeners 32 levels deep, and `transaction.end` listeners that keep beginning transactions after 32
  levels, end in a `LogicException`.
- Listener rule: a `transaction.end` listener may steer transactions; a `query.before`, `query` or `error` listener may
  run `transaction()` or `updateMultiple()` when no transaction was open as it was entered. Other listeners, and
  `beginTransaction()`, `commit()` or `rollback()` from a statement listener, get a `ListenerTransactionException`
  (details: `Traits\HasHooks`).

## Exceptions

Namespace `Sodaho\PdoWrapper\Exception`. `getMessage()` names no SQL and no bound value; details are in
`getDebugMessage()`.

| Class | When | Fields |
|---|---|---|
| `DatabaseException` | Base class | `$sqlState` (?string), `$driverCode` (?int), `getDebugMessage(): ?string` |
| `ConnectionException` | Connecting, a refused config, `reconnect()` | `$refusal` (?`ConnectionRefusal`) |
| `NamedLocksHeldException` | `reconnect()` while named locks are held | `$lockNames` |
| `TransactionOpenException` | `reconnect()` while a library transaction is open | |
| `QueryException` | A failed or refused statement or argument | |
| `UniqueViolationException` | A duplicate key (error 1062) | `$constraint` (?string) |
| `ImplicitCommitException` | A statement that would commit the open transaction | `$statement` |
| `LockOutsideTransactionException` | A locking read outside a transaction | |
| `NamedLockReentryException` | `namedLock()` of a lock this connection holds | `$lockName` |
| `TransactionException` | Begin, commit or rollback failed | |
| `CommitFailedException` | The commit failed or was refused | `$outcome` (?string) |
| `ListenerTransactionException` | Transaction control refused inside a listener | |
| `CommitHookException` | Committed; a listener failed, or the state unclear | `$failures`, `$connectionInTransaction` |
| `RedactedPdoException` | `redactParameters`: replaces a statement's or listener's error | SQLSTATE as `getCode()` |

- Hierarchy: `ConnectionException`, `QueryException`, `TransactionException` and `CommitHookException` extend
  `DatabaseException`; `NamedLocksHeldException` and `TransactionOpenException` extend `ConnectionException`;
  `UniqueViolationException`, `ImplicitCommitException`, `LockOutsideTransactionException` and
  `NamedLockReentryException` extend `QueryException`; `CommitFailedException` and `ListenerTransactionException`
  extend `TransactionException`; `RedactedPdoException` extends `PDOException`.
- `getCode()` of a `DatabaseException` is 0; compare `$sqlState` as a string. `ConnectionRefusal` cases: `NotMysqlnd`,
  `NotMariaDb`, `MariaDbTooOld`, `NullMode`.
- Constructors: `DatabaseException::__construct(string $message = 'Database error', ?Throwable $previous = null,
  ?string $debugMessage = null, bool $listenerFailure = false, bool $codesOfPrevious = true)`; the subclasses take
  message, previous and debug message first, their field last. `CommitHookException(Throwable $first, array $failures,
  bool $connectionInTransaction = false)`, `RedactedPdoException(string $message, ?string $sqlState)`.

## Transactions

| `transaction.end` outcome | Meaning | `CommitFailedException::$outcome` |
|---|---|---|
| `committed` | `PDO::commit()` went through; listener failures come as `CommitHookException` | |
| `rolled_back` | The library's ROLLBACK went through: nothing of it is committed (see below) | `rolled_back` |
| `lost` | No confirmed rollback (ROLLBACK failed, transaction gone, state unknown): data may be committed | `lost` |

- `transaction.end` fires once for each transaction the library ends. A manual `commit()` or `rollback()` that fails
  before the end fires nothing and leaves the transaction to the caller - except a commit after which PDO reports no
  transaction: it tells `lost` at once. A `rollback()` whose ROLLBACK went through may still throw for a listener's
  failure, after the events. Full contract: `DatabaseInterface::commit()`, `rollback()`, `transaction()` and
  `Traits\HasHooks`.
- `rolled_back` holds for transactions steered through the library: SQL that steers transactions itself, sent through
  `query()` (`START TRANSACTION` commits the open one and opens the next), is not seen, and the outcome is then not to
  be relied on.
- `transaction()` returns the callback's value. After a failed commit there it rolls back where PDO still reports the
  transaction, and `$outcome` is the outcome told; a failed manual `commit()` leaves it `null` unless it told `lost`.
- No nesting: `beginTransaction()` inside an open transaction throws a `TransactionException`.
- After a deadlock (1213) or error 1020 the server has rolled the transaction back: statements are refused, `commit()`
  throws a `CommitFailedException`, `beginTransaction()` refuses, until the transaction is ended (`rollback()`, or a
  refused `commit()` that tells `lost`). After another failure inside a library transaction the driver asks the
  server; found gone, or not to be found out, its end is `lost`, and nothing more is sent until it is ended.
- Inside a library transaction a statement that commits implicitly (DDL, LOCK/UNLOCK TABLES, account and maintenance
  statements; see `Driver\ImplicitCommit`) throws `ImplicitCommitException` before it is sent. Unjudged and refused
  as well: an executable comment (`/*!...*/`, `/*M!...*/`), a byte from 0x80 on or a control character other than
  tab, LF, VT, FF and CR, standing anywhere before the leading keywords are decided (also between them). `BEGIN`,
  `COMMIT`, `ROLLBACK`, `SET autocommit` and `XA` sent through `query()` are not refused and not tracked; what `CALL`,
  `EXECUTE` or `BEGIN NOT ATOMIC` run inside is not looked into.
- `reconnect()` opens the new connection first (a failure changes nothing), sends a ROLLBACK on the old one when it
  reports a transaction, then swaps; an owed end is told as `lost`. It throws `TransactionOpenException` while a
  library transaction is open (unless `$dropTransaction`), `NamedLocksHeldException` while named locks are held
  (unless `$dropNamedLocks`), `ConnectionException` for a persistent connection or a driver without `$connect`.
  Session state (`SET SESSION`, temporary tables) stays behind; set it with `Pdo\Mysql::ATTR_INIT_COMMAND`.

A manual transaction ends where the library or PDO still reports one:

```php
$db->beginTransaction();
try {
    $work($db);
    $db->commit();
} catch (Throwable $e) {
    if ($db->currentTransaction() !== null || $db->inTransaction()) {
        $db->rollback();
    }
    throw $e;
}
```

## Security

- Values are bound (native prepares by default); names are quoted with backticks, a backtick inside doubled.
- SQL in `Database::raw()`, `whereRaw()`, the `insertWhen()` condition and raw `select()`, `groupBy()` and
  `orderBy()` entries is used as written: never build it from user input, put user input in `$bindings`.
- Keys of `$data` and `$where` are column names, and MariaDB compares them without case: map user input to a fixed
  list of accepted keys.
- Channels that carry bound values: the `query.before`, `query` and `error` payloads, `getDebugMessage()`, the
  previous exception (MariaDB's message quotes values, e.g. a duplicate entry), `$lockName`, `$lockNames`. With
  `redactParameters` payload values are `'[redacted]'`, debug messages name no value, and a `RedactedPdoException`
  with the codes replaces the `PDOException` of a failed statement, a failed read after it and a listener;
  `$lockName` and `$lockNames` keep the names, a failed connect keeps PDO's exception.
- Parameters that take values are marked `#[\SensitiveParameter]`. The `PDOException` of a failed connect holds the
  DSN, the username and the options in its trace while `zend.exception_ignore_args` is off.
- Multi-statements are off and cannot be switched on. `escapeLike()` escapes user input for LIKE; the builder binds
  the escape character.
- Reporting a vulnerability: [SECURITY.md](SECURITY.md).

## Testing

```bash
composer install
docker compose up -d                  # MariaDB 10.11 on 3306, 11.4 on 3307, 12.3 on 3308
composer test                         # against 10.11
MARIADB_PORT=3307 composer test       # against 11.4
composer analyse && composer cs && composer validate --strict
```

Variables `MARIADB_HOST`, `MARIADB_PORT`, `MARIADB_DATABASE`, `MARIADB_USERNAME`, `MARIADB_PASSWORD` (defaults in
`phpunit.xml.dist`). Suites: `Unit` (no database), `Contract`, `Driver` (`composer test:unit`, `test:contract`,
`test:driver`).

## Upgrading

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).
