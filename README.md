# PDO Wrapper

A lightweight PHP PDO wrapper with a fluent query builder, for MariaDB.

## Why This Library?

- **No dependencies** -- just PDO and pdo_mysql, which ship with PHP.
- **Readable codebase** -- the entire source fits in a handful of files, every decision explained where it is made. Reading all of it takes an afternoon, not minutes: the transaction paths are where the care went.
- **One database, known well** -- MariaDB 10.11 and later: its transactions, deadlocks, implicit commits and result types are measured and handled, not guessed for several engines at once.
- **Safe defaults** -- prepared statements, identifier quoting, operator whitelist. Hard to accidentally write an injection vulnerability.
- **Intentionally limited** -- no dedicated OR methods, no subqueries, no UNION in the query builder. When you need complex SQL, you write SQL: a raw condition with bound values via `whereRaw()`, or the whole statement. The builder handles the straightforward queries.

Since 3.0 the library supports MariaDB only; the drivers for MySQL, PostgreSQL and SQLite are gone (see the CHANGELOG, "Upgrading from 2.x"). The driver layer stays: a driver for another database could come back, and it would have to pass the same test suite (see [Testing](#testing)).

## Installation

```bash
composer require sodaho/pdo-wrapper
```

Requires PHP 8.5, pdo_mysql built on mysqlnd (the default of PHP's own builds), and MariaDB 10.11 or later.

## Quick Start

```php
use Sodaho\PdoWrapper\Database;

$db = Database::mariadb([
    'host' => 'localhost',
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
]);
```

## Connection Options

```php
$db = Database::mariadb([
    'host' => '127.0.0.1',      // required ('localhost' is the local socket, see below)
    'database' => 'myapp',      // required
    'username' => 'root',       // required
    'password' => 'secret',     // optional
    'port' => 3306,             // optional, default: 3306
    'charset' => 'utf8mb4',     // optional, default: utf8mb4
    'options' => [],            // optional, PDO options
    'pdoClass' => PDO::class,   // optional, the class of the PDO object (see "The PDO Class")
]);
```

`options` replace the library's PDO defaults, the security-relevant ones included: exceptions as error mode, native prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`) and multi-statements switched off. No statement of the library needs multi-statements; switched on, a string that reaches raw PDO (`getPdo()->exec()`) or an emulated prepare could carry a second statement. `Pdo\Mysql::ATTR_MULTI_STATEMENTS => true` brings them back, for example for a migration that sends a whole file in one call. On a persistent connection (`PDO::ATTR_PERSISTENT`) the options that take effect when connecting (multi-statements, `ATTR_INIT_COMMAND`, `ATTR_FOUND_ROWS`, SSL) are those of the request that opened it: PDO hands a pooled connection back whatever the new options say. Two options are pinned: `PDO::ATTR_STRINGIFY_FETCHES` stays off and `PDO::ATTR_ORACLE_NULLS` stays `PDO::NULL_NATURAL` (see "What Comes Back"). Set the connection charset with the `charset` key, never with `SET NAMES` at runtime: PDO's own escaping (emulated prepares, `PDO::quote()`) only knows the charset of the DSN. `port` must be a whole number between 1 and 65535, also when `Database::fromEnv()` reads it from `DB_PORT` (an invalid value throws a `ConnectionException` instead of falling back to the default).

**What is refused when the connection opens.** The server must be MariaDB 10.11 or later, and pdo_mysql must be built on mysqlnd. Both are read from what the client got in the handshake, without sending anything: a MySQL server, an older MariaDB or another client library throws a `ConnectionException` before the library sends a statement ("MariaDB connection to host:port refused: ..."; a `Pdo\Mysql::ATTR_INIT_COMMAND` has run in the handshake already), and so does `reconnect()` when the new connection is one of them. The version string may carry MariaDB's prefix for old clients (`5.5.5-10.11.9-MariaDB`) and MariaDB Enterprise's build number (`11.4.5-3-MariaDB-enterprise`); a proxy in between (MaxScale, ProxySQL) passes when it reports the server's version string, `-MariaDB` included. The check reads what the PDO object reports: a `pdoClass` of yours that reports something else is trusted, like the rest of your code.

**What is set when the connection opens.** After those checks the driver sends one statement, on raw PDO (no hook sees it): `SET SESSION completion_type = 'NO_CHAIN'` - whatever the server's default (every new session inherits it) or an `INIT_COMMAND` among the `options` set, and again at `reconnect()`. The outcomes of a transaction rely on a `COMMIT` and a `ROLLBACK` that end it and open none: under `CHAIN` each opens the next transaction, under `RELEASE` the server closes the connection after it. When the statement does not go through, a `ConnectionException` is thrown (`$refusal` null); what a `pdoClass`, or an error handler under a non-exception error mode, throws besides a `PDOException` passes unchanged. Behind a proxy that pools server connections, the statement is a session variable like any other: whether the proxy keeps it per client or refuses it is the proxy's (not measured). A session you switch afterwards is covered under [Transactions](#transactions).

**What is not set.** `host => 'localhost'` makes pdo_mysql use the local Unix socket and ignore `port` (measured: `localhost` with `port => 3309` fails with 2002 "No such file or directory" where only TCP listens) - use `127.0.0.1` for TCP. The library sets neither `sql_mode` nor `time_zone` nor a connect timeout: the server's defaults apply. Its results rely on a strict `sql_mode` (MariaDB's default) - without it the server truncates and coerces values instead of failing -, and `NOW()` follows the session's `time_zone`. A host that does not answer blocks the connect for PHP's `default_socket_timeout` (60 s by default). Set what your application needs on every connection, `reconnect()` included, with the options: `Pdo\Mysql::ATTR_INIT_COMMAND => "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION', time_zone = '+00:00'"` (one statement: multi-statements are off) and `PDO::ATTR_TIMEOUT => 5` (seconds for the connect).
### What Comes Back

The PHP type of a fetched value is pinned, the same on MariaDB 10.11, 11.4 and 12.3, with native and with emulated prepares (measured with PHP 8.5 and mysqlnd, on 64-bit PHP; on a 32-bit build an integer outside -2^31 .. 2^31 - 1 - a `BIGINT`, an `INT UNSIGNED` - cannot arrive as `int`):

| Column or expression | PHP type |
|---|---|
| `INT`, `SMALLINT`, `BIGINT`, `TINYINT(1)` / `BOOLEAN` | `int` |
| `BIGINT UNSIGNED` above `PHP_INT_MAX` | `string` |
| `FLOAT`, `DOUBLE` | `float` |
| `DECIMAL`, and `SUM()` / `AVG()` of integers (they are `DECIMAL`) | `string` |
| `VARCHAR`, `TEXT`, `BLOB`, `DATETIME`, `TIMESTAMP`, `JSON`, `JSON_UNQUOTE(JSON_EXTRACT(...))` | `string` |
| `NULL` | `null` |

The driver sets `PDO::ATTR_STRINGIFY_FETCHES` off and refuses `options` that switch it on: the setting would turn every value into a string, and the types above would no longer hold. Likewise `PDO::ATTR_ORACLE_NULLS` stays `PDO::NULL_NATURAL`: `NULL_TO_STRING` would deliver `NULL` as `''`, `NULL_EMPTY_STRING` `''` as `null` - a connection with another mode is refused (the mode is read back from the connection, so every spelling counts). Where an application needs strings, it casts. Whether a value arrives as `int` or as a string depends on the client, not on the server: these are mysqlnd's types, which is why another client library is refused.

**What goes in.** Every value is bound as text; a `bool` as `'1'`/`'0'`, a `float` as the shortest decimal text that reads back as the same float (`0.1` as `'0.1'`, `0.1 + 0.2` as `'0.30000000000000004'`) - not as PHP's `precision` setting writes it, which cuts after 14 digits and differs between installations. So a float keeps all its digits in a `DOUBLE` column and in a comparison. The other side: a computed float is not rounded into the `DECIMAL` it was meant to equal - `where('price', 0.1 + 0.2)` does not find `0.30`. Write and compare `DECIMAL` columns with a string (`'0.30'`) or a rounded value (`round($x, 2)`), never with a computed float. A string bound against a number column is read as a number by MariaDB, as far as it looks like one: `where('id', '1abc')` finds the row with id 1, `' 1'` and `'1.0'` find it as well, `'1e3'` finds id 1000, `'007'` id 7 (measured). Check a number that comes from a request before the call (`filter_var($id, FILTER_VALIDATE_INT)`) and pass it as `int`; an `int` is bound as it is, not rewritten.

### One Config for Every Environment

`Database::connect()` picks the driver from the config (`driver`) and delegates to `mariadb()` with the same keys:

```php
$db = Database::connect(['driver' => 'mariadb', 'host' => 'localhost', 'database' => 'myapp', 'username' => 'root', 'password' => 'secret']);
```

The driver name is `mariadb`. `mysql` - the name before 3.0 - and the names of the removed drivers (`pgsql`, `postgres`, `postgresql`, `sqlite`) throw a `ConnectionException` that says so; a missing or unknown name throws as well.

`mariadb()` and `connect()` use what they are given and nothing else. They never read the environment: a required value that was not passed throws a `ConnectionException`, whatever `DB_HOST` says - and so does an empty `host`, `database` or `username` (pdo_mysql would take them for the local socket, no database and an anonymous login); an empty `password` is a password.

### Environment Variables

`Database::fromEnv()` is the one place in the library that reads the environment:

```php
$db = Database::fromEnv();                                  // driver and connection values from the environment
$db = Database::fromEnv(['password' => $secret]);           // the password from a secret store, the rest from the environment
$db = Database::fromEnv(['driver' => 'mariadb', 'charset' => 'utf8mb4']);
```

| Variable | |
|---|---|
| `DB_DRIVER` | `mariadb` (the name `connect()` accepts) |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` | required |
| `DB_PASSWORD` | optional |
| `DB_PORT` | optional, 3306 without it |

The list is complete: no other variable is read, and none of these anywhere else.

**Priority:** `$overrides` > `$_ENV` > the process environment. A key that is passed counts instead of its variable, also with `null` or an empty value: `fromEnv(['password' => null])` connects without a password whatever `DB_PASSWORD` says. The keys are those of `connect()`; `charset`, `options` and `pdoClass` have no variable and can only be passed. `$_ENV` is checked first (thread-safe), then `getenv($name, true)`.

The second source is the process environment and nothing else: `getenv()` is asked with `local_only`. Without it PHP asks the web server module first, and that answers with what came with the request - under PHP-FPM the FastCGI parameters, every request header among them as `HTTP_*`. A `DB_*` value that is only a FastCGI parameter (`fastcgi_param DB_HOST ...;` in nginx) or an Apache `SetEnv` is therefore not found there. Set it where the process gets it - `env[DB_HOST] = ...` in the FPM pool, the service's or the container's environment - or load it into `$_ENV`. One thing the library cannot change: with `E` in `variables_order` (PHP's default without a `php.ini`; `php.ini-production` and `php.ini-development` leave it out) PHP-FPM fills `$_ENV` with the request's parameters as well, so a `fastcgi_param DB_HOST` still arrives through `$_ENV` there. A client cannot use that: what it sends arrives as `HTTP_*`, never as `DB_*`.

A variable that is set but empty counts as not set (`DB_HOST=` in a dotenv template): an empty value in `$_ENV` leaves the process environment to answer, and a required value that has none in either is reported as missing instead of connecting with an empty one. Use a library like [sodaho/env-loader](https://github.com/sodaho/env-loader) to load `.env` files.

### The PDO Class

The library creates the PDO object itself, with its defaults. `pdoClass` names the class it creates it as: `PDO` by default, otherwise any class that extends `PDO`. It is a key of `mariadb()`, `connect()` and of the overrides of `fromEnv()`; `getPdo()` returns the object.

```php
$db = Database::mariadb($config + ['pdoClass' => Pdo\Mysql::class]);   // getPdo() has getWarningCount() and the like
```

The class is created with the arguments PDO's constructor takes (DSN, user name, password, options) and has to pass them on to it; a constructor of its own should mark the password `#[\SensitiveParameter]` as PDO's does, or it shows up in stack traces. A value that is not the name of a class that extends `PDO` and can be instantiated throws a `ConnectionException` before anything is connected; `getDebugMessage()` names the key, not the value. A class PDO refuses for the driver (`Pdo\Pgsql`) is a failed connection. The class is never read from the environment: a class name from there would be handed the credentials.

**A COMMIT that fails, in a test.** `beginTransaction()`, `commit()` and `rollback()` are `final`: a test cannot override them to see what the application does when a COMMIT fails after the callback has returned. Give the connection a class whose `commit()` fails on demand instead - the test then runs the library's own commit path, on the connection everything else uses:

```php
final class SwitchablePdo extends PDO
{
    public ?Throwable $commitFailure = null;

    public function commit(): bool
    {
        if ($this->commitFailure !== null) {
            [$failure, $this->commitFailure] = [$this->commitFailure, null];

            throw $failure;
        }

        return parent::commit();
    }
}

$db = Database::mariadb($config + ['pdoClass' => SwitchablePdo::class]);
$pdo = $db->getPdo();
assert($pdo instanceof SwitchablePdo);

$pdo->commitFailure = new PDOException('COMMIT failed');
$db->transaction($callback);   // the callback returns, then: CommitFailedException, $outcome 'rolled_back'
```

| `commit()` of the class | `transaction()` throws | `transaction.end` |
|---|---|---|
| throws a `PDOException` | `CommitFailedException` with it as `getPrevious()`, `$outcome` `rolled_back` | `rolled_back`, after the `transaction.rollback` listeners, the exception as `error` |
| returns `false` | `CommitFailedException`, `$outcome` `rolled_back` | the same |
| throws anything else | that object itself, unchanged | `rolled_back`, after the `transaction.rollback` listeners, the object as `error` |
| one of these three, and `rollBack()` of the class fails too (throws a `PDOException` or returns `false`) | the same exception; the `$outcome` of the library's `CommitFailedException` is `lost` | `lost`; no `transaction.rollback` listener runs |
| commits (`parent::commit()`), then throws a `PDOException`: a COMMIT that took effect but is reported as failed | `CommitFailedException`, `$outcome` `lost` - PDO reports no transaction any more, nothing is rolled back | `lost`, the exception as `error`; no `transaction.rollback` listener runs |

No `transaction.commit` listener runs in any of them. In the fourth row no ROLLBACK was sent, so the transaction is still open on the server: end it with `getPdo()->rollBack()` - once the class lets it through - before the connection is used again. In the last row the COMMIT took effect: nothing is open and the rows are written - `lost` says that the library cannot know, which is what a COMMIT whose answer never arrives looks like. "Anything else" includes the exceptions of this library: a `CommitHookException` or a `CommitFailedException` that the class throws is passed on like any other object, the transaction is rolled back, and no `$outcome` is written into it - the library only speaks for the exceptions it built itself. A `commit()` called directly hands the failure on (`$outcome` is still `null`) and leaves the transaction to the caller, whose `rollback()` ends it - unless PDO reports no transaction after it, as in the last row: then `$outcome` is `lost`, as `transaction.end` has told. On a session switched to `completion_type` `CHAIN` or `RELEASE` after it connected, the first three rows end as `lost` instead, and in the last row under `CHAIN` PDO reports the chained transaction, so the ROLLBACK is sent and confirms nothing (`lost` all the same): after a failed COMMIT the library asks the server for the session's `completion_type`, and on anything but `NO_CHAIN` it cannot tell a COMMIT that failed from one that took effect - under `CHAIN` PDO then reports the next transaction, under `RELEASE` the server has closed the connection (see [Transactions](#transactions)). These are simulations - the class decides what PDO reports, not what the server did; what the databases really do with a COMMIT is described under [Transactions](#transactions).

### Reconnecting

A connection that cannot be cleaned up any more - a COMMIT that failed, a transaction a listener left open, a state that cannot be read, a session that chains transactions - can be discarded: `reconnect()` continues on a new connection, opened with the settings the driver was created with (the same DSN, credentials, `options` and `pdoClass`), and with `completion_type` `NO_CHAIN` again.

```php
$db->reconnect();
```

- The new connection is opened first. When that fails, a `ConnectionException` reaches the caller and nothing has changed: the old connection is still in place.
- Otherwise a `ROLLBACK` is sent on the old connection when it reports a transaction (best effort: it frees the transaction's locks), and the driver continues on the new one. A transaction whose end was still owed ends as `lost`, with its number and a `TransactionException` ("Transaction discarded with its connection") as `error`; no `transaction.rollback` listener runs. `reconnect()` commits nothing of it - but what an implicit commit (a DDL statement) committed before is committed, which is why the end is `lost`. `inTransaction()` is `false` afterwards (unless an end listener of that `lost` began a transaction, or an error handler inside a call into the old connection reconnected itself and began one on its new connection: those stay).
- What belonged to the old session is gone with it: **settings made with SQL** (`SET SESSION sql_mode = ...` sent through `execute()`), temporary tables, user variables. So is what was done to the old PDO object after the driver created it: attributes set with `getPdo()->setAttribute()`, a PDO object a subclass put in its place - the new one is created from the settings alone. Give session settings to the connection as options instead - `Pdo\Mysql::ATTR_INIT_COMMAND` runs on every connect, the first and every reconnect:

  ```php
  $db = Database::mariadb($config + ['options' => [Pdo\Mysql::ATTR_INIT_COMMAND => "SET SESSION sql_mode = 'STRICT_TRANS_TABLES'"]]);
  ```
- `getPdo()` returns the new PDO object. The old connection closes when nothing holds it any more; the driver itself keeps nothing of it (but see the error handler below). Whoever still holds a part of it keeps it open until they drop it: a reference to the old PDO object from an earlier `getPdo()`; a `PDOStatement` of it; an exception whose trace holds one of them, directly or through another exception - a trace keeps the arguments of the calls the exception was thrown through unless `zend.exception_ignore_args` is on (off is PHP's default without a php.ini): the exception of a statement that failed on it, the `error` the end listeners of a refused `commit()` are told and the `CommitFailedException` the caller gets, and the `error` of the `lost` that `reconnect()` tells when it is called from where such an exception is an argument (an `error` listener, a helper handed the failed query's exception) - an end listener that keeps that `error` keeps the old connection -; and the call `reconnect()` is made from: `query()` holds its statement while its `query` and `error` listeners run (after a failed prepare there is none), an error handler runs inside the call into PDO. Session locks (`GET_LOCK()`) and temporary tables of the old session last as long as its connection; the locks of its transaction end with the `ROLLBACK` sent there - when that ROLLBACK fails (it is best effort), they too last as long as the connection. An error handler that reconnects in the middle of a statement and begins a transaction there goes further: the failure of that statement may be remembered for the new transaction, and the old statement with it, until that transaction ends - a deadlock or a 1020 of the old connection then refuses the new transaction's statements and its `commit()` until `rollback()`. Reconnect after the call instead.
- Named locks taken with `namedLock()` go with the old session: while the driver holds one (`heldNamedLocks()`), `reconnect()` throws a `NamedLocksHeldException` (a `ConnectionException`, the names in `$lockNames`) and nothing changes. Release them first, or call `reconnect(dropNamedLocks: true)` to give them up knowingly - on a connection that is gone, `releaseNamedLock()` cannot reach the server any more. A `reconnect()` from a `query` listener of a named-lock statement - after the statement ran, before its method returns - is refused as well, `dropNamedLocks` or not: the answer and what was recorded belong to the session the statement ran on. From a `query.before` listener the statement simply runs on the new session; from an `error` listener after its failure nothing was recorded, and the reconnect goes through. These refusals come first: nothing has changed.
- A persistent connection (`PDO::ATTR_PERSISTENT`) cannot be discarded - PDO hands the same connection back for the same settings: `reconnect()` throws a `ConnectionException` instead.
- A driver keeps its credentials and options for this, in a form that `var_dump()`, `print_r()`, `var_export()` and `json_encode()` of the driver do not show. A custom driver that sets its PDO object itself (`$this->pdo = ...`, without passing a connector to `AbstractDriver::__construct()`) cannot reconnect: `reconnect()` throws a `ConnectionException`.

## Raw Queries

```php
// SELECT query (the returned PDOStatement is raw PDO: re-executing it skips the hooks and binds a boolean false as '')
$stmt = $db->query('SELECT * FROM users WHERE id = ?', [1]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// INSERT/UPDATE/DELETE (returns affected rows)
$affected = $db->execute('UPDATE users SET active = ? WHERE id = ?', [1, 5]);

// Get last insert ID
$id = $db->lastInsertId();

// Access underlying PDO — for features not covered by the wrapper
// (e.g., LOCK TABLES, driver-specific methods, passing PDO to third-party tools);
// execute([...]) on raw PDO binds every value as text, a boolean false as ''
$pdo = $db->getPdo();
```

## CRUD Methods

### Insert

```php
$id = $db->insert('users', [
    'name' => 'John',
    'email' => 'john@example.com',
]);

// Conditional insert in one statement: 1 when inserted, 0 when the condition failed
$inserted = $db->insertWhen('codes', ['user_id' => 7, 'code' => 'abc'], 'NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL)', [7]);

// Insert unless the row exists already: 1 when inserted, 0 when a unique key or the primary key collided
$inserted = $db->insertIgnore('subscriptions', ['user_id' => 7, 'topic' => 'news']);
```

`insert()` returns the generated id as an integer, and `0` when the database generated none (a table without `AUTO_INCREMENT`). An id that is no integer of PHP throws a `QueryException` (`Insert ID out of range`, the id in `getDebugMessage()`) instead of being cut: MariaDB reports a `BIGINT UNSIGNED` id above `PHP_INT_MAX` as it is, and a negative id written into an `AUTO_INCREMENT` column as a number of that size. The row is inserted at that point - inside `transaction()` the exception rolls it back, outside it stays -, and no `error` hook fires: the statement did not fail. Write such rows with `execute()` and read the id with `lastInsertId()`, which returns PDO's string.

`insertIgnore()` skips the row on a duplicate of **any** unique key or of the primary key and throws for everything else (`NOT NULL`, a foreign key, an unknown column), like `insert()`. It returns the number of inserted rows, not an id; a skipped insert may still use up an auto-increment value. It renders `ON DUPLICATE KEY UPDATE col = col` on the row's first column (not `INSERT IGNORE`, which would also swallow other errors): the existing row stays locked until the transaction ends, the table's `BEFORE INSERT` and update triggers run for it although nothing is updated (a `BEFORE UPDATE` trigger that changes the row does change it; the result is still 0), and on a connection opened with the PDO option `ATTR_FOUND_ROWS` the method throws, because the server then reports one affected row for an existing row as well - and so it does on a persistent connection (`PDO::ATTR_PERSISTENT`), which PDO may hand back opened with that option by an earlier request (its pool ignores the options; measured). To find out *which* key collided, use `insert()` and catch `UniqueViolationException` (see [Exceptions](#exceptions)).

Values are bound as prepared-statement parameters; a boolean arrives as `1`/`0`, so `['active' => false]` works in `insert()`, `update()` and `where()` alike (a text or binary column stores `'0'`; a `BIT` column does not store a bound `0`/`1` as bits, use `Database::raw('0')` or a `TINYINT(1)` column).

A value must be `null`, a scalar or a `Stringable` object, and a float a finite one. An array, a resource or any other object (a `DateTime`, an enum) throws a `QueryException` before the statement is sent - PDO would store an array as the text `Array` -, and so does `INF` or `NAN`: MariaDB has no such number, and sent as text it would compare as 0. Pass `$date->format('Y-m-d H:i:s')`, `$enum->value`, `json_encode($array)`. This holds for every method and for `query()`/`execute()`. There, a `Database::raw()` expression as a parameter throws as well (it would be bound as its own text): write it into the SQL. A custom driver that binds more (a stream as LOB) overrides `unbindableParameter()` along with `bindAndExecute()`.

### Update

```php
// Returns affected rows
$affected = $db->update('users',
    ['name' => 'Jane'],           // data
    ['id' => 1]                   // where
);
```

### Delete

```php
// Returns affected rows
$affected = $db->delete('users', ['id' => 1]);
```

### Find

```php
// Find one record
$user = $db->findOne('users', ['id' => 1]);

// Find all matching records
$users = $db->findAll('users', ['active' => 1]);

// Find all records in table
$users = $db->findAll('users');
```

The `$where` array of `update()`, `delete()`, `findOne()` and `findAll()` knows **equality only**: every entry is `column = value`, joined with AND, and a `null` value throws (`= NULL` matches nothing). Everything else - `IS NULL`, comparisons (`<`, `>=`, `!=`), `IN`, `BETWEEN`, `LIKE`, the null-safe `IS` - is what the [Query Builder](#query-builder) is for, with the same table and the same return values:

```php
$db->table('sessions')->where('expires_at', '<', $db->now())->delete();
$db->table('users')->whereNull('verified_at')->whereIn('role', ['guest', 'trial'])->update(['active' => 0]);
$count = $db->table('users')->whereLike('email', '%@example.com')->count();
```

`update()` and `delete()` return the number of affected rows as MariaDB counts them: an `UPDATE` counts the rows it actually **changed** (0 when the row already had the values), not the rows it matched. Do not use the return value of an update as "the row exists"; ask with `exists()`.

### Update Multiple

```php
$db->updateMultiple('users', [
    ['id' => 1, 'name' => 'John'],
    ['id' => 2, 'name' => 'Jane'],
], 'id');  // key column
```

A row that holds nothing but the key column is skipped (nothing to update). **Note:** This method executes one UPDATE query per row within a transaction. Best suited for batch sizes under ~100 rows. For larger datasets, consider `execute()` with a bulk statement (`INSERT ... ON DUPLICATE KEY UPDATE`).

## Query Builder

### Basic Select

```php
// Get all
$users = $db->table('users')->get();

// Get first
$user = $db->table('users')->first();

// Select specific columns
$users = $db->table('users')
    ->select(['id', 'name', 'email'])
    ->get();

// Select with string
$users = $db->table('users')
    ->select('id, name, email')
    ->get();

// Distinct
$names = $db->table('users')
    ->select('name')
    ->distinct()
    ->get();
```

### Where Conditions

```php
// Basic where
$users = $db->table('users')
    ->where('active', 1)
    ->get();

// With operator
$users = $db->table('users')
    ->where('age', '>=', 18)
    ->get();

// Multiple conditions (AND)
$users = $db->table('users')
    ->where('active', 1)
    ->where('role', 'admin')
    ->get();

// Array syntax
$users = $db->table('users')
    ->where(['active' => 1, 'role' => 'admin'])
    ->get();

// Where In
$users = $db->table('users')
    ->whereIn('id', [1, 2, 3])
    ->get();

// Where Not In
$users = $db->table('users')
    ->whereNotIn('status', ['banned', 'deleted'])
    ->get();

// Where Between
$users = $db->table('users')
    ->whereBetween('age', [18, 65])
    ->get();

// Where Not Between
$users = $db->table('users')
    ->whereNotBetween('created_at', ['2020-01-01', '2020-12-31'])
    ->get();

// Where Null
$users = $db->table('users')
    ->whereNull('deleted_at')
    ->get();

// Where Not Null
$users = $db->table('users')
    ->whereNotNull('email_verified_at')
    ->get();

// Where Like
$users = $db->table('users')
    ->whereLike('name', '%john%')
    ->get();

// Where Not Like
$users = $db->table('users')
    ->whereNotLike('email', '%spam%')
    ->get();
```

The array form needs column names as keys; a list (`where(['active', 1])`) or a numeric column name as key throws a `QueryException` (use `where('2024', $value)` for the latter). `whereBetween()`/`whereNotBetween()` throw on a `null` bound: `BETWEEN` with NULL matches no row; an open range is a `where()` with `>=` or `<=`. `whereIn()`/`whereNotIn()` throw on a `null` element: `IN` never matches NULL, and `NOT IN` with a NULL in the list matches no row at all - add `whereNull()`/`whereNotNull()` for it. Every element is a placeholder, and MariaDB takes at most 65,535 of them in one statement: a longer list ends in the server's error 1390 (a `QueryException`) - split it, or use a temporary table and a join.

`IS` and `IS NOT` with a bound value compare **null-safely**: `where('nick', 'IS NOT', 'anna')` also matches rows whose `nick` is NULL, and the value itself may be `null` - `where('parent_id', 'IS', $parentId)` finds the rows with that parent, or the rows without one when `$parentId` is null. The builder renders them as `<=>` (`NOT (... <=> ...)` for `IS NOT`). With a raw value (`where('flag', 'IS', Database::raw('TRUE'))`) the SQL is passed through unchanged, so truth tests keep their database semantics. For a plain NULL test use `whereNull()` / `whereNotNull()`.

`whereRaw()` takes a condition the other methods cannot express - an OR group, a database function with values of its own - (an expression without bindings on the left goes into `where()` itself: `where(Database::raw('LOWER(email)'), $email)`) with its values bound in order; it is joined to the other conditions with AND, in parentheses. The SQL is trusted developer code (never build it from user input; user input goes into the bindings):

```php
$users = $db->table('users')
    ->where('status', 'active')
    ->whereRaw('LOWER(email) = ?', [$email])
    ->whereRaw('score > ? OR created_at < ?', [100, $cutoff])
    ->get();
// SELECT * FROM `users` WHERE `status` = ? AND (LOWER(email) = ?) AND (score > ? OR created_at < ?)
```

### Joins

```php
// Inner Join
$posts = $db->table('posts')
    ->select(['posts.title', 'users.name as author'])
    ->join('users', 'users.id', '=', 'posts.user_id')
    ->get();

// Left Join
$users = $db->table('users')
    ->select(['users.name', 'posts.title'])
    ->leftJoin('posts', 'posts.user_id', '=', 'users.id')
    ->get();

// Right Join
$posts = $db->table('posts')
    ->rightJoin('users', 'users.id', '=', 'posts.user_id')
    ->get();
```

### Ordering, Limit, Offset

```php
$users = $db->table('users')
    ->orderBy('name', 'ASC')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->offset(20)
    ->get();
```

The direction is `ASC` or `DESC` (any case, surrounding whitespace ignored); anything else (`'DESCENDING'`, `'down'`) throws a `QueryException` instead of silently sorting ascending.

A string is always a column name, quoted - also one that came from a request, and `'title as x'` is the name of one column there, not an alias. To order by an expression, pass it as an object; it may not carry bindings:

```php
$db->table('tasks')->orderBy(Database::raw("FIELD(status, 'open', 'blocked', 'done')"))->get();
$db->table('users')->select(['id', Database::raw('LENGTH(name) AS name_length')])->orderBy(Database::raw('name_length'), 'DESC')->get();
```

`offset()` works without `limit()` (the builder adds the "no limit" value MariaDB requires). A negative `limit()` or `offset()` throws a `QueryException`.

### Row Locks

Inside a transaction, `lockForUpdate()` locks the selected rows until the commit; `sharedLock()` keeps others from updating them while still allowing reads:

```php
$db->transaction(function ($db) use ($id) {
    $account = $db->table('accounts')->where('id', $id)->lockForUpdate()->first();
    $db->update('accounts', ['balance' => $account['balance'] - 10], ['id' => $id]);
});
```

The builder renders `FOR UPDATE` / `LOCK IN SHARE MODE`. `exists()` (`SELECT 1 ... LIMIT 1`) and the aggregates keep the lock: `count()` under `lockForUpdate()` locks the rows it reads - in `REPEATABLE READ`, the default, the gaps between them too -, so "count, then insert" in one transaction is not overtaken by another transaction's insert. A lock combined with `distinct()`, `groupBy()` or `having()` throws a `QueryException`, for the aggregates too: such a result is not the rows the lock would hold.

### Group By, Having

```php
use Sodaho\PdoWrapper\Database;

$stats = $db->table('posts')
    ->select(['user_id', Database::raw('COUNT(*) as post_count')])
    ->groupBy('user_id')
    ->having(Database::raw('COUNT(*)'), '>', 5)
    ->get();

// Group by an expression: Database::raw(), alone or inside the array
$perDay = $db->table('orders')
    ->select([Database::raw('DATE(created_at) AS day'), Database::raw('COUNT(*) AS orders')])
    ->groupBy(Database::raw('DATE(created_at)'))
    ->get();
```

A string given to `groupBy()` (and `select()`) is split at commas into column names, so an expression belongs in `Database::raw()`. `having()` with `null` throws a `QueryException` (`= NULL` is never true) unless the operator is `IS` or `IS NOT`, the null-safe comparison. A string column of `having()` is a name: one with an expression in it (`'COUNT(*)'`) throws a `QueryException` - pass `Database::raw('COUNT(*)')`, or an alias.

### Aggregates

```php
$count = $db->table('users')->count();
$count = $db->table('users')->where('active', 1)->count();

$sum = $db->table('orders')->sum('total');
$avg = $db->table('orders')->avg('total');
$min = $db->table('orders')->min('total');
$max = $db->table('orders')->max('total');

$exists = $db->table('users')->where('email', 'test@example.com')->exists();

// Distinct values and groups
$countries = $db->table('users')->select('country')->distinct()->count();          // number of distinct countries
$authors   = $db->table('posts')->groupBy('user_id')->having(Database::raw('COUNT(*)'), '>', 5)->count(); // groups with more than 5 posts
$revenue   = $db->table('orders')->distinct()->sum('amount');                      // SUM(DISTINCT amount)
```

`sum()` and `avg()` return the number as the database delivers it, not converted to a float: what the database computed exactly - a sum of `BIGINT` values beyond 2^53, a `DECIMAL` sum of money - arrives exactly. MariaDB delivers a numeric string for integer and `DECIMAL` columns and a float for `FLOAT`/`DOUBLE` (see [What Comes Back](#what-comes-back)), and `null` when there is nothing to add up (no rows, only `NULL`, or a `having()` without `groupBy()` that filtered out the one group - `min()` and `max()` then too); any other type throws a `QueryException` instead of passing for "no value". Cast where a number is wanted - `(float) $db->table('orders')->sum('total')` -, or hand the string to an arbitrary-precision function (`bcadd()`).

`sum()`, `avg()`, `min()` and `max()` combined with `groupBy()` throw a `QueryException`: one value per group is ambiguous, select the aggregate explicitly with `Database::raw()` and `get()` instead. `distinct()->count()` counts a derived table, which needs unique output names (MariaDB rejects repeated ones): two columns named alike (`users.id`, `orders.id`), a wildcard next to other entries, or a bare `*` over a join throw a `QueryException` - alias the columns (`orders.id as order_id`) or use `count('column')`; a single `table.*` is fine, `Database::raw()` entries are not inspected. With `groupBy()`, only aliased `select()` entries (`'country as c'`, `Database::raw('LOWER(name) AS ln')`, `Database::raw('COUNT(*) AS n')`) stay in the counted query, so `groupBy('ln')` and `having('n', '>', 1)` work (MariaDB accepts select aliases in `GROUP BY` and `HAVING`); MariaDB compares aliases without case, quoted or not, so `'country as C'` and `Database::raw('COUNT(*) AS c')` are one name. `having()` without `groupBy()` treats the whole result as one group: `count()` returns its row count, and `distinct()` only applies to `count('column')` then.

### JSON Values

`Database::json($column, $path)` is a value inside a JSON column, as text - ``JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net'))`` - for `where*()`, `select()` (named with `->as()`), `groupBy()` and `orderBy()`; `->orColumn('ip')` falls back to a column where the document has no value (``COALESCE(..., `ip`)``):

```php
use Sodaho\PdoWrapper\Database;

$db->table('events')->where(Database::json('payload', '$.user.id'), '7')->count();

$net = Database::json('payload', '$.net')->orColumn('ip');
$rows = $db->table('events')
    ->select([$net->as('net'), Database::raw('COUNT(*) AS n')])
    ->groupBy($net)
    ->having('n', '>', 1)        // in having(), the alias: MariaDB does not resolve the JSON column there
    ->orderBy($net)
    ->get();
```

The path is written into the SQL, not bound (bound, MariaDB rejects a `GROUP BY` on it under `ONLY_FULL_GROUP_BY`), so it is checked: `$` followed by `.name` and `[n]` steps, nothing else (`$.items[0].id`; an index has up to 9 digits - MariaDB reads a larger one modulo 2^32); anything else throws a `QueryException`. What comes back is text: `'a'`, `'5'`, `'1.50'`, `'true'`; a missing field, a document that is no valid JSON and a `NULL` column give `null` (such a row matches no comparison but `IS` / `IS NOT`; `whereNull()` finds it); a JSON `null` gives the text `'null'`.

Compared and ordered as text, too, under the binary collation the JSON functions return (`utf8mb4_bin`, measured on 10.11, 11.4 and 12.3): `where(Database::json('payload', '$.n'), '>', 5)` does not find 12 (`'12'` sorts before `'5'`), `'1.5'` does not equal `'1.50'`, and case and accents count in `=` and `LIKE`. For a field that holds numbers, cast: `Database::raw('CAST(' . Database::json('payload', '$.n') . ' AS DECIMAL(20,6))')` is an expression without bindings and works as a column like the JSON value itself - but text, `'true'` and JSON `null` cast to 0 (a warning only), and `DECIMAL(20,6)` rounds to 6 places. In strict mode (MariaDB's default) an `update()` or `increment()` whose condition reads a document that is no valid JSON fails with error 4038 - a select or delete only warns; a `JSON` column keeps invalid documents out.

An index: declare a virtual column with the same expression and the collation the JSON functions return, and index it - `net VARCHAR(64) COLLATE utf8mb4_bin AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.net'))) VIRTUAL, INDEX (net)`. From MariaDB 11.8 a `where()` on `Database::json('payload', '$.net')` uses that index (measured on 12.3; not with another collation, not for `orderBy()`); before 11.8, query the column itself (`where('net', ...)`).

### Insert, Update, Delete via Query Builder

```php
// Insert
$id = $db->table('users')->insert([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// Update (requires where)
$affected = $db->table('users')
    ->where('id', 1)
    ->update(['name' => 'Jane']);

// Delete (requires where)
$affected = $db->table('users')
    ->where('id', 1)
    ->delete();

// Delete in batches, oldest first: DELETE ... ORDER BY ... LIMIT. limit() needs an orderBy() -
// order by a unique key (or add one as tie-breaker) so that each batch is deterministic.
// Without an orderBy() it throws: which rows it hit would be up to the server.
$deleted = $db->table('logs')
    ->where('created_at', '<', $cutoff)
    ->orderBy('created_at')
    ->orderBy('id')
    ->limit(500)
    ->delete();

// The same for update(): UPDATE ... ORDER BY ... LIMIT
$claimed = $db->table('jobs')
    ->where('status', 'queued')
    ->orderBy('id')
    ->limit(10)
    ->update(['status' => 'claimed']);

// Add to a column in place, atomically: UPDATE ... SET `attempts` = `attempts` + CAST(? AS SIGNED)
$db->table('login_codes')->where('id', $id)->increment('attempts');
$db->table('devices')->where('user_id', $userId)->increment('generation', 1, ['updated_at' => $db->now()]);
$db->table('slots')->where('id', $id)->where('used', '>', 0)->decrement('used');

// A list that came back empty matches nothing: the statement hits no row
$db->table('grants')->whereIn('user_id', $userIds)->delete();   // [] deletes nothing

// Insert only when a condition holds, in one statement:
// the row's values are bound first, then the condition's bindings
$inserted = $db->table('codes')->insertWhen(
    ['user_id' => $userId, 'code' => $code],
    'NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL)',
    [$userId]
); // 1 or 0

// Insert unless a unique key or the primary key collides
$inserted = $db->table('subscriptions')->insertIgnore(['user_id' => $userId, 'topic' => 'news']); // 1 or 0

// Insert, or change the row it collides with: 1 inserted, 2 updated, 0 unchanged
$db->table('login_attempts')->upsert(
    ['ip' => $ip, 'window' => $window, 'count' => 1],
    ['count' => Database::raw('count + 1')]
);

// The same, returning the row after the statement
$row = $db->table('login_attempts')->upsertReturning(
    ['ip' => $ip, 'window' => $window, 'count' => 1],
    ['count' => Database::raw('IF(count < ?, count + 1, count)', [$max])],
    ['id', 'count']
);

// insertWhen() with an update, returning the row - or null when the condition was false
$row = $db->table('shares')->insertWhenReturning(
    ['user_id' => $userId, 'n' => 1],
    '? = 1',
    [$allowed ? 1 : 0],
    ['n' => Database::raw('n + 1')],
    ['n']
);
```

`upsert()` renders `INSERT INTO login_attempts (ip, window, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = count + 1` (names quoted); `upsertReturning()` adds `RETURNING id, count`, `insertWhen()` with an update `INSERT ... SELECT ... FROM DUAL WHERE (condition) ON DUPLICATE KEY UPDATE ...`. MariaDB has no conflict target: a collision on **any** unique key or the primary key counts as the duplicate. The update is rendered in the order of the array and applied from left to right - a later assignment sees what an earlier one set (`n = n + 1, m = n` gives `m` the new `n`); a value may be `Database::raw()` with bindings, and `Database::value('col')` is the value the row would have been inserted with (`VALUE(col)`). Bound in the order of the SQL: the row, the condition, the update. The returning forms take column names, `'*'` (the default) or expressions without bindings (`Database::raw('n * 2 AS twice')`); `upsertReturning()` returns the row in every case - inserted, updated or unchanged -, `insertWhenReturning()` null when the condition was false. On a connection opened with `Pdo\Mysql::ATTR_FOUND_ROWS` an unchanged row counts 1 like an insert: `upsert()` and `insertWhen()` with an update throw there, and on a persistent connection, which an earlier request may have opened with that option (the returning forms work). The update runs the table's update triggers and locks the existing row until the transaction ends; a `BEFORE UPDATE` trigger that changes the row makes an unchanged upsert count 2. `Database::value()` belongs in the update only: elsewhere - `update()`, `insert()`, the `$row` - MariaDB reads `VALUE(col)` as `NULL`.

`insertWhen()` renders `INSERT INTO codes (...) SELECT ?, ? FROM DUAL WHERE (condition)`. The condition is trusted developer SQL, like `whereRaw()`: never build it from user input. Check and insert see one snapshot, but two concurrent calls can still both insert: an invariant like "one open code per user" needs a `UNIQUE` constraint, a row lock (`lockForUpdate()` on the user row) or `SERIALIZABLE` on top. After a return of 0, `lastInsertId()` is meaningless. Clauses set on the builder (`where*()`, joins, `groupBy()`/`having()`, `orderBy()`, `limit()`/`offset()`, `distinct()`, locks) are not part of the statement and make `insertWhen()`, `insertWhenReturning()`, `insertIgnore()`, `upsert()` and `upsertReturning()` throw; a `select()` is ignored.

`increment()` and `decrement()` follow the rules of `update()` (a `where()` is required; `limit()` with `orderBy()`), set `$extra` in the same statement after the column, and return the affected rows. The step is bound and cast, so that the server adds it exactly (`col + CAST(? AS SIGNED)` for an `int`, `CAST(? AS DECIMAL(65,30))` for a `float`): bound as text it would make MariaDB add in `DOUBLE`, and a `BIGINT` beyond 2^53 or a `DECIMAL` with more digits than a double holds would come back rounded. A `float` is bound as the shortest decimal text that reads back as the same float (`0.1`; not as PHP's `precision` setting writes it, which cuts after 14 digits); one whose text has more than 35 integer or 30 fraction digits, `INF` and `NAN` throw a `QueryException` - add it with `update()` and `Database::raw()`. A column that is `NULL` stays `NULL` (`NULL + 1` is `NULL`) - the row then counts as changed only when `$extra` changes something (or the connection counts matched rows, `ATTR_FOUND_ROWS`); `update(['n' => Database::raw('COALESCE(n, 0) + CAST(? AS SIGNED)', [1])])` starts it from 0. `$extra` must not set the column itself, also not in another case or as `table.column` (MariaDB takes those for the same column, and the step would be replaced); with `$extra`, a column name beyond ASCII throws, because MariaDB folds the case of such names by rules that differ between versions. `whereIn()` with an empty list renders `1 = 0`; `whereNotIn()` with an empty list throws - "not in nothing" would match every row, in a `delete()` every row of the table.

### Debug Query

```php
[$sql, $params] = $db->table('users')
    ->where('active', 1)
    ->orderBy('name')
    ->toSql();

// $sql = 'SELECT * FROM `users` WHERE `active` = ? ORDER BY `name` ASC'
// $params = [1]
```

## Timestamps and Raw Values

`now()` and `utcNow()` return the database's current time (at statement time, to the second) as a raw SQL expression - `NOW()` and `UTC_TIMESTAMP()` -, so the clock of the database server counts, not PHP's:

```php
$db->insert('logs', ['message' => 'started', 'created_at' => $db->utcNow()]);
$db->table('sessions')->where('expires_at', '<', $db->now())->delete();
```

`now()` is the database session's time zone, not PHP's `date.timezone`. When PHP and the database may run in different zones, `utcNow()` is the unambiguous choice. Both are **zoneless** values, meant for `DATETIME` columns: a `TIMESTAMP` column would interpret `utcNow()` in the session's time zone and store a shifted instant unless the session runs in UTC.

Any `Database::raw()` expression works the same way as a **value** in `insert()`, `update()`, `where()`, `whereIn()`, `whereBetween()` and `having()`, in the CRUD methods and in the query builder alike. It is inlined into the SQL instead of being bound, which allows expressions on the row itself (the bound escape character of `LIKE`, see [LIKE Patterns with User Input](#like-patterns-with-user-input), applies to raw patterns too):

```php
$db->update('counters', ['hits' => Database::raw('hits + 1')], ['id' => $id]);
$db->table('jobs')->where('attempts', '<', Database::raw('max_attempts'))->get();
```

A raw value may carry values of its own: `?` placeholders in the SQL and their values as the second argument. They are bound exactly where the expression stands among the statement's other values - in an update in the order of the SET list, before the WHERE values:

```php
$db->table('outbox')->where('id', $id)->update([
    'attempts' => Database::raw('attempts + 1'),
    'next_attempt_at' => Database::raw('? + LEAST(14400, 60 * POW(4, attempts - 1))', [time()]), // an integer column, seconds
    'status' => 'retry',
]);
// UPDATE `outbox` SET `attempts` = attempts + 1, `next_attempt_at` = ? + LEAST(...), `status` = ? WHERE `id` = ?
// params: [time(), 'retry', $id]
```

The SET list is written in the order of the array, and that order matters: MariaDB evaluates the assignments left to right, so `next_attempt_at` above is computed from the **raised** `attempts` (standard SQL would compute every assignment from the row as it was). Only an expression used as a value may carry bindings: in `select()`, `groupBy()`, `orderBy()`, as the column of `having()` and of the `where*()` methods and among the columns of `RETURNING` it throws a `QueryException` (its values would have to be placed in front of all others) - use `whereRaw()` for a condition, or `query()` for the whole statement.

**Security Note:** the SQL of a raw value is not a bound parameter. Never build it from user input; user input goes into its bindings (see [Raw Expressions](#raw-expressions)).

## Transactions

```php
use Sodaho\PdoWrapper\Exception\CommitHookException;

// Automatic transaction with callback (auto-rollback on exception)
$db->transaction(function ($db) {
    $db->insert('users', ['name' => 'John']);
    $db->insert('profiles', ['user_id' => $db->lastInsertId()]);
});

// With return value - real world example
$orderId = $db->transaction(function ($db) use ($orderData, $items) {
    // Insert order
    $orderId = $db->insert('orders', [
        'user_id' => $orderData['user_id'],
        'total' => $orderData['total'],
        'status' => 'pending'
    ]);

    // Insert order items
    foreach ($items as $item) {
        $db->insert('order_items', [
            'order_id' => $orderId,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'price' => $item['price']
        ]);

        // Update inventory with raw query
        $db->execute(
            'UPDATE products SET stock = stock - ? WHERE id = ?',
            [$item['quantity'], $item['product_id']]
        );
    }

    return $orderId;  // Return value is passed through
});

// Manual transaction control
$db->beginTransaction();
try {
    $db->insert('users', ['name' => 'John']);
    $db->commit();
} catch (CommitHookException $e) {
    // Committed: never roll that back. Only what a commit hook left open may still need it
    if ($e->connectionInTransaction && $db->inTransaction()) {
        $db->rollback(); // or discard the connection
    }
    throw $e;
} catch (Throwable $e) {
    // A failed commit arrives here as CommitFailedException (see below).
    // Best effort: roll back only if still open, keep the original exception
    try {
        if ($db->currentTransaction() !== null || $db->inTransaction()) {
            $db->rollback();
        }
    } catch (Throwable) {
    }
    throw $e;
}
```

Ask both: `currentTransaction()` is the library's view, `inTransaction()` PDO's. After the server ended the transaction behind the library's back - a DDL statement committed it, a lock wait timeout under `innodb_rollback_on_timeout` rolled it back, raw PDO ended it - PDO reports none while the library still holds it and refuses every statement (it would run in autocommit), `releaseNamedLock()` included. `rollback()` is the way out: it sends nothing when PDO reports no transaction, tells the end as `lost`, and the connection works again. A transaction begun on raw PDO has no number: there `inTransaction()` decides.

`transaction()` ends in one of these ways (`updateMultiple()` too, when it opens its own transaction):

- **Success** - committed, the callback's return value is returned.
- **The transaction cannot be started** (`BEGIN` fails, a `transaction.begin` hook throws, or such a hook ends the transaction it was told about - then no further begin hook runs either) - the callback does not run; after a throwing hook a rollback is attempted (best effort) and `transaction.end` is told for that transaction (`rolled_back` when the rollback went through, `lost` when it did not); the exception is re-thrown, a `PDOException` from the hook as `TransactionException`. When `BEGIN` itself fails, there was no transaction: nothing is told.
- **The callback throws** - rollback attempted, the callback's exception is re-thrown unchanged. Best effort: if the rollback itself fails, the connection may still be in a transaction.
- **The commit fails** - rollback attempted, `CommitFailedException` (a `TransactionException`) is thrown. Its `$outcome` says what became of the transaction, in the words of `transaction.end`: `rolled_back` when the rollback after it is confirmed - nothing is committed - and `lost` when it is not: the commit may or may not have taken effect (e.g. connection lost during `COMMIT`, or a session switched to `completion_type=CHAIN`, see below).
- **The callback swallowed a statement error that ended the transaction on the server** - a deadlock (error 1213) rolls it back, and so does writing a row another transaction changed since this one read it (error 1020, under `innodb_snapshot_isolation`, on by default since MariaDB 11.6.2); the server would answer `COMMIT` with success although nothing of the transaction is committed. The library therefore refuses to send that `COMMIT`: `CommitFailedException`, `getPrevious()` is the statement's error. The rollback follows, and `transaction.end` and the exception's `$outcome` say `rolled_back` when it is confirmed - `lost` when the server reports the transaction gone already (nothing is left to roll back) or cannot be asked whether it still exists. A manual `commit()` stays refused until `rollback()`. After a deadlock or a 1020 the library accepts nothing on the connection but the end of that transaction: a statement would run outside of it and be committed on its own, so it throws a `QueryException` instead (`getPrevious()` is the failure; neither `query` nor `error` fires for it - `query.before` has), and `beginTransaction()` refuses. `rollback()` is the way out. Never carry on after a deadlock or a 1020: let the exception end the transaction and run it again. After any other failure inside a transaction the library began it asks the server right away (one extra statement, only then; asked again before the commit; not after error 2014, a statement sent while an unbuffered result is still open - the client refuses it before anything reaches the server: close the cursor and send it again): when the transaction is gone - a lock wait timeout under `innodb_rollback_on_timeout` rolled it back, a DDL statement sent on raw PDO committed it - or the server cannot be asked, nothing more is sent either (`QueryException`, `Not sent: ...`, `getPrevious()` is that failure), and the end is `lost`, never `rolled_back`. The same holds while PDO reports no transaction any more although the library's has not been ended (raw PDO ended it, a DDL statement there committed it): what would be sent then would run in autocommit. Raw PDO (`getPdo()`) is outside all of this: failures there are not seen, and a statement sent there after a deadlock is committed on its own - `commit()` is still refused, and with nothing left to roll back the refusal itself reports `transaction.end` as `lost`, on a manual `commit()` too. The same `lost` follows a swallowed lock wait timeout on a server that runs with `innodb_rollback_on_timeout`: the question right after it finds the transaction gone, and nothing more is sent. After a deadlock, end the transaction with `rollback()`: for a transaction begun through the library it also works when PDO no longer reports it (nothing is sent, the end is `lost`), and a transaction ended and begun again on raw PDO stays refused, statements included, until `rollback()` is called. All of this describes autocommit, the default: with autocommit switched off, a statement after the transaction's end opens the next transaction instead of being committed on its own - after a deadlock the refusals hold all the same and the rollback undoes it, and after a lock wait timeout that ended a transaction the library began (or a failure after a DDL statement on raw PDO), the question right after it opens no transaction (measured) and finds it gone just the same: nothing more is sent. Only in a transaction begun on raw PDO is such a timeout not detected once a later statement has opened the next transaction.
- **The callback sends a statement that commits implicitly** - on MariaDB DDL (`CREATE`, `ALTER`, `DROP`, `RENAME`, `TRUNCATE`; not `CREATE TEMPORARY TABLE` / `DROP TEMPORARY TABLE`), `LOCK TABLES` / `UNLOCK TABLES`, `ANALYZE` / `CHECK` / `OPTIMIZE` / `REPAIR TABLE`, the account statements (`CREATE USER`, `GRANT`, `REVOKE`, `SET PASSWORD`, ...), `FLUSH`, `RESET`, `CACHE INDEX`, `LOAD INDEX INTO CACHE` and the replication statements: MariaDB commits the transaction before such a statement runs, also when it then fails, and what the callback does afterwards would run in autocommit. Inside a transaction the library began such a statement is refused before it is sent: `ImplicitCommitException` (a `QueryException`; `$statement` names the leading keywords, `getMessage()` is static), only `query.before` fires, and the transaction stays open and intact - catch it and go on, or let it end the transaction. The statement is recognised by its leading keywords, past whitespace and comments (the content of an executable comment `/*! ... */` counts); `SET STATEMENT ... FOR <statement>` by the statement after `FOR`. Run such statements outside of transactions - there they run as before (migrations on raw PDO are not touched either). What runs inside a stored procedure (`CALL`), a prepared statement (`EXECUTE`) or a compound statement (`BEGIN NOT ATOMIC`) is not seen.
- **The callback steers the transaction with SQL** (`BEGIN`, `START TRANSACTION`, `COMMIT`, `ROLLBACK` - also `AND CHAIN` -, `SET autocommit`, `XA`, sent through `query()` or `execute()`) - not refused (decided 2026-10-03: raw transaction control is the caller's), and not seen: the outcome is then wrong, in both directions. `START TRANSACTION` commits the open transaction implicitly and opens the next one, which the library takes for its own - the callback below ends as `rolled_back`, although row 1 is committed; `ROLLBACK AND CHAIN` rolls back what came before and opens a new transaction, whose commit the library reports as `committed`. Steer transactions with `beginTransaction()`, `commit()`, `rollback()` and `transaction()` only:

  ```php
  $db->transaction(function ($db) {
      $db->insert('t', ['id' => 1]);
      $db->execute('START TRANSACTION'); // commits row 1, opens a transaction the library does not know
      $db->insert('t', ['id' => 2]);
      throw new RuntimeException();      // transaction.end: 'rolled_back' - row 1 is in the database
  });
  ```
- **The callback ends the transaction itself** (a `commit()` or `rollback()` of its own, or of a hook) - `transaction()` ends only the transaction it began. Whatever is open afterwards - a second transaction of the callback, one a hook began in response - is neither committed nor rolled back for it and is left to whoever began it. A callback that returns gets a `CommitFailedException` with `lost`, and no `COMMIT` is sent; one that throws gets its exception back. `transaction()` is for one transaction: run several with one call each, or with `beginTransaction()` and `commit()` yourself.
- **The callback calls `transaction()` or `beginTransaction()` again** - there are no nested transactions (no savepoints): the inner call throws a `TransactionException` `Failed to begin transaction` ("There is already an active transaction") before its callback runs. Left to escape, it rolls the outer transaction back like any other exception of the callback. A function that must work inside and outside a transaction asks `currentTransaction()` (or `inTransaction()`) first.
- **A `transaction.commit` hook fails, or a `transaction.end` hook fails after the commit** (throws, or a commit hook leaves the connection in a state that cannot be verified or cleaned up) - the data **is committed**, the committed transaction is not rolled back (only what a commit hook left open is, raw), `CommitHookException` is thrown (see [Hooks](#hooks)). On the rollback and `lost` paths an end hook's failure never replaces the exception that ended the transaction.

Every `TransactionException` that `commit()` itself throws is a `CommitFailedException`. Only `rolled_back` means that nothing of the transaction whose commit failed is committed; treat every other value as unclear.

```php
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;

try {
    $db->transaction(fn ($db) => $db->update('accounts', ['password_hash' => $hash], ['id' => $id]));
} catch (CommitFailedException $e) {
    if ($e->outcome === DatabaseInterface::TRANSACTION_ROLLED_BACK) {
        // nothing of that transaction was committed: safe to report "not saved" or to run it again
    } else {
        // 'lost': the change may be in the database - act as if it were
    }
}
```

The outcome can be read, not assigned (`$e->outcome = ...` is an `Error`): the driver tells it once, and what was told stays told. It is set in two places and nowhere else. `transaction()` and `updateMultiple()` set it for the commit they run themselves, and never leave it `null` (`lost` also when nothing was left to end because the callback had committed or rolled back itself). And `commit()` sets `lost` when PDO reported no transaction any more after the failed commit: nothing could end it then, so the failed commit itself tells `transaction.end` (also for a transaction begun on raw PDO). Every other `commit()` you call yourself - directly, inside a `transaction()` callback, inside a hook - keeps `null`: the transaction is yours to end, and neither your `rollback()` nor a `transaction()` that rolls back because the exception left its callback writes into it. The same goes for an exception thrown again later or handed on from a second connection. With `PDO::ERRMODE_WARNING` and an error handler that throws, a failing `COMMIT` arrives as the handler's exception, not as `CommitFailedException`.

With a manual `commit()`, a failing commit hook likewise throws `CommitHookException` after the commit: the committed transaction cannot be rolled back and must not be retried. `$e->connectionInTransaction` is a fail-closed snapshot taken after the commit hooks and before the end hooks: `true` means a transaction a commit hook left open could not be cleaned up, or the connection state could not be read, so check `inTransaction()` again and roll back, or discard the connection; what an end hook leaves open is not checked.

The driver sets `completion_type` to `NO_CHAIN` when it connects (see [Connection Options](#connection-options)). A session switched to `CHAIN` afterwards (`SET SESSION`) is not supported: every `COMMIT` and `ROLLBACK` opens the next transaction, which nobody would commit. The library reports it instead of continuing silently: after a commit as `CommitHookException` (first failure `Connection is in a new transaction`, commit hooks skipped, `connectionInTransaction` true), after a rollback as `TransactionException` once the rollback and end hooks ran (through the `error` hook instead where another exception reaches you: the callback's on the automatic rollback in `transaction()`, or a rollback hook's; after a `ROLLBACK` that confirmed nothing only the end hooks run, with `lost`). The hooks of such a commit or rollback already run inside the chained transaction. When the connection state cannot be read right after the `COMMIT` or `ROLLBACK` (only a `pdoClass` of yours throws from `inTransaction()`), the same is reported with `Connection state unknown`: a chained transaction may be open.

A `COMMIT` that fails is the one case where chaining would make the outcome a lie: the `COMMIT` may have taken effect - its answer lost on the way, a `pdoClass` that throws after `parent::commit()`, an error handler that throws - and the transaction PDO reports is then the next one, not the one whose `COMMIT` failed; a `ROLLBACK` would roll back only that empty one. So after a failed `COMMIT` - a `CommitFailedException`, or what a `pdoClass` or an error handler threw besides a `PDOException`, which passes unchanged - the driver asks the server for `@@completion_type` (on raw PDO, no hook sees it), unless PDO says that no transaction is left - then, or when the question makes PDO learn it, the end is `lost` at once. The answer counts only when no statement went through the library since the `COMMIT` was sent: an error handler may have switched `completion_type` in between. Unless the answer is `NO_CHAIN` - also when no answer comes -, whatever ends that transaction as rolled back confirms nothing: the `ROLLBACK` is sent to clean up, no `transaction.rollback` hook runs, and `transaction.end` says `lost` (and so does `$outcome` of the `CommitFailedException` that `transaction()` or `updateMultiple()` throw; the one your own `commit()` threw keeps `null`). That holds for `transaction()`, `updateMultiple()`, your own `rollback()` after a failed `commit()`, and for the raw cleanups after a `transaction.begin` or `transaction.commit` hook whose own `commit()` failed. The end's `error` is the failed commit - where another exception reaches you (`transaction()`, a throwing `transaction.begin` hook), that one. Under `RELEASE` the server closes the connection after a `COMMIT` that went through: where PDO learned that, the commit tells `lost` at once; where the answer was lost on the way, the `ROLLBACK` fails as on every lost connection. A transaction begun on raw PDO, ended there and begun there again is not told apart from the one whose `COMMIT` failed: its `rollback()` is `lost` as well.

The question also changes one case on `NO_CHAIN`: a `COMMIT` the server answers with an error **and** ends - a deadlock at commit, a Galera certification failure - used to end as `rolled_back`, because PDO still reported the transaction and a `ROLLBACK` into nothing was taken for a confirmation. The question refreshes what PDO knows, PDO reports no transaction any more, and the end is `lost`, as after every failed commit that leaves no transaction behind. For a `commit()` you call yourself that end is told by `commit()` at once: a `rollback()` after it finds no transaction and throws, so check `inTransaction()` first, as the example above does. Otherwise `NO_CHAIN` changes nothing: the transaction PDO reports is the one whose `COMMIT` failed, and its rollback is `rolled_back`. One limit: when a `transaction.commit` hook's own transaction ends as `lost` while it may still be open (its cleanup `ROLLBACK` chained again), the `rollback()` that ends it later runs the `transaction.rollback` hooks, as after every such `lost` (see [Hooks](#hooks)).

## Schema

`$db->schema()` reads what the current database holds from `information_schema` - for a check that a deployment has the tables, columns and indexes it expects. Read only: no DDL.

```php
$schema = $db->schema();
$schema->tables();                 // ['sessions', 'users'] - base and system-versioned tables
$schema->hasTable('users');        // true
$schema->columns('users');         // [['name' => 'id', 'type' => 'bigint(20)', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment'], ...]
$schema->indexes('users');         // [['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true, 'primary' => true], ...]
$schema->constraints('users');     // [['name' => 'PRIMARY', 'type' => 'PRIMARY KEY'], ['name' => 'email', 'type' => 'UNIQUE'], ...]
```

A table is a base table or a system-versioned one - not a view, a sequence or a temporary table (a temporary table that shadows a base table in the session leaves the base table described). Names come in the server's order (`utf8mb3_general_ci`: without case or accents, `_` after the letters), names equal there by their bytes. Index and constraint names are never compared there - `e` and `é` are two indexes, a UNIQUE named `PRÍMARY` is no primary key. Columns come in their order; `type` and `default` are as MariaDB writes them: the default as SQL - `NULL`, a quoted string (`'it''s'`), a number, an expression (`current_timestamp()`) - and `null` when the column has none. A `JSON` column is `longtext` with a `CHECK` constraint of its name. Indexes and constraints list the primary key first, then by name (constraints of the same name by type); an index shows its columns, not a prefix length (`KEY (s(10))` is on `s`), a descending part or its type. `columns()`, `indexes()` and `constraints()` throw a `QueryException` for a table the current database does not have (a view or a sequence included).

`information_schema` shows what the user has privileges on - the same on 10.11, 11.4 and 12.3 (measured). `SELECT`, `INSERT`, `UPDATE` or `REFERENCES` on the whole database shows everything. With less: a table without any privilege is no table here; `columns()` shows the columns with `SELECT`, `INSERT`, `UPDATE` or `REFERENCES` (on the database, the table or the column) and throws when that leaves none; an index, and a key in `constraints()`, shows with any privilege on the table, else only when every one of its columns has one; a `CHECK` constraint shows only with a privilege on the database. Constraints are read from `KEY_COLUMN_USAGE` and `CHECK_CONSTRAINTS`: `TABLE_CONSTRAINTS` shows nothing to a user with `SELECT` alone.

No snapshot: DDL on another connection at that moment can show the old state, the new one, or a mix - the table found, its rows from after the change (after a `DROP` none: `columns()` throws, `indexes()` and `constraints()` return `[]`).

## Named Locks

A named lock (`GET_LOCK()`) is a lock on a name, not on rows: "at most one of these at a time", across requests and processes.

```php
if ($db->namedLock('login-code:' . $userId)) {        // false: another connection holds it
    try {
        // ...
    } finally {
        $db->releaseNamedLock('login-code:' . $userId);
    }
}
$db->namedLock('report', 5);                            // wait up to 5 seconds
```

Around a manual transaction, end the transaction before the lock is released - with the pattern under [Transactions](#transactions) (`currentTransaction() !== null || inTransaction()`): a transaction the server ended behind the library's back is still held by the library, which then refuses every statement, the release included, until `rollback()` tells its end.

It belongs to the connection, not to a transaction: `COMMIT` and `ROLLBACK` do not release it; `releaseNamedLock()` or the end of the connection do - `reconnect()` refuses while the driver holds one (see "Reconnecting"), and a persistent connection (`PDO::ATTR_PERSISTENT`) does not end with the request: a lock a request dies holding stays held until that pooled connection ends, and the next request on that connection holds it (`isNamedLockHeld()` true, `namedLock()` throws the reentry exception) until it releases it. Two connections that each wait for the other's lock end in a deadlock: MariaDB fails one `GET_LOCK()` with error 1213 and keeps its transaction (measured), but 1213 is also the code of a deadlock that ended the transaction - the library cannot tell them apart and refuses further statements until `rollback()`, fail-closed. Take named locks outside transactions, or always in the same order. After a deadlock or a 1020 the library sends nothing but `rollback()` - `releaseNamedLock()` included: roll back first, then release. A name with a NUL byte throws (MariaDB cuts the name there, and different names would be one lock). The name is prefixed with the configured database and `:` on the server (`app_db:login-code:7`), because the server keeps one namespace for all its databases - a shared server included (a configured database whose name holds a `:` gets no prefix, and the named-lock methods throw a `QueryException`: the lock `c` of `a:b` and the lock `b:c` of `a` would be one name; the connection itself works); it is compared as written (case, accents and spaces count) and may have 192 bytes with the prefix (error 1059 beyond). MariaDB lets a connection take a lock it already holds and counts the holds, so that one release would leave it held: `namedLock()` throws a `NamedLockReentryException` (a `QueryException`, with the name in `$lockName`) for a lock this connection holds instead, so that an application can tell "held here already" from a failure of the server by its class (`isNamedLockHeld()` asks the server). `releaseNamedLock()` returns false when this connection did not hold the lock. A `NULL` from `GET_LOCK()` (an error such as a killed thread) throws; a `reconnect()` from a `query` listener of one of these statements is refused (see "Reconnecting"); a negative timeout and an empty name throw before anything is sent.

`heldNamedLocks()` lists the names the driver holds as far as it knows, as passed, in the order taken - not asked on the server: a lock taken in raw SQL is not in it until `namedLock()` is answered that this connection holds it, one the server ended (the connection died, the session was killed) still is. It follows the answers, recorded where the statement ran - before its `query` listeners, so that what they take or release counts after it: a name counts when the server answers that this connection holds the lock (taken, or held already), and no longer when it answers that another connection holds it or when `releaseNamedLock()` ran; an error (`NULL`) and a statement that did not run (it failed, a `query.before` listener threw) change nothing. An answer that cannot be read after the statement ran counts the name - the lock may have been taken - and the method throws a `QueryException` (an exception other than a `PDOException` that an error handler throws, not about a failure PDO recorded, passes unchanged; the name counts all the same). `namedLockHolder($name)` asks the server which connection holds a lock: its connection id (`CONNECTION_ID()`, the `Id` of `SHOW PROCESSLIST`), this one's included, or `null`. The form of the name on the server, `<configured database>:<name>`, is part of the contract: another program may ask `IS_USED_LOCK('app_db:login-code:7')` itself.

The methods are on `DatabaseInterface`. A driver of its own that extends `AbstractDriver` names its prefix by overriding `namedLockPrefix()`; it names none by default, and the named-lock methods then throw. The named-lock statements go through `AbstractDriver::query()` itself, so that their answers are recorded where they ran: a driver's own `query()` does not see them, the hooks do. Only the method's own statement is recorded: the same statement sent from inside that call (an error handler, a driver's `bindAndExecute()`) is not - a lock it takes counts as one taken in raw SQL.

## Hooks

Register callbacks for query logging, debugging, or monitoring:

```php
// Before every statement: a listener that throws stops it - nothing is sent (for tests)
$db->on('query.before', function (array $data) {
    if (str_starts_with($data['sql'], 'INSERT INTO `login_attempts`')) {
        throw new RuntimeException('simulated failure before the insert');
    }
});

// Log all queries
$db->on('query', function (array $data) {
    echo "SQL: {$data['sql']}\n";
    echo "Params: " . count($data['params']) . "\n"; // the values themselves may be secrets, see below
    echo "Duration: {$data['duration']}s\n";
    echo "Rows: {$data['rows']}\n";
});

// Log errors: what the database said is in sqlState and driverCode, as on the exception
$db->on('error', function (array $data) {
    // not $data['error']: the database's message may quote a value (see "Parameters are secrets" below)
    error_log("Query failed | SQLSTATE: {$data['sqlState']} | driver code: {$data['driverCode']} | SQL: {$data['sql']}");
});

// Transaction hooks: every event names its transaction (see below)
$db->on('transaction.begin', fn(array $data) => print "Transaction {$data['transaction']} started\n");
$db->on('transaction.commit', fn(array $data) => print "Transaction {$data['transaction']} committed\n");
$db->on('transaction.rollback', fn(array $data) => print "Transaction {$data['transaction']} rolled back\n");

// One listener for every outcome: 'committed', 'rolled_back' or 'lost'
$db->on('transaction.end', function (array $data) use ($pending) {
    $data['outcome'] === DatabaseInterface::TRANSACTION_COMMITTED ? $pending->flush() : $pending->discard();
});

// A listener for a while only: off() takes it away again
$log = static fn (array $data) => $recorder->add($data['sql']);
$db->on('query', $log);
handleRequest($db);
$db->off('query', $log);
```

`query.before` fires at the start of every `query()` - before the library's own checks, so also for a statement the library then refuses (a parameter it cannot bind fires `error` after it; after a deadlock the statement throws) -, with `sql` and `params` (the parameters as values: references among them are told as what they hold). Changing them changes nothing of the statement; the `query.before` listeners after it see the change, as with `query`. A listener that throws stops the statement: nothing is sent, neither `query` nor `error` fires, and its exception reaches the caller unchanged - a `PDOException` as `QueryException` `Query hook failed`, as from a `query` listener, without codes. What a listener does counts for the statement: after its `commit()` or `rollback()` the statement runs outside the transaction, after its `reconnect()` on the new connection - as the same call in the callback would (and `transaction()` tells such an end as for the callback). A listener that runs a statement of its own fires `query.before` again: guard against the recursion. One that never stops - a statement for every statement, in `query.before`, `query` or `error` - is stopped after 32 levels of these listeners inside each other with a `LogicException` (before, PHP ran out of memory); the `transaction.*` listeners do not count.

`off()` removes every registration of the callback, told apart by identity (`===`: the same closure object, the same `[object, 'method']` pair). A callback that is not registered for the event throws, like an unknown event does for `on()` - a typo or a second `off()` is loud. A listener removed while its event is being told still runs that once; the change counts from the next one.

| Event | Payload |
|---|---|
| `transaction.begin` | `['transaction' => int, 'depth' => int]` |
| `transaction.commit`, `transaction.rollback` | `['transaction' => ?int, 'depth' => ?int]` |
| `transaction.end` | `['outcome' => string, 'error' => ?Throwable, 'transaction' => ?int, 'depth' => ?int]` |

`transaction` is the number of the transaction: the transactions begun through the driver are counted, from 1, for as long as the driver lives (`reconnect()` does not start over), so that a listener can match an end to its begin instead of relying on their order. `currentTransaction()` returns the number of the open transaction begun through the driver, `null` when none is open (also after its end was told as `lost`). `depth` counts the transactions whose `transaction.begin` was told and whose `transaction.end` was not yet, this one included: 1 for a transaction begun while no other one owed its end, 2 for one a `transaction.commit` listener runs (the committed one's end is told after the commit listeners), 3 for one a second commit listener runs while the transaction the first one left open still waits for its end. A transaction begun on raw PDO (`getPdo()->beginTransaction()`) was not told begun: its commit, rollback and end carry `null` for both. Every `transaction.begin` is followed by exactly one `transaction.end` with the same number - also when a begin listener threw, when a commit listener began a transaction and ended it on raw PDO (`lost`), and when the connection is discarded (`reconnect()`, `lost`). The numbers are the library's: a `transaction.begin` or `transaction.rollback` listener that takes the payload by reference and changes them changes what the listeners after it are told - these two, like `query` and `error`, hand one payload from listener to listener -, not what the library tells later: the end carries the number the begin was given. `transaction.commit` and `transaction.end` listeners get an array of their own each and cannot take it by reference (PHP throws an `Error`, which is that listener's failure).

`transaction.end` fires exactly once for every transaction the library ends, after the `transaction.commit` or `transaction.rollback` listeners, with `['outcome' => ..., 'error' => ?Throwable, 'transaction' => ?int, 'depth' => ?int]`. After a throwing `transaction.begin` listener no rollback listener runs: the transaction is rolled back on raw PDO, and the end is `rolled_back` (or `lost` when that rollback did not go through), with the exception the caller gets as `error`. `rolled_back` carries the exception that ended the transaction (null after a manual `rollback()`). `lost` is the case the rollback listeners never see: the library could not confirm a rollback, because the connection was lost, the raw cleanup of a commit hook's transaction did not end it (`completion_type=CHAIN`), PDO no longer reported the transaction, the connection state could not be read, the server said before the rollback that the transaction is gone after a statement had failed (or could not be asked: the `ROLLBACK` is then still sent, but confirms nothing), or the commit failed and so did the rollback after it (then the data may be committed, fail-closed, and `error` is the commit's exception: a callback that committed itself with a raw `COMMIT` or a DDL statement lands here, and then the data is committed), or the commit failed on a session that may chain transactions and the rollback after it confirms nothing (the same; `error` is the exception that reaches you where another one does). The connection may be gone, so listeners must not expect queries to work. If the transaction may in fact still be open, end it with `rollback()` or discard the connection (`reconnect()`): that `rollback()` runs the rollback listeners but tells no second end. Ending it on raw PDO instead leaves that mark in place: a transaction then begun on raw PDO and ended through the library tells no end (`beginTransaction()` clears the mark). All end listeners run; their failures land in `CommitHookException::$failures` after a commit (behind the commit listeners' failures: first the ends of transactions commit listeners left open, then the committed transaction's end), as `TransactionException` after a manual `rollback()` (unless a rollback listener threw: that exception wins), and only in the `error` hook (with `hook`, `outcome` and `exception` keys; a throwing `error` listener is ignored there) after the automatic rollback in `transaction()`/`updateMultiple()` and on a `lost` reported there, so the exception that ended the transaction reaches you unchanged. A failed manual `commit()` or `rollback()` fires nothing: that transaction is still yours to end (one exception: a failed or refused commit of a transaction PDO no longer reports tells `lost` at once, because nothing could end it afterwards). After the commit `transaction()`/`updateMultiple()` ran themselves has failed, `error` is the `CommitFailedException` the caller gets, and its `$outcome` is the outcome the listener is told. A `commit()` the callback called itself and let escape is the `error` as well, with `$outcome` null (or `lost`, if that commit had told the end itself). A transaction a commit listener starts through the library and leaves open is rolled back without rollback hooks but with its own `transaction.end` (after all commit listeners, before the outer end); one a rollback or end listener leaves open is not checked. A commit the library refuses (see [Transactions](#transactions): a swallowed deadlock or 1020) ends as `rolled_back`, or as `lost` when nothing was left to roll back (with autocommit, the default: after statements on raw PDO following a deadlock, or after a lock wait timeout that ended the transaction), never as `committed`. An `error` hook that writes to the database after a failed statement needs its own connection: after a deadlock or a 1020 the library sends nothing on this one.

The `error` payload carries `sql` and `params` as passed, `error` (the message), `code` (the code of the reported exception: the SQLSTATE string of a failed statement, the driver's number in a non-exception error mode, `0` for an exception of the library, a foreign exception's own code), and `sqlState` and `driverCode` - what the database said, read by the rule of `$sqlState` and `$driverCode` (see [Exceptions](#exceptions)): for a failed statement the same values as on the exception the caller gets; `null` where no database failure stands behind the reported error (a parameter the library refused to bind, a chained transaction, a listener's exception that carries no codes). Where the library hands an exception to the hook instead of, or besides, throwing it - a failing `transaction.end` listener (after any rollback or `lost`), a chained transaction -, the codes are that exception's, and `outcome` and `exception` are added; `hook` (`'transaction.end'`) only for a listener's failure.

**Parameters are secrets.** The values a statement binds - password hashes, tokens, personal data - reach every channel that tells about the statement:

- the `query.before`, `query` and `error` payloads: `params` exactly as passed, and in `error` the database's own message, which quotes values (`Duplicate entry 'ann@example.com' for key 'uq_email'`);
- `QueryException::getDebugMessage()`: the SQL and the parameters;
- `getPrevious()`: the `PDOException` behind it, with the database's message - a duplicate key carries the duplicate value. Tell duplicates apart by `UniqueViolationException::$constraint`, never by logging the previous exception;
- `(string) $e`: it includes the messages of the previous exceptions;
- the arguments in the trace (`getTrace()`, `getTraceAsString()`) while `zend.exception_ignore_args` is off - the parameters of `query()` and of the CRUD methods are among them, and so are statements: `serialize($e)` then fails ("Serialization of 'PDOStatement' is not allowed"), `var_export($e)` prints the values. With `zend.exception_ignore_args = On` (the production setting) traces carry no arguments and the exception serializes;
- a failed connection: the `PDOException` behind the `ConnectionException` holds the DSN and the username in its trace (PDO keeps the password out);
- `NamedLocksHeldException::$lockNames`: the names of the named locks the driver holds, which may identify a person (`login:<user id>`).

Never write any of them to a log or an error page unredacted: log the SQL, `sqlState` and `driverCode` - `getMessage()` names neither the SQL nor a value -, and of the parameters at most their number or a whitelisted subset. The `error` hook also fires (with `code` 0) for a parameter that must not be bound.

For `query.before`, `query`, `error` and `transaction.begin`, a throwing hook stops the remaining hooks of its event and its exception reaches the caller (a `PDOException` from a `query` hook arrives as `QueryException` with the message `Query hook failed`: the statement did run and no `error` hook fires); after a throwing `transaction.begin` hook a rollback of the transaction it was told about is attempted first (best effort, directly and without `transaction.rollback` hooks, then its `transaction.end`; if that rollback fails, the transaction may still be open and its end is `lost`) - unless the hook ended it itself; a transaction it began afterwards is left open, with its end owed. A throwing `transaction.rollback` hook does the same on a manual `rollback()`, but is ignored during the automatic rollback in `transaction()` and `updateMultiple()` (the original exception is re-thrown). A throwing `error` hook therefore replaces the `QueryException` of the failed statement. `insert()` reads the new id before the `query` hooks run, so a hook may itself insert on the same connection. `on()` throws a `DatabaseException` for an event name it does not know - a listener for a misspelled name would never run; a custom driver that triggers events of its own names them by overriding `knownEvents()`. `transaction.commit` hooks run after the commit and cannot undo it, so they work differently:

- **All of them run**, even if one throws (only exception: see the last point). Keep them independent: steps that depend on each other belong in one hook.
- **Failures are reported together** as `CommitHookException`: `getPrevious()` is the first failure, `$e->failures` lists all of them in hook order.
- **A transaction a hook leaves open** is rolled back before the next hook runs - directly, without `transaction.rollback` hooks - and reported as a `LogicException`. If that rollback fails or does not end the transaction (`completion_type=CHAIN` opens the next one), or the connection state cannot be read, the remaining hooks are skipped, listed as failures, and `$e->connectionInTransaction` is `true`: the connection is, or may still be, in a transaction (an unreadable state counts as `true`, fail-closed). Do not continue on that connection as if it were in autocommit: check `$db->inTransaction()` and roll back, or discard the connection.
- **A hook that commits a transaction itself** (a `transaction()` inside a commit hook) runs every commit hook again for that inner commit, itself included: guard against the recursion.

## Exceptions

All exceptions extend `DatabaseException`, which extends PHP's base `Exception`:

```php
use Sodaho\PdoWrapper\Exception\DatabaseException;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Exception\UniqueViolationException;

// Catch all pdo-wrapper exceptions
try {
    $db->query('...');
} catch (DatabaseException $e) {
    // Catches ConnectionException, QueryException, TransactionException, CommitHookException
}

try {
    $db = Database::mariadb([...]);
} catch (ConnectionException $e) {
    // Connection failed
    echo $e->getMessage();        // User-friendly message
    echo $e->getDebugMessage();   // Detailed debug info
    $e->refusal;                  // why the library refused a connection it could open, or null:
                                  // ConnectionRefusal::NotMariaDb, MariaDbTooOld, NotMysqlnd, NullMode
}

try {
    $db->query('INVALID SQL');
} catch (QueryException $e) {
    // Query failed
    $e->sqlState;     // '42000' - the SQLSTATE the database sent, a string; null when no database failure is behind it
    $e->driverCode;   // 1064   - the driver's own number; null when there is none
    $e->getCode();    // always 0
}

try {
    $db->insert('users', ['email' => $email]);
} catch (UniqueViolationException $e) {
    // The row exists already: a duplicate of a unique key or of the primary key.
    // A QueryException like any other, so an existing catch (QueryException) still sees it.
    $key = $e->constraint; // 'email', 'PRIMARY'
}

try {
    $db->transaction(fn($db) => $db->insert('users', ['name' => 'John']));
} catch (CommitHookException $e) {
    // Committed, but a transaction.commit or transaction.end hook failed, the connection state
    // after the commit could not be verified or cleaned up, or the session chained a new
    // transaction to the COMMIT: do not retry
    $firstFailure = $e->getPrevious(); // all of them: $e->failures
    if ($e->connectionInTransaction) {
        // a transaction a commit hook left open (or a chained one) may be open: check inTransaction(), roll back or discard the connection
    }
} catch (CommitFailedException $e) {
    // The commit failed or was refused; $e->outcome is 'rolled_back' (nothing is committed) or 'lost' (unclear)
} catch (TransactionException $e) {
    // The transaction could not be started
}
// An exception thrown by the callback itself is re-thrown unchanged after the rollback.
```

A `CommitHookException` the library throws means the data is committed; a hook failed, the connection state could not be verified or cleaned up after a hook, or the connection was in a new transaction right after the commit (`completion_type=CHAIN`: no commit hook ran). One that a PDO class of the caller's throws from its own `commit()` is not the library's word: `transaction()` rolls back and passes it on (see [The PDO Class](#the-pdo-class)). It extends `DatabaseException`, not `TransactionException`: a broad `catch (DatabaseException)` also sees committed data, so catch `CommitHookException` first where that matters.

Every exception of the library carries what the database said in `$sqlState` and `$driverCode`, taken from PDO's `errorInfo`: the SQLSTATE as the five characters the database sent (`'42S02'`, `'23000'`, `'40001'` - compare as a string) and MariaDB's error number (`1062`, `1146`, `1213`). Both are `null` when no database failure stands behind the exception (a refused argument), an exception that wraps another one of the library hands its codes on, and a failure PDO reports by returning `false` carries them like a thrown one. An exception the library puts around what a hook threw after the operation went through - `CommitHookException`, `Query hook failed`, a `TransactionException` for a failing `transaction.rollback` or `transaction.end` hook - carries none: the statement ran, the transaction was committed or rolled back, and a retry on `sqlState === '40001'` must not run it again. The hook's codes are in `getPrevious()`. A failing `transaction.begin` hook makes the begin fail, so its codes are handed on - except where the transaction it was told about ended behind the library's back or its state could not be found out (`lost`): nothing is certain there, and there are no codes; and a hook's own exception that passes unchanged (a `QueryException` of a statement it ran) keeps what it carries - catch in the hook what must not look like the caller's failure. `getCode()` is always `0`: PHP's exception code is an integer and cannot hold an SQLSTATE.

`UniqueViolationException` tells "this row exists already" from every other failed statement without looking at error codes; it is recognized by MariaDB's code for it (1062), on inserts and updates alike. `$constraint` is the name of the key MariaDB reports (`email`, `PRIMARY` for the primary key; a name with a dot is kept whole). It is read from the server's English error message: a server set to another message language yields `null`, so compare it as a hint, and branch on the class.

**Refusals with a class of their own** - each a subclass of what the method throws anyway, so an existing `catch` keeps seeing it; `getMessage()` is static, and `$sqlState`/`$driverCode` are `null` (nothing was sent):

- `ImplicitCommitException` (a `QueryException`): a statement that would commit the open transaction implicitly, inside a transaction the library began (see [Transactions](#transactions)); `$statement` names its leading keywords (`CREATE`, `LOCK`).
- `NamedLockReentryException` (a `QueryException`): `namedLock()` for a lock this connection holds already (see [Named Locks](#named-locks)).
- `NamedLocksHeldException` (a `ConnectionException`): `reconnect()` while the driver holds named locks (see [Reconnecting](#reconnecting)).

**Upgrading from 1.0:** a failing `transaction.commit` hook used to surface as its own exception (a `PDOException` from a hook even as `TransactionException`); it now arrives as `CommitHookException` with the hook's exception as `getPrevious()`.

## Database-Qualified Tables

```php
$db->insert('mydb.users', ['name' => 'John']);
$db->table('mydb.users')->where('id', 1)->first();
```

## MariaDB Specifics

What the library renders, and what MariaDB does with it, where that is worth knowing:

| | |
|---|---|
| Identifier quoting | backticks |
| `update()` / `delete()` with `limit()` | `ORDER BY ... LIMIT n`; `limit()` needs an `orderBy()` |
| `lockForUpdate()` / `sharedLock()` | `FOR UPDATE` / `LOCK IN SHARE MODE` |
| `insert()` returns | the `AUTO_INCREMENT` id; 0 for a table without one; throws for an id above `PHP_INT_MAX` (`BIGINT UNSIGNED`) and for a negative id in an `AUTO_INCREMENT` column |
| `insertIgnore()` | `ON DUPLICATE KEY UPDATE col = col`: the existing row is locked until the transaction ends and its update triggers run; throws with `ATTR_FOUND_ROWS` and on a persistent connection |
| `sum()` / `avg()` return | a numeric string for integer and `DECIMAL` columns (`'75'`, `'1.5000'`), a float for `FLOAT`/`DOUBLE` (see "What Comes Back") |
| Rows an `update()` returns | rows actually changed (0 when the values were already there); matched rows with `ATTR_FOUND_ROWS` - and possibly on a persistent connection an earlier request opened with it |
| Several assignments in one `update()` | evaluated left to right: a later one sees what an earlier one set |
| `insertWhen()` | `... FROM DUAL WHERE` |
| `upsert()` | `ON DUPLICATE KEY UPDATE` (any unique key is the duplicate); counts 1 / 2 / 0; throws with `ATTR_FOUND_ROWS` and on a persistent connection |
| `upsertReturning()` | the same with `RETURNING`: the row after the statement, in every case; works with `ATTR_FOUND_ROWS` |
| `Database::json()` | `JSON_UNQUOTE(JSON_EXTRACT(col, 'path'))`, the path written in; JSON `null` as `'null'` |
| `namedLock()` | `GET_LOCK()`, the name prefixed with the database; held by the connection |
| `IS` / `IS NOT` with a value | `<=>` / `NOT (... <=> ...)` |
| `LIKE` and upper/lower case | case- and accent-insensitive with the default collations (`a%` matches `Anna` and `Ärger`); binary on a `Database::json()` value |
| `orderBy()` and NULL | NULL first ascending, last descending |
| A failed statement inside a transaction | only the statement is undone, except where the server ends the transaction: after a deadlock (1213) or a changed row under snapshot isolation (1020) the library accepts nothing but `rollback()`; after a lock wait timeout under `innodb_rollback_on_timeout` the library asks right away, sends nothing more and `commit()` refuses, the end is `lost` - with autocommit switched off as well (in a transaction begun on raw PDO: not once a later statement has opened the next transaction) |
| DDL inside a transaction | DDL and the other statements with an implicit commit (`CREATE TABLE`, `ALTER TABLE`, `LOCK TABLES`, `GRANT`; not `CREATE TEMPORARY TABLE`) commit it, also when the statement itself fails: inside a transaction the library began they are refused before they are sent (`ImplicitCommitException`), the transaction stays open. On raw PDO the library does not see them: after one there nothing more is sent through the library (it would run in autocommit), `transaction()` reports `lost`, and so does a `rollback()` (nothing is sent when PDO reports no transaction) |
| `now()` / `utcNow()` | `NOW()` / `UTC_TIMESTAMP()` |

## Security

This library protects against SQL injection through:

- **Prepared statements** for all values (WHERE, INSERT, UPDATE) - the one exception is a `Database::raw()` expression given as a value, which is inlined by design
- **Identifier quoting** for all column and table names
- **Operator whitelist** validation (only `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IS`, `IS NOT`)
- **Connection values that cannot redirect the connection**: `host`, `database` and `charset` are rejected with a `ConnectionException` before connecting if they contain a `;` (PDO's DSN separator) or NUL; `port` must be a whole number between 1 and 65535; the config arrays are marked `#[\SensitiveParameter]`, so PHP keeps the password out of stack traces
- **No multi-statements** by default (see [Connection Options](#connection-options))

What it cannot protect: the SQL you pass yourself (`query()`, `execute()`, `whereRaw()`, `insertWhen()` conditions, `Database::raw()`), the PDO options you override, and what you do with the parameters in hooks and exception messages (see [Hooks](#hooks): parameters are secrets).

### Raw Expressions

For aggregate functions or complex SQL expressions, use `Database::raw()`:

```php
use Sodaho\PdoWrapper\Database;

// Aggregates require Database::raw()
$db->table('users')
    ->select([Database::raw('COUNT(*) as total')])
    ->get();

// Regular column names are automatically quoted and safe
$db->table('users')
    ->select(['id', 'name', 'email'])  // Becomes: `id`, `name`, `email`
    ->get();
```

**Security Note:** Never pass user input as the SQL of `Database::raw()`. Raw expressions bypass all identifier quoting, and as values in `insert()`, `update()`, `where()` or `having()` they are inlined instead of bound. A value that comes from outside goes into the expression's bindings: `Database::raw('price * ?', [$factor])` (see [Timestamps and Raw Values](#timestamps-and-raw-values)).

### User Input in Column Names

Column names are safely quoted against SQL injection, but quoting only makes a name safe as SQL, not as a decision: whoever chooses the column decides what is read, filtered or sorted by (`select($_GET['fields'])` would hand out `password_hash`, `where($_GET['field'], ...)` would search it). The whitelist is the authorization boundary, and it gives meaningful error messages instead of database errors:

```php
// ✅ REQUIRED for names from request input - the whitelist decides what may be asked
$allowedColumns = ['id', 'name', 'email', 'created_at'];
$column = $_GET['column'];

if (!in_array($column, $allowedColumns, true)) {
    throw new InvalidArgumentException('Invalid column');
}

$db->table('users')->orderBy($column)->get();
```

This applies to `select()`, `where*()`, `orderBy()`, `groupBy()`, and `join()`, and to table names. The same goes for a sort direction from request input: `orderBy()` throws on anything but `ASC`/`DESC`, so map the input to one of the two first.

### LIKE Patterns with User Input

Use `Database::escapeLike()` to prevent LIKE wildcards (`%`, `_`) in user input from being interpreted as wildcards:

```php
use Sodaho\PdoWrapper\Database;

$search = Database::escapeLike($_GET['q']); // "100%" → "100\%"

$db->table('products')
    ->whereLike('name', '%' . $search . '%')
    ->get();
// Matches "Rabatt: 100%" but NOT "1000" or "10099"
```

The builder renders every `LIKE` and `NOT LIKE` (`whereLike()`, `whereNotLike()`, and `where()`, `having()` and `join()` with the operator) as `LIKE ? ESCAPE ?` and binds the backslash as the escape character. The escape character therefore does not depend on the engine or its SQL mode (`NO_BACKSLASH_ESCAPES` would otherwise turn the escaped `%` back into a wildcard), and `toSql()` returns one more parameter per `LIKE`. A `LIKE` you write yourself in `whereRaw()` or a raw query gets no escape clause: add `ESCAPE ?` and bind `'\\'` there.

## Limitations

This library is designed for simple, common use cases. The following features are **not supported**:

- **Dedicated OR methods** - All `where*()` calls are joined with AND. An OR group goes into `whereRaw()` (values bound) or into a raw query:
  ```php
  $db->table('users')->whereRaw('role = ? OR role = ?', ['admin', 'moderator'])->get();
  $db->query('SELECT * FROM users WHERE role = ? OR role = ?', ['admin', 'moderator']);
  ```

- **Nested WHERE groups** - Complex conditions like `(A AND B) OR (C AND D)` go into one `whereRaw()` condition or a raw query.

- **Subqueries** - Use raw queries for subqueries in SELECT, WHERE, or FROM clauses.

- **UNION** - Combine queries manually or use raw SQL.

- **OFFSET/JOIN/GROUP BY in update/delete** - `offset()`, `join()` (also `leftJoin()`/`rightJoin()`), `groupBy()`, `having()`, an `orderBy()` without `limit()` and a `limit()` without `orderBy()` are not supported with `update()` or `delete()`: they are not part of the generated statement, or would leave it to the server which rows are hit. The QueryBuilder throws an exception if you try, also for combinations that happen to be row-neutral (such as `groupBy()` on the primary key). `orderBy()->limit(n)` together is supported (see above). `select()`, `distinct()` and a row lock on an `update()`/`delete()` have no meaning there and are ignored (the statement takes its own row locks), so a builder locked for a `first()` can be reused for the update.

- **NULL in where()** - `where('column', null)` throws an exception because `column = NULL` is always false in SQL. Use `whereNull()` or `whereNotNull()`, or the null-safe `where('column', 'IS', $value)` for a value that may be null. The `$where` arrays of the CRUD methods take no `null` either.

- **Aliases** - `'column as alias'` and `'table as alias'` quote the alias like every other name. The result key is the alias as written, `orderBy()`, `groupBy()` and `'alias.column'` find it under that name, and a reserved word is a valid alias. The alias must be a plain word (letters of any script, digits, underscore) at the very end; anything else - a trailing newline included - leaves the whole entry one quoted name. A condition (`where()` and the other `where*()` methods) declares no alias: `'has as col'` is the name of one column there, as in `orderBy()`. An alias inside a `Database::raw()` entry is sent as written.

- **Builder clauses on `insert()`** - `table('t')->where(...)->insert($row)` inserts the row and ignores the clauses; use `insertWhen()` for a conditional insert (there, clauses throw).

These limitations keep the QueryBuilder simple and predictable. For complex queries, use the `query()` method with raw SQL - prepared statements still protect against SQL injection.

## Requirements

- PHP 8.5 or a later 8.x (`^8.5`)
- PDO and pdo_mysql, built on mysqlnd
- MariaDB 10.11 or later

## Testing

```bash
composer install
docker compose up -d                       # MariaDB 10.11 on 3306, 11.4 on 3307, 12.3 on 3308
./vendor/bin/phpunit                        # against 10.11
MARIADB_PORT=3307 ./vendor/bin/phpunit      # against 11.4
docker compose down
```

The suite has three parts:

- `tests/Unit` needs no database: the SQL the builder renders, the exceptions, the hooks.
- `tests/Contract` is what every driver of this library must do the same way. These tests name no database and write no DDL: they reach the database only through a **binding** (`tests/Support/Binding/DriverBinding.php`) - how to connect, the tables from a small set of column types, and the few facts that do differ between databases (error codes, the types of aggregates, the order of a SET list). The binding is chosen with `PDO_WRAPPER_TEST_DRIVER` (default `mariadb`).
- `tests/Driver/MariaDb` is what only MariaDB has: deadlocks and error 1020, implicit commits, the version and client checks, the pinned result types.

**The contract for a new driver:** a driver for another database comes with a binding of its own (registered in `ContractTestCase::binding()`), and it passes all of `tests/Contract` with it, unchanged - plus tests of its own for what only its database does. Only then is it part of the library. The contract tests what a driver does, not the SQL text: the SQL the builder renders is tested in `tests/Unit` and `tests/Driver/MariaDb`. What it does includes the PHP types of the values: the column types of the binding arrive as the types of [What Comes Back](#what-comes-back). It runs the shared base (`AbstractDriver`, `QueryBuilder`) through the driver, and that base renders MariaDB's SQL (backtick quoting, `FROM DUAL`, `ON DUPLICATE KEY UPDATE`): a driver for a database with another dialect first brings a dialect seam back into it.

## Acknowledgments

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).

## License

MIT
