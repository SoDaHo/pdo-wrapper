# PDO Wrapper

A lightweight PHP PDO wrapper with fluent Query Builder, supporting MySQL/MariaDB, PostgreSQL, and SQLite.

## Why This Library?

- **No dependencies** -- just PDO, which ships with PHP.
- **Readable codebase** -- the entire source fits in a handful of files. You can read and understand all of it in minutes.
- **Multi-database** -- MySQL, MariaDB, PostgreSQL, SQLite behind one API, with driver-specific details handled internally.
- **Safe defaults** -- prepared statements, identifier quoting, operator whitelist. Hard to accidentally write an injection vulnerability.
- **Intentionally limited** -- no dedicated OR methods, no subqueries, no UNION in the query builder. When you need complex SQL, you write SQL: a raw condition with bound values via `whereRaw()`, or the whole statement. The builder handles the straightforward queries.

## Installation

```bash
composer require sodaho/pdo-wrapper
```

## Quick Start

```php
use Sodaho\PdoWrapper\Database;

// Connect to SQLite
$db = Database::sqlite(':memory:');

// Connect to MySQL
$db = Database::mysql([
    'host' => 'localhost',
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
]);

// Connect to PostgreSQL
$db = Database::postgres([
    'host' => 'localhost',
    'database' => 'myapp',
    'username' => 'postgres',
    'password' => 'secret',
]);
```

## Connection Options

### MySQL

```php
$db = Database::mysql([
    'host' => 'localhost',      // required
    'database' => 'myapp',      // required
    'username' => 'root',       // required
    'password' => 'secret',     // optional
    'port' => 3306,             // optional, default: 3306
    'charset' => 'utf8mb4',     // optional, default: utf8mb4
    'options' => [],            // optional, PDO options
    'pdoClass' => PDO::class,   // optional, the class of the PDO object (see "The PDO Class")
]);
```

### PostgreSQL

```php
$db = Database::postgres([
    'host' => 'localhost',      // required
    'database' => 'myapp',      // required
    'username' => 'postgres',   // required
    'password' => 'secret',     // optional
    'port' => 5432,             // optional, default: 5432
    'options' => [],            // optional, PDO options
    'pdoClass' => PDO::class,   // optional, the class of the PDO object (see "The PDO Class")
]);
```

`options` replace the library's PDO defaults, the security-relevant ones included: exceptions as error mode, native prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`) and, on MySQL/MariaDB, multi-statements switched off. No statement of the library needs multi-statements; switched on, a string that reaches raw PDO (`getPdo()->exec()`) or an emulated prepare could carry a second statement. `Pdo\Mysql::ATTR_MULTI_STATEMENTS => true` brings them back, for example for a migration that sends a whole file in one call. Set the connection charset with the `charset` key, never with `SET NAMES` at runtime: PDO's own escaping (emulated prepares, `PDO::quote()`) only knows the charset of the DSN. `port` must be a whole number between 1 and 65535, also when `Database::fromEnv()` reads it from `DB_PORT` (an invalid value throws a `ConnectionException` instead of falling back to the default).

### SQLite

```php
// In-memory database
$db = Database::sqlite(':memory:');

// File-based database
$db = Database::sqlite('/path/to/database.db');

// With PDO options: wait up to 5 seconds for a lock another connection holds; open read-only
$db = Database::sqlite('/path/to/database.db', [PDO::ATTR_TIMEOUT => 5]);
$db = Database::sqlite('/path/to/database.db', [Pdo\Sqlite::ATTR_OPEN_FLAGS => Pdo\Sqlite::OPEN_READONLY]);
```

The options replace the library's PDO defaults like the `options` of the other drivers (see above); in `connect()` and `fromEnv()` they are the `options` key. A third argument names the class of the PDO object (`pdoClass` in `connect()` and `fromEnv()`, see "The PDO Class").

An empty path throws a `ConnectionException`: SQLite would open a private temporary database for it and delete it when the connection closes, so a missing setting would look like a working database that forgets everything.

SQLite identifiers are quoted with backticks. SQLite reads an unknown name in double quotes as a string literal (a typo in a column name then silently compares or sorts by a constant); a backtick-quoted name is always an identifier and fails with `no such column`, as it would on MySQL or PostgreSQL. Integers are bound as integers and booleans as `0`/`1` (PDO binds everything as text by default, and SQLite converts text to a number only through a column's affinity: `HAVING COUNT(*) > ?` with a text `1` is always false, and `false` would be stored as `''`).

**Upgrading to 1.2 with an existing SQLite database:** earlier versions bound every value as text. That matters wherever SQLite kept the text: a `false` is stored as `''` in every column type, and integers are TEXT in columns declared without a type or as `BLOB` (columns with numeric affinity converted them on write; `TEXT` columns keep text, which an integer parameter still matches through the column's affinity). An integer or boolean parameter no longer matches those rows in `=`, `IN`, `BETWEEN` or range comparisons, new numeric values sort before old text values, and `UNIQUE` tells the storage classes apart. Either keep passing strings for such columns (when writing and when reading), or convert the data once. Run each statement only on columns you know to hold booleans or integers, after checking for `UNIQUE` collisions; the integer statement converts only values whose integer round trip is lossless (so `'007'`, `'+5'`, `' 5'`, `'1.5'`, `''`, text and out-of-range numbers stay as they are), and `COLLATE BINARY` keeps a column collation such as `RTRIM` from matching trailing spaces:

```sql
-- a boolean column: former false
UPDATE t SET flag = 0 WHERE typeof(flag) = 'text' AND flag = '' COLLATE BINARY;
-- an integer column declared without a type or as BLOB: former integers
UPDATE t SET n = CAST(n AS INTEGER) WHERE typeof(n) = 'text' AND CAST(CAST(n AS INTEGER) AS TEXT) = n COLLATE BINARY;
```

### One Config for Every Environment

`Database::connect()` picks the driver from the config (`driver`) and delegates to `mysql()`, `postgres()` or `sqlite()` with the same keys - MariaDB in production, SQLite in tests, one call:

```php
$db = Database::connect(['driver' => 'mysql', 'host' => 'localhost', 'database' => 'myapp', 'username' => 'root', 'password' => 'secret']);
$db = Database::connect(['driver' => 'sqlite', 'path' => ':memory:']);
```

Accepted driver names: `mysql` (also `mariadb`), `pgsql` (also `postgres`, `postgresql`), `sqlite`. A missing or unknown driver throws a `ConnectionException`. For SQLite the file is `path`, else `database`; one of them is required (`:memory:` for an in-memory database), `options` are its PDO options and `pdoClass` the class of its PDO object.

`mysql()`, `postgres()`, `sqlite()` and `connect()` use what they are given and nothing else. They never read the environment: a required value that was not passed throws a `ConnectionException`, whatever `DB_HOST` says.

### Environment Variables

`Database::fromEnv()` is the one place in the library that reads the environment:

```php
$db = Database::fromEnv();                                  // driver and connection values from the environment
$db = Database::fromEnv(['password' => $secret]);           // the password from a secret store, the rest from the environment
$db = Database::fromEnv(['driver' => 'mysql', 'charset' => 'utf8mb4']);
```

| Variable | Used by | |
|---|---|---|
| `DB_DRIVER` | every driver | `mysql`, `pgsql` or `sqlite` (the names `connect()` accepts) |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` | MySQL/MariaDB, PostgreSQL | required |
| `DB_PASSWORD` | MySQL/MariaDB, PostgreSQL | optional |
| `DB_PORT` | MySQL/MariaDB, PostgreSQL | optional, the driver's default without it |
| `DB_SQLITE_PATH` | SQLite | required: the path of the file, or `:memory:` for an in-memory database |

The list is complete: no other variable is read, and none of these anywhere else.

**Priority:** `$overrides` > `$_ENV` > the process environment. A key that is passed counts instead of its variable, also with `null` or an empty value: `fromEnv(['password' => null])` connects without a password whatever `DB_PASSWORD` says. The keys are those of `connect()`; `charset`, `options` and `pdoClass` have no variable and can only be passed. `$_ENV` is checked first (thread-safe), then `getenv($name, true)`.

The second source is the process environment and nothing else: `getenv()` is asked with `local_only`. Without it PHP asks the web server module first, and that answers with what came with the request - under PHP-FPM the FastCGI parameters, every request header among them as `HTTP_*`. A `DB_*` value that is only a FastCGI parameter (`fastcgi_param DB_HOST ...;` in nginx) or an Apache `SetEnv` is therefore not found there. Set it where the process gets it - `env[DB_HOST] = ...` in the FPM pool, the service's or the container's environment - or load it into `$_ENV`. One thing the library cannot change: with `E` in `variables_order` (PHP's default without a `php.ini`; `php.ini-production` and `php.ini-development` leave it out) PHP-FPM fills `$_ENV` with the request's parameters as well, so a `fastcgi_param DB_HOST` still arrives through `$_ENV` there. A client cannot use that: what it sends arrives as `HTTP_*`, never as `DB_*`.

A variable that is set but empty counts as not set (`DB_HOST=` in a dotenv template): a required value is then reported as missing instead of connecting with an empty one. SQLite has no default path anywhere - `Database::sqlite($path)`, `new SqliteDriver($path)`, `connect()` and `fromEnv()` throw without one, and so does an empty `DB_SQLITE_PATH`: a missing setting must not end in an in-memory database that forgets everything. The SQLite file comes from `DB_SQLITE_PATH` or from a `path`/`database` that is passed, never from `DB_DATABASE`: that is the name of a server database. Use a library like [sodaho/env-loader](https://github.com/sodaho/env-loader) to load `.env` files.

### The PDO Class

The library creates the PDO object itself, with its defaults. `pdoClass` names the class it creates it as: `PDO` by default, otherwise any class that extends `PDO`. It is a key of `mysql()`, `postgres()`, `connect()` and of the overrides of `fromEnv()`, and the third argument of `sqlite()` and `new SqliteDriver()`; `getPdo()` returns the object.

```php
$db = Database::mysql($config + ['pdoClass' => Pdo\Mysql::class]);
$db = Database::sqlite(':memory:', [], Pdo\Sqlite::class);   // getPdo() has createFunction() and the like
```

The class is created with the arguments PDO's constructor takes (DSN, user name, password, options) and has to pass them on to it; a constructor of its own should mark the password `#[\SensitiveParameter]` as PDO's does, or it shows up in stack traces. A value that is not the name of a class that extends `PDO` and can be instantiated throws a `ConnectionException` before anything is connected; `getDebugMessage()` names the key, not the value. A class PDO refuses for the driver (`Pdo\Sqlite` for a MySQL connection) is a failed connection. The class is never read from the environment: a class name from there would be handed the credentials.

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

$db = Database::mysql($config + ['pdoClass' => SwitchablePdo::class]);
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
| any of these, and `rollBack()` of the class fails too (throws a `PDOException` or returns `false`) | the same exception; the `$outcome` of the library's `CommitFailedException` is `lost` | `lost`; no `transaction.rollback` listener runs |

No `transaction.commit` listener runs in any of them. In the last row no ROLLBACK was sent, so the transaction is still open on the server: end it with `getPdo()->rollBack()` - once the class lets it through - before the connection is used again. "Anything else" includes the exceptions of this library: a `CommitHookException` or a `CommitFailedException` that the class throws is passed on like any other object, the transaction is rolled back, and no `$outcome` is written into it - the library only speaks for the exceptions it built itself. A `commit()` called directly hands the failure on (`$outcome` is still `null`) and leaves the transaction to the caller, whose `rollback()` ends it. These are simulations - the class decides what PDO reports, not what the server did; what the databases really do with a COMMIT is described under [Transactions](#transactions).

### Reconnecting

A connection that cannot be cleaned up any more - a COMMIT that failed, a transaction a listener left open, a state that cannot be read, a session that chains transactions - can be discarded: `reconnect()` continues on a new connection, opened with the settings the driver was created with (the same DSN, credentials, `options` and `pdoClass`).

```php
use Sodaho\PdoWrapper\Driver\AbstractDriver;

if ($db instanceof AbstractDriver) {   // reconnect() is not part of DatabaseInterface before 3.0
    $db->reconnect();
}
```

- The new connection is opened first. When that fails, a `ConnectionException` reaches the caller and nothing has changed: the old connection is still in place.
- Otherwise a `ROLLBACK` is sent on the old connection when it reports a transaction (best effort: it frees the transaction's locks), and the driver continues on the new one. A transaction whose end was still owed ends as `lost`, with its number and a `TransactionException` ("Transaction discarded with its connection") as `error`; no `transaction.rollback` listener runs. `reconnect()` commits nothing of it - but what an implicit commit (a DDL statement on MySQL/MariaDB) committed before is committed, which is why the end is `lost`. `inTransaction()` is `false` afterwards (unless an end listener of that `lost` began a transaction, or an error handler inside a call into the old connection reconnected itself and began one on its new connection: those stay).
- What belonged to the old session is gone with it: **settings made with SQL** (`SET SESSION sql_mode = ...` sent through `execute()`), temporary tables, user variables, and for SQLite an in-memory database (`:memory:` is a new, empty database). So is what was done to the old PDO object after the driver created it: attributes set with `getPdo()->setAttribute()`, functions added with `Pdo\Sqlite::createFunction()`, a PDO object a subclass put in its place - the new one is created from the settings alone. Give session settings to the connection as options instead - `Pdo\Mysql::ATTR_INIT_COMMAND` runs on every connect, the first and every reconnect:

  ```php
  $db = Database::mysql($config + ['options' => [Pdo\Mysql::ATTR_INIT_COMMAND => "SET SESSION sql_mode = 'STRICT_TRANS_TABLES'"]]);
  ```
- `getPdo()` returns the new PDO object. The old connection closes when nothing holds it any more; the driver itself keeps nothing of it (but see the error handler below). Whoever still holds a part of it keeps it open until they drop it: a reference to the old PDO object from an earlier `getPdo()`; a `PDOStatement` of it; an exception whose trace holds one of them, directly or through another exception - a trace keeps the arguments of the calls the exception was thrown through unless `zend.exception_ignore_args` is on (off is PHP's default without a php.ini): the exception of a statement that failed on it, the `error` the end listeners of a refused `commit()` are told and the `CommitFailedException` the caller gets, and the `error` of the `lost` that `reconnect()` tells when it is called from where such an exception is an argument (an `error` listener, a helper handed the failed query's exception) - an end listener that keeps that `error` keeps the old connection -; and the call `reconnect()` is made from: `query()` holds its statement while its `query` and `error` listeners run, an error handler runs inside the call into PDO. Session locks (`GET_LOCK()`) and temporary tables of the old session last as long as its connection; the locks of its transaction end with the `ROLLBACK` sent there - when that ROLLBACK fails (it is best effort), they too last as long as the connection. An error handler that reconnects in the middle of a statement and begins a transaction there goes further: the failure of that statement may be remembered for the new transaction, and the old statement with it, until that transaction ends - on MySQL/MariaDB a deadlock of the old connection then refuses the new transaction's statements and its `commit()` until `rollback()`. Reconnect after the call instead.
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

`insert()` returns the generated id as an integer on every database, and `0` when the database generated none (a table without `AUTO_INCREMENT`; on PostgreSQL a table without a `{table}_id_seq` sequence). An id that is no integer of PHP throws a `QueryException` (`Insert ID out of range`, the id in `getDebugMessage()`) instead of being cut: MySQL/MariaDB report a `BIGINT UNSIGNED` id above `PHP_INT_MAX` as it is, and a negative id written into an `AUTO_INCREMENT` column as a number of that size. The row is inserted at that point - inside `transaction()` the exception rolls it back, outside it stays -, and no `error` hook fires: the statement did not fail. Write such rows with `execute()` and read the id with `lastInsertId()`, which returns PDO's string.

`insertIgnore()` skips the row on a duplicate of **any** unique key or of the primary key and throws for everything else (`NOT NULL`, a foreign key, an unknown column), like `insert()`. On PostgreSQL a conflict with an exclusion constraint is skipped too, and a duplicate on a `DEFERRABLE` unique constraint throws (PostgreSQL cannot skip it). It returns the number of inserted rows, not an id; a skipped insert may still use up an auto-increment or sequence value. PostgreSQL and SQLite get `ON CONFLICT DO NOTHING`; MySQL/MariaDB get `ON DUPLICATE KEY UPDATE col = col` on the row's first column (not `INSERT IGNORE`, which would also swallow other errors) - there the existing row stays locked until the transaction ends, the table's `BEFORE INSERT` and update triggers run for it although nothing is updated (a `BEFORE UPDATE` trigger that changes the row does change it; the result is still 0), and on a connection opened with the PDO option `ATTR_FOUND_ROWS` the method throws, because the server then reports one affected row for an existing row as well. To find out *which* key collided, use `insert()` and catch `UniqueViolationException` (see [Exceptions](#exceptions)) - but not inside a PostgreSQL transaction: the failed insert aborts it, every later statement fails and the commit is refused.

Values are bound as prepared-statement parameters; a boolean arrives as `1`/`0` on every driver, so `['active' => false]` works in `insert()`, `update()` and `where()` alike (a text or binary column stores `'0'`; a MySQL `BIT` column does not store a bound `0`/`1` as bits, use `Database::raw('0')` or a `TINYINT(1)` column).

A value must be `null`, a scalar or a `Stringable` object. An array, a resource or any other object (a `DateTime`, an enum) throws a `QueryException` before the statement is sent - PDO would store an array as the text `Array`. Pass `$date->format('Y-m-d H:i:s')`, `$enum->value`, `json_encode($array)`. This holds for every method and for `query()`/`execute()`. There, a `Database::raw()` expression as a parameter throws as well (it would be bound as its own text): write it into the SQL. A custom driver that binds more (a stream as LOB) overrides `unbindableParameter()` along with `bindAndExecute()`.

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

`update()` and `delete()` return the number of affected rows as the database counts them: MySQL/MariaDB count the rows an `UPDATE` actually **changed** (0 when the row already had the values), PostgreSQL and SQLite the rows it **matched**. Do not use the return value of an update as "the row exists" on MySQL/MariaDB; ask with `exists()`.

### Update Multiple

```php
$db->updateMultiple('users', [
    ['id' => 1, 'name' => 'John'],
    ['id' => 2, 'name' => 'Jane'],
], 'id');  // key column
```

A row that holds nothing but the key column is skipped (nothing to update). **Note:** This method executes one UPDATE query per row within a transaction. Best suited for batch sizes under ~100 rows. For larger datasets, consider using `execute()` with database-specific bulk update syntax (e.g., `INSERT ... ON DUPLICATE KEY UPDATE` for MySQL).

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

The array form needs column names as keys; a list (`where(['active', 1])`) or a numeric column name as key throws a `QueryException` (use `where('2024', $value)` for the latter). `whereBetween()`/`whereNotBetween()` throw on a `null` bound: `BETWEEN` with NULL matches no row; an open range is a `where()` with `>=` or `<=`. `whereIn()`/`whereNotIn()` throw on a `null` element: `IN` never matches NULL, and `NOT IN` with a NULL in the list matches no row at all - add `whereNull()`/`whereNotNull()` for it.

`IS` and `IS NOT` with a bound value compare **null-safely**: `where('nick', 'IS NOT', 'anna')` also matches rows whose `nick` is NULL, and the value itself may be `null` - `where('parent_id', 'IS', $parentId)` finds the rows with that parent, or the rows without one when `$parentId` is null. The builder renders them in each database's own syntax (SQLite `IS`, MySQL/MariaDB `<=>`, PostgreSQL `IS NOT DISTINCT FROM`). With a raw value (`where('flag', 'IS', Database::raw('TRUE'))`) the SQL is passed through unchanged, so truth tests keep their database semantics. For a plain NULL test use `whereNull()` / `whereNotNull()`.

`whereRaw()` takes a condition the other methods cannot express - an expression on the left, an OR group, a database function - with its values bound in order; it is joined to the other conditions with AND, in parentheses. The SQL is trusted developer code (never build it from user input; user input goes into the bindings):

```php
$users = $db->table('users')
    ->where('status', 'active')
    ->whereRaw('LOWER(email) = ?', [$email])
    ->whereRaw('score > ? OR created_at < ?', [100, $cutoff])
    ->get();
// SELECT * FROM "users" WHERE "status" = ? AND (LOWER(email) = ?) AND (score > ? OR created_at < ?)   (PostgreSQL quoting; backticks on MySQL/MariaDB and SQLite)
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

`offset()` works without `limit()` on every driver (MySQL/MariaDB and SQLite get the "no limit" value they require). A negative `limit()` or `offset()` throws a `QueryException` (SQLite would read a negative limit as "no limit").

### Row Locks

Inside a transaction, `lockForUpdate()` locks the selected rows until the commit; `sharedLock()` keeps others from updating them while still allowing reads:

```php
$db->transaction(function ($db) use ($id) {
    $account = $db->table('accounts')->where('id', $id)->lockForUpdate()->first();
    $db->update('accounts', ['balance' => $account['balance'] - 10], ['id' => $id]);
});
```

MySQL/MariaDB render `FOR UPDATE` / `LOCK IN SHARE MODE`, PostgreSQL `FOR UPDATE` / `FOR SHARE`. SQLite has no row locks: the clause is omitted there (its write lock covers the whole database file). `exists()` keeps the lock (`SELECT 1 ... LIMIT 1`); aggregates such as `count()` drop it, because PostgreSQL rejects `FOR UPDATE` with aggregates. A lock combined with `distinct()`, `groupBy()` or `having()` throws a `QueryException` (not portable); PostgreSQL also rejects a lock on the nullable side of a `leftJoin()`/`rightJoin()` - use a raw query with `FOR UPDATE OF table` there.

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

A string given to `groupBy()` (and `select()`) is split at commas into column names, so an expression belongs in `Database::raw()`. `having()` with `null` throws a `QueryException` (`= NULL` is never true) unless the operator is `IS` or `IS NOT`, the null-safe comparison.

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

`sum()` and `avg()` return the number as the database delivers it, not converted to a float: what the database computed exactly - a sum of `BIGINT` values beyond 2^53, a `DECIMAL` sum of money - arrives exactly. That is an integer, a float or a numeric string, depending on the database and the column type (see [Database Differences](#database-differences)), and `null` when there is nothing to add up (no rows, or only `NULL`). Cast where a number is wanted - `(float) $db->table('orders')->sum('total')` -, or hand the string to an arbitrary-precision function (`bcadd()`).

`sum()`, `avg()`, `min()` and `max()` combined with `groupBy()` throw a `QueryException`: one value per group is ambiguous, select the aggregate explicitly with `Database::raw()` and `get()` instead. `distinct()->count()` counts a derived table, which needs unique output names (MySQL rejects repeated ones): two columns named alike (`users.id`, `orders.id`), a wildcard next to other entries, or a bare `*` over a join throw a `QueryException` - alias the columns (`orders.id as order_id`) or use `count('column')`; a single `table.*` is fine, `Database::raw()` entries are not inspected. With `groupBy()`, only aliased `select()` entries (`'country as c'`, `Database::raw('LOWER(name) AS ln')`, `Database::raw('COUNT(*) AS n')`) stay in the counted query, so `groupBy('ln')` works everywhere and `having('n', '>', 1)` where the database accepts select aliases in `HAVING` (MySQL/MariaDB and SQLite, not PostgreSQL). `having()` without `groupBy()` treats the whole result as one group: `count()` returns its row count, and `distinct()` only applies to `count('column')` then.

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

// Delete in batches, oldest first - MySQL/MariaDB only (DELETE ... ORDER BY ... LIMIT);
// PostgreSQL and SQLite throw here instead of deleting every matching row.
// Order by a unique key (or add one as tie-breaker) so that each batch is deterministic.
$deleted = $db->table('logs')
    ->where('created_at', '<', $cutoff)
    ->orderBy('created_at')
    ->orderBy('id')
    ->limit(500)
    ->delete();

// The same for update(): UPDATE ... ORDER BY ... LIMIT, MySQL/MariaDB only
$claimed = $db->table('jobs')
    ->where('status', 'queued')
    ->orderBy('id')
    ->limit(10)
    ->update(['status' => 'claimed']);

// Insert only when a condition holds, in one statement:
// the row's values are bound first, then the condition's bindings
$inserted = $db->table('codes')->insertWhen(
    ['user_id' => $userId, 'code' => $code],
    'NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL)',
    [$userId]
); // 1 or 0

// Insert unless a unique key or the primary key collides
$inserted = $db->table('subscriptions')->insertIgnore(['user_id' => $userId, 'topic' => 'news']); // 1 or 0
```

`insertWhen()` renders `INSERT INTO codes (...) SELECT ?, ? WHERE (condition)` (`FROM DUAL` on MySQL/MariaDB). The condition is trusted developer SQL, like `whereRaw()`: never build it from user input. Check and insert see one snapshot, but two concurrent calls can still both insert: an invariant like "one open code per user" needs a `UNIQUE` constraint, a row lock (`lockForUpdate()` on the user row) or `SERIALIZABLE` on top. After a return of 0, `lastInsertId()` is meaningless. Clauses set on the builder (`where*()`, joins, `groupBy()`/`having()`, `orderBy()`, `limit()`/`offset()`, `distinct()`, locks) are not part of the statement and make `insertWhen()` and `insertIgnore()` throw; a `select()` is ignored.

### Debug Query

```php
[$sql, $params] = $db->table('users')
    ->where('active', 1)
    ->orderBy('name')
    ->toSql();

// $sql = 'SELECT * FROM "users" WHERE "active" = ? ORDER BY "name" ASC'   (PostgreSQL; MySQL/MariaDB and SQLite quote with backticks)
// $params = [1]
```

## Timestamps and Raw Values

`now()` and `utcNow()` return the database's current time (at statement time, to the second) as a raw SQL expression, so every driver uses its own dialect and the clock of the database server, not PHP's:

```php
$db->insert('logs', ['message' => 'started', 'created_at' => $db->utcNow()]);
$db->table('sessions')->where('expires_at', '<', $db->now())->delete();
```

| Driver | `now()` (local time) | `utcNow()` |
|---|---|---|
| MySQL / MariaDB | `NOW()` | `UTC_TIMESTAMP()` |
| PostgreSQL | `CAST(statement_timestamp() AS TIMESTAMP(0))` | `CAST(statement_timestamp() AT TIME ZONE 'UTC' AS TIMESTAMP(0))` |
| SQLite | `datetime('now', 'localtime')` | `datetime('now')` |

"Local" is the database session's time zone; SQLite takes it from the operating system, not from PHP's `date.timezone`. When PHP and the database may run in different zones, `utcNow()` is the unambiguous choice. Both are **zoneless** values, meant for `DATETIME`, `TIMESTAMP WITHOUT TIME ZONE` or `TEXT` columns: a zone-aware column (PostgreSQL `TIMESTAMPTZ`, MySQL `TIMESTAMP`) would interpret `utcNow()` in the session's time zone and store a shifted instant unless the session runs in UTC.

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
// MySQL/MariaDB: UPDATE `outbox` SET `attempts` = attempts + 1, `next_attempt_at` = ? + LEAST(...), `status` = ? WHERE `id` = ?
// params: [time(), 'retry', $id]
```

The SET list is written in the order of the array, and that order matters on MySQL/MariaDB: they evaluate the assignments left to right, so `next_attempt_at` above is computed from the **raised** `attempts`. PostgreSQL and SQLite compute every assignment from the row as it was (standard SQL); write the expression so that it does not depend on the order if the statement has to mean the same everywhere. Only an expression used as a value may carry bindings: in `select()`, `groupBy()` and as the column of `having()` it throws a `QueryException` (its values would have to be placed in front of all others) - use `whereRaw()` for a condition, or `query()` for the whole statement.

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
        if ($db->inTransaction()) {
            $db->rollback();
        }
    } catch (Throwable) {
    }
    throw $e;
}
```

`transaction()` ends in one of these ways (`updateMultiple()` too, when it opens its own transaction):

- **Success** - committed, the callback's return value is returned.
- **The transaction cannot be started** (`BEGIN` fails, a `transaction.begin` hook throws, or such a hook ends the transaction it was told about - then no further begin hook runs either) - the callback does not run; after a throwing hook a rollback is attempted (best effort) and `transaction.end` is told for that transaction (`rolled_back` when the rollback went through, `lost` when it did not); the exception is re-thrown, a `PDOException` from the hook as `TransactionException`. When `BEGIN` itself fails, there was no transaction: nothing is told.
- **The callback throws** - rollback attempted, the callback's exception is re-thrown unchanged. Best effort: if the rollback itself fails, the connection may still be in a transaction.
- **The commit fails** - rollback attempted, `CommitFailedException` (a `TransactionException`) is thrown. Its `$outcome` says what became of the transaction, in the words of `transaction.end`: `rolled_back` when the rollback after it is confirmed - nothing is committed - and `lost` when it is not: the commit may or may not have taken effect (e.g. connection lost during `COMMIT`).
- **The callback swallowed a statement error that ended the transaction on the server** - on PostgreSQL every statement error aborts the transaction (unless a savepoint caught it), on MySQL/MariaDB a deadlock rolls it back, and in both cases the server would answer `COMMIT` with success although nothing of the transaction is committed. The library therefore refuses to send that `COMMIT`: `CommitFailedException`, `getPrevious()` is the statement's error. The rollback follows, and `transaction.end` and the exception's `$outcome` say `rolled_back` when it is confirmed - `lost` when MySQL/MariaDB report the transaction gone already (nothing is left to roll back) or cannot be asked whether it still exists. A manual `commit()` stays refused until `rollback()`. After a MySQL/MariaDB deadlock the library accepts nothing on the connection but the end of that transaction: a statement would run outside of it and be committed on its own, so it throws a `QueryException` instead (`getPrevious()` is the deadlock; no hook fires for it) - what PostgreSQL does by itself in an aborted transaction - and `beginTransaction()` refuses. `rollback()` is the way out. Never carry on after a deadlock: let the exception end the transaction and run it again. After a failure other than a deadlock the library asks the server before it commits (one extra statement, only then). Raw PDO (`getPdo()`) is outside all of this: failures there are not seen, and a statement sent there after a deadlock is committed on its own - `commit()` is still refused, and with nothing left to roll back the refusal itself reports `transaction.end` as `lost`, on a manual `commit()` too. The same `lost` follows a swallowed lock wait timeout on a server that runs with `innodb_rollback_on_timeout`: nothing is held back after it, so later statements ran in autocommit, and the question to the server before the commit finds the transaction gone. After a deadlock, end the transaction with `rollback()`: for a transaction begun through the library it also works when PDO no longer reports it (nothing is sent, the end is `lost`), and a transaction ended and begun again on raw PDO stays refused, statements included, until `rollback()` is called. All of this describes autocommit, the default: with autocommit switched off, a statement after the transaction's end opens the next transaction instead of being committed on its own - after a deadlock the refusals hold all the same and the rollback undoes it; a swallowed lock wait timeout that ended the transaction is not detected there.
- **The callback ends the transaction itself** (a `commit()` or `rollback()` of its own, or of a hook) - `transaction()` ends only the transaction it began. Whatever is open afterwards - a second transaction of the callback, one a hook began in response - is neither committed nor rolled back for it and is left to whoever began it. A callback that returns gets a `CommitFailedException` with `lost`, and no `COMMIT` is sent; one that throws gets its exception back. `transaction()` is for one transaction: run several with one call each, or with `beginTransaction()` and `commit()` yourself.
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

A session that chains transactions (MySQL/MariaDB `completion_type=CHAIN`) is not supported: every `COMMIT` and `ROLLBACK` opens the next transaction, which nobody would commit. The library reports it instead of continuing silently: after a commit as `CommitHookException` (first failure `Connection is in a new transaction`, commit hooks skipped, `connectionInTransaction` true), after a rollback as `TransactionException` once the rollback and end hooks ran (through the `error` hook instead where another exception reaches you: the callback's on the automatic rollback in `transaction()`, or a rollback hook's; after a `ROLLBACK` that confirmed nothing only the end hooks run, with `lost`). The hooks of such a commit or rollback already run inside the chained transaction.

## Hooks

Register callbacks for query logging, debugging, or monitoring:

```php
// Log all queries
$db->on('query', function (array $data) {
    echo "SQL: {$data['sql']}\n";
    echo "Params: " . count($data['params']) . "\n"; // the values themselves may be secrets, see below
    echo "Duration: {$data['duration']}s\n";
    echo "Rows: {$data['rows']}\n";
});

// Log errors: what the database said is in sqlState and driverCode, as on the exception
$db->on('error', function (array $data) {
    error_log("Query failed: {$data['error']} | SQLSTATE: {$data['sqlState']} | SQL: {$data['sql']}");
});

// Transaction hooks: every event names its transaction (see below)
$db->on('transaction.begin', fn(array $data) => print "Transaction {$data['transaction']} started\n");
$db->on('transaction.commit', fn(array $data) => print "Transaction {$data['transaction']} committed\n");
$db->on('transaction.rollback', fn(array $data) => print "Transaction {$data['transaction']} rolled back\n");

// One listener for every outcome: 'committed', 'rolled_back' or 'lost'
$db->on('transaction.end', function (array $data) use ($pending) {
    $data['outcome'] === DatabaseInterface::TRANSACTION_COMMITTED ? $pending->flush() : $pending->discard();
});
```

| Event | Payload |
|---|---|
| `transaction.begin` | `['transaction' => int, 'depth' => int]` |
| `transaction.commit`, `transaction.rollback` | `['transaction' => ?int, 'depth' => ?int]` |
| `transaction.end` | `['outcome' => string, 'error' => ?Throwable, 'transaction' => ?int, 'depth' => ?int]` |

`transaction` is the number of the transaction: the transactions begun through the driver are counted, from 1, for as long as the driver lives (`reconnect()` does not start over), so that a listener can match an end to its begin instead of relying on their order. `depth` counts the transactions whose `transaction.begin` was told and whose `transaction.end` was not yet, this one included: 1 for a transaction begun while no other one owed its end, 2 for one a `transaction.commit` listener runs (the committed one's end is told after the commit listeners), 3 for one a second commit listener runs while the transaction the first one left open still waits for its end. A transaction begun on raw PDO (`getPdo()->beginTransaction()`) was not told begun: its commit, rollback and end carry `null` for both. Every `transaction.begin` is followed by exactly one `transaction.end` with the same number - also when a begin listener threw, when a commit listener began a transaction and ended it on raw PDO (`lost`), and when the connection is discarded (`reconnect()`, `lost`). The numbers are the library's: a `transaction.begin` or `transaction.rollback` listener that takes the payload by reference and changes them changes what the listeners after it are told - these two, like `query` and `error`, hand one payload from listener to listener -, not what the library tells later: the end carries the number the begin was given. `transaction.commit` and `transaction.end` listeners get an array of their own each and cannot take it by reference (PHP throws an `Error`, which is that listener's failure).

`transaction.end` fires exactly once for every transaction the library ends, after the `transaction.commit` or `transaction.rollback` listeners, with `['outcome' => ..., 'error' => ?Throwable, 'transaction' => ?int, 'depth' => ?int]`. After a throwing `transaction.begin` listener no rollback listener runs: the transaction is rolled back on raw PDO, and the end is `rolled_back` (or `lost` when that rollback did not go through), with the exception the caller gets as `error`. `rolled_back` carries the exception that ended the transaction (null after a manual `rollback()`). `lost` is the case the rollback listeners never see: the library could not confirm a rollback, because the connection was lost, the raw cleanup of a commit hook's transaction did not end it (MySQL `completion_type=CHAIN`), PDO no longer reported the transaction, the connection state could not be read, on MySQL/MariaDB the server said before the rollback that the transaction is gone after a statement had failed (or could not be asked: the `ROLLBACK` is then still sent, but confirms nothing), or the commit failed and so did the rollback after it (then the data may be committed, fail-closed, and `error` is the commit's exception: PostgreSQL lands here when `COMMIT` fails on a deferred constraint, although the server rolled back, but so does a callback that committed itself with a raw `COMMIT` or a MySQL DDL statement, and then the data is committed). The connection may be gone, so listeners must not expect queries to work. If the transaction may in fact still be open, end it with `rollback()` or discard the connection (`reconnect()`): that `rollback()` runs the rollback listeners but tells no second end. Ending it on raw PDO instead leaves that mark in place: a transaction then begun on raw PDO and ended through the library tells no end (`beginTransaction()` clears the mark). All end listeners run; their failures land in `CommitHookException::$failures` after a commit (behind the commit listeners' failures: first the ends of transactions commit listeners left open, then the committed transaction's end), as `TransactionException` after a manual `rollback()` (unless a rollback listener threw: that exception wins), and only in the `error` hook (with `hook`, `outcome` and `exception` keys; a throwing `error` listener is ignored there) after the automatic rollback in `transaction()`/`updateMultiple()` and on a `lost` reported there, so the exception that ended the transaction reaches you unchanged. A failed manual `commit()` or `rollback()` fires nothing: that transaction is still yours to end (one exception: a failed or refused commit of a transaction PDO no longer reports tells `lost` at once, because nothing could end it afterwards). After the commit `transaction()`/`updateMultiple()` ran themselves has failed, `error` is the `CommitFailedException` the caller gets, and its `$outcome` is the outcome the listener is told. A `commit()` the callback called itself and let escape is the `error` as well, with `$outcome` null (or `lost`, if that commit had told the end itself). A transaction a commit listener starts through the library and leaves open is rolled back without rollback hooks but with its own `transaction.end` (after all commit listeners, before the outer end); one a rollback or end listener leaves open is not checked. A commit the library refuses (see [Transactions](#transactions): a swallowed statement error on PostgreSQL, a swallowed deadlock on MySQL/MariaDB) ends as `rolled_back`, or as `lost` when nothing was left to roll back (MySQL/MariaDB with autocommit, the default: after statements on raw PDO following a deadlock, or after a lock wait timeout that ended the transaction), never as `committed`. An `error` hook that writes to the database after a failed statement needs its own connection: on PostgreSQL every statement in an aborted transaction fails, and after a MySQL/MariaDB deadlock the library sends none.

The `error` payload carries `sql` and `params` as passed, `error` (the message), `code` (the code of the reported exception: the SQLSTATE string of a failed statement, the driver's number in a non-exception error mode, `0` for an exception of the library, a foreign exception's own code), and `sqlState` and `driverCode` - what the database said, read by the rule of `$sqlState` and `$driverCode` (see [Exceptions](#exceptions)): for a failed statement the same values as on the exception the caller gets; `null` where no database failure stands behind the reported error (a parameter the library refused to bind, a chained transaction, a listener's exception that carries no codes). Where the library hands an exception to the hook instead of, or besides, throwing it - a failing `transaction.end` listener (after any rollback or `lost`), a chained transaction -, the codes are that exception's, and `outcome` and `exception` are added; `hook` (`'transaction.end'`) only for a listener's failure.

**Parameters are secrets.** The `query` and `error` payloads carry the SQL and the parameters exactly as passed - password hashes, tokens, personal data - and `QueryException::getDebugMessage()` contains both as well. Never write them to a log or an error page unredacted: log the SQL and the error, and of the parameters at most their number or a whitelisted subset. The `error` hook also fires (with `code` 0) for a parameter that must not be bound.

For `query`, `error` and `transaction.begin`, a throwing hook stops the remaining hooks of its event and its exception reaches the caller (a `PDOException` from a `query` hook arrives as `QueryException` with the message `Query hook failed`: the statement did run and no `error` hook fires); after a throwing `transaction.begin` hook a rollback of the transaction it was told about is attempted first (best effort, directly and without `transaction.rollback` hooks, then its `transaction.end`; if that rollback fails, the transaction may still be open and its end is `lost`) - unless the hook ended it itself; a transaction it began afterwards is left open, with its end owed. A throwing `transaction.rollback` hook does the same on a manual `rollback()`, but is ignored during the automatic rollback in `transaction()` and `updateMultiple()` (the original exception is re-thrown). A throwing `error` hook therefore replaces the `QueryException` of the failed statement. `insert()` reads the new id before the `query` hooks run, so a hook may itself insert on the same connection. `on()` throws a `DatabaseException` for an event name it does not know - a listener for a misspelled name would never run; a custom driver that triggers events of its own names them by overriding `knownEvents()`. `transaction.commit` hooks run after the commit and cannot undo it, so they work differently:

- **All of them run**, even if one throws (only exception: see the last point). Keep them independent: steps that depend on each other belong in one hook.
- **Failures are reported together** as `CommitHookException`: `getPrevious()` is the first failure, `$e->failures` lists all of them in hook order.
- **A transaction a hook leaves open** is rolled back before the next hook runs - directly, without `transaction.rollback` hooks - and reported as a `LogicException`. If that rollback fails or does not end the transaction (MySQL `completion_type=CHAIN` opens the next one), or the connection state cannot be read, the remaining hooks are skipped, listed as failures, and `$e->connectionInTransaction` is `true`: the connection is, or may still be, in a transaction (an unreadable state counts as `true`, fail-closed). Do not continue on that connection as if it were in autocommit: check `$db->inTransaction()` and roll back, or discard the connection.
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
    $db = Database::mysql([...]);
} catch (ConnectionException $e) {
    // Connection failed
    echo $e->getMessage();        // User-friendly message
    echo $e->getDebugMessage();   // Detailed debug info
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
    $key = $e->constraint; // 'email' / 'PRIMARY' on MySQL/MariaDB, 'users_email_key' / 'users_pkey' on PostgreSQL, null on SQLite
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

A `CommitHookException` the library throws means the data is committed; a hook failed, the connection state could not be verified or cleaned up after a hook, or the connection was in a new transaction right after the commit (MySQL/MariaDB `completion_type=CHAIN`: no commit hook ran). One that a PDO class of the caller's throws from its own `commit()` is not the library's word: `transaction()` rolls back and passes it on (see [The PDO Class](#the-pdo-class)). It extends `DatabaseException`, not `TransactionException`: a broad `catch (DatabaseException)` also sees committed data, so catch `CommitHookException` first where that matters.

Every exception of the library carries what the database said in `$sqlState` and `$driverCode`, taken from PDO's `errorInfo`: the SQLSTATE as the five characters the database sent (`'42S02'`, `'23505'`, `'HY000'` - compare as a string) and the driver's own error number (MySQL/MariaDB: `1062`, `1146`, `1213`; SQLite: `19`, `1`; PostgreSQL has none, PDO reports `7` for everything - use `$sqlState` there). Both are `null` when no database failure stands behind the exception (a refused argument), an exception that wraps another one of the library hands its codes on, and a failure PDO reports by returning `false` carries them like a thrown one. An exception the library puts around what a hook threw after the operation went through - `CommitHookException`, `Query hook failed`, a `TransactionException` for a failing `transaction.rollback` or `transaction.end` hook - carries none: the statement ran, the transaction was committed or rolled back, and a retry on `sqlState === '40001'` must not run it again. The hook's codes are in `getPrevious()`. A failing `transaction.begin` hook makes the begin fail, so its codes are handed on - except where the transaction it was told about ended behind the library's back or its state could not be found out (`lost`): nothing is certain there, and there are no codes; and a hook's own exception that passes unchanged (a `QueryException` of a statement it ran) keeps what it carries - catch in the hook what must not look like the caller's failure. `getCode()` is always `0`: PHP's exception code is an integer and cannot hold an SQLSTATE.

`UniqueViolationException` tells "this row exists already" from every other failed statement without looking at driver error codes; it is recognized by the driver's own code for it (MySQL/MariaDB 1062, PostgreSQL SQLSTATE 23505, SQLite's `UNIQUE constraint failed`), on inserts and updates alike. `$constraint` is the name the database reports: the index name on MySQL/MariaDB (`PRIMARY` for the primary key; MySQL since 8.0.19 prints `table.key`, MariaDB and older MySQL versions `key`, all yield the key - where the table is in front, a table or key name that itself contains a dot makes the printed name ambiguous and yields `null` rather than a wrong name; the same goes for a name with a dot wherever the server's version cannot be read. The server is told by its version string: behind a proxy, or on a MySQL-compatible server, whose reported version does not match its message format, an older version in front of a newer MySQL yields `table.key` for every key, and a newer one in front of MariaDB or an older MySQL cuts a key name that contains a dot), the constraint name on PostgreSQL, and `null` on SQLite, which names columns only. It is read from the server's English error message: a server set to another message language yields `null`, so compare it as a hint, and branch on the class.

**Upgrading from 1.0:** a failing `transaction.commit` hook used to surface as its own exception (a `PDOException` from a hook even as `TransactionException`); it now arrives as `CommitHookException` with the hook's exception as `getPrevious()`.

## Schema-Qualified Tables

For PostgreSQL schemas or MySQL database-qualified names:

```php
// PostgreSQL
$db->insert('public.users', ['name' => 'John']);
$db->table('public.users')->where('id', 1)->first();

// MySQL
$db->insert('mydb.users', ['name' => 'John']);
$db->table('mydb.users')->where('id', 1)->first();
```

## Database Differences

The same call gives the same result on every database wherever the databases allow it. Where they do not, the library throws instead of doing something else silently. What differs:

| | MySQL / MariaDB | PostgreSQL | SQLite |
|---|---|---|---|
| Identifier quoting | backticks | double quotes (names are case-sensitive) | backticks |
| `update()` / `delete()` with `limit()` | `ORDER BY ... LIMIT n` | throws | throws |
| `lockForUpdate()` / `sharedLock()` | `FOR UPDATE` / `LOCK IN SHARE MODE` | `FOR UPDATE` / `FOR SHARE` | omitted (one write lock per file) |
| `insert()` returns | the `AUTO_INCREMENT` id; 0 for a table without one; throws for an id above `PHP_INT_MAX` (`BIGINT UNSIGNED`) and for a negative id in an `AUTO_INCREMENT` column | the value of `{table}_id_seq`, 0 without that sequence | the rowid; for a `WITHOUT ROWID` table the rowid of the connection's last insert elsewhere (meaningless) |
| `insertIgnore()` | `ON DUPLICATE KEY UPDATE col = col`: the existing row is locked until the transaction ends and its update triggers run; throws with `ATTR_FOUND_ROWS` | `ON CONFLICT DO NOTHING`: also skips a conflict with an exclusion constraint; throws for a duplicate on a `DEFERRABLE` unique constraint | `ON CONFLICT DO NOTHING` |
| `UniqueViolationException::$constraint` | index name (`email`, `PRIMARY`); `null` when the table or key name contains a dot and MySQL 8.0.19+ puts the table in front (or the server version cannot be read) | constraint name (`users_email_key`, `users_pkey`) | `null` |
| `sum()` returns | a numeric string for integer and `DECIMAL` columns (`'75'`, `'0.3000'`), a float for `FLOAT`/`DOUBLE` | an integer for `SMALLINT`/`INTEGER` columns, a numeric string for `BIGINT` and `NUMERIC`, a float for `REAL`/`DOUBLE PRECISION` | an integer as long as every value is one (the statement fails when the sum overflows 64 bits), otherwise a float - also for a `DECIMAL` column, SQLite has no decimal type |
| `avg()` returns | a numeric string (`'1.5000'`), a float for `FLOAT`/`DOUBLE` | a numeric string (`'1.5000000000000000'`), a float for `REAL`/`DOUBLE PRECISION` | a float |
| Rows an `update()` returns | rows actually changed (0 when the values were already there) | rows matched | rows matched |
| Several assignments in one `update()` | evaluated left to right: a later one sees what an earlier one set | all computed from the row as it was | all computed from the row as it was |
| `insertWhen()` | `... FROM DUAL WHERE` | `... WHERE` | `... WHERE` |
| `IS` / `IS NOT` with a value | `<=>` | `IS [NOT] DISTINCT FROM` | `IS` / `IS NOT` |
| Select alias in `having()` | allowed | rejected by the database | allowed |
| `LIKE` and upper/lower case | case- and accent-insensitive with the default collations (`a%` matches `Anna` and `Ärger`) | case-sensitive (`a%` matches neither) | case-insensitive for ASCII letters only (`a%` matches `Anna`, `ä%` does not match `Ärger`) |
| `orderBy()` and NULL | NULL first ascending, last descending | NULL last ascending, first descending | NULL first ascending, last descending |
| `rightJoin()` | supported | supported | needs SQLite 3.39 or newer (older versions reject the statement) |
| `rows` in the `query` hook after a SELECT | number of rows | number of rows | always 0 |
| Booleans as parameters | `'1'` / `'0'` | `'1'` / `'0'` | `1` / `0` (integer) |
| Integers as parameters | sent as text, converted by the server | sent as text, typed by the server | integer |
| Floats as parameters | sent as text, converted by the server | sent as text, typed by the server (an integer column rejects `1.5`) | text: a column converts it, an expression (`price * 2 > ?`, `HAVING SUM(price) > ?`) does not - write `CAST(? AS REAL)` in `whereRaw()`; `having()` has no place for it, use a raw query there |
| A failed statement inside a transaction | only the statement is undone, except where the server ends the transaction: after a deadlock the library accepts nothing but `rollback()` (statements, `beginTransaction()` and `commit()` are refused); after a lock wait timeout under `innodb_rollback_on_timeout` later statements run in autocommit and `commit()` refuses (not detected with autocommit switched off) | aborts the transaction (unless a savepoint caught it): every later statement fails, `commit()` refuses | only the statement is undone (unless the statement itself asks for more: `ON CONFLICT ROLLBACK`) |
| DDL inside a transaction | most DDL statements (`CREATE TABLE`, `ALTER TABLE`, not `CREATE TEMPORARY TABLE`) commit it implicitly, also when the statement itself fails. `transaction()` reports `lost`, and so does a `rollback()` right after the failed statement; once a later statement has shown that no transaction is open, `rollback()` fails and the next `beginTransaction()` reports it | transactional | transactional |
| `now()` / `utcNow()` | `NOW()` / `UTC_TIMESTAMP()` | `statement_timestamp()`, cast to seconds | `datetime('now', 'localtime')` / `datetime('now')` |

Everything else - `where*()`, joins, `orderBy()`, `limit()`/`offset()`, `groupBy()`/`having()`, aggregates, `LIKE` with its bound escape character, CRUD methods, transactions and hooks - is rendered so that the same call means the same on all three; the test suite runs the same scenarios against MySQL 8.0/8.4, MariaDB 10.11/11.4, PostgreSQL 15-17 and SQLite.

## Security

This library protects against SQL injection through:

- **Prepared statements** for all values (WHERE, INSERT, UPDATE) - the one exception is a `Database::raw()` expression given as a value, which is inlined by design
- **Identifier quoting** for all column and table names
- **Operator whitelist** validation (only `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IS`, `IS NOT`)
- **Connection values that cannot redirect the connection**: `host`, `database` and (MySQL) `charset` are rejected with a `ConnectionException` before connecting if they contain a `;` (PDO's DSN separator) or NUL; PostgreSQL values are additionally quoted the libpq way, because libpq would otherwise treat a space inside a value (`app host=evil`) as the start of another parameter; `port` must be a whole number between 1 and 65535, and a SQLite path that is empty or contains a NUL byte (which would cut the path short) is rejected; the config arrays are marked `#[\SensitiveParameter]`, so PHP keeps the password out of stack traces
- **No multi-statements on MySQL/MariaDB** by default (see [Connection Options](#connection-options))

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
    ->select(['id', 'name', 'email'])  // Becomes: "id", "name", "email" (PostgreSQL) or `id`, `name`, `email` (MySQL/MariaDB, SQLite)
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

The builder renders every `LIKE` and `NOT LIKE` (`whereLike()`, `whereNotLike()`, and `where()`, `having()` and `join()` with the operator) as `LIKE ? ESCAPE ?` and binds the backslash as the escape character. The escape character therefore does not depend on the engine or its SQL mode (MySQL's `NO_BACKSLASH_ESCAPES` would otherwise turn the escaped `%` back into a wildcard), and `toSql()` returns one more parameter per `LIKE`. A `LIKE` you write yourself in `whereRaw()` or a raw query gets no escape clause: add `ESCAPE ?` and bind `'\\'` there.

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

- **LIMIT/ORDER BY/JOIN in update/delete** - `offset()`, `join()` (also `leftJoin()`/`rightJoin()`), `groupBy()`, `having()` and an `orderBy()` without `limit()` are not supported with `update()` or `delete()`: they are not part of the generated statement, and ignoring them could silently change the affected rows. The QueryBuilder throws an exception if you try, also for combinations that happen to be row-neutral (such as `groupBy()` on the primary key). The one exception is `orderBy()->limit(n)` with `update()` or `delete()` on MySQL/MariaDB (see above). On PostgreSQL and SQLite use a subquery (MySQL and MariaDB reject `LIMIT` inside `IN (...)`, MySQL also a subquery on the target table - there the builder form above is the way):
  ```php
  // Delete the 10 oldest logs - PostgreSQL and SQLite
  $db->execute(
      'DELETE FROM logs WHERE id IN (SELECT id FROM logs ORDER BY created_at ASC LIMIT 10)'
  );
  ```
  `select()`, `distinct()` and a row lock on an `update()`/`delete()` have no meaning there and are ignored (the statement takes its own row locks), so a builder locked for a `first()` can be reused for the update.

- **NULL in where()** - `where('column', null)` throws an exception because `column = NULL` is always false in SQL. Use `whereNull()` or `whereNotNull()`, or the null-safe `where('column', 'IS', $value)` for a value that may be null. The `$where` arrays of the CRUD methods take no `null` either.

- **Aliases** - `'column as alias'` and `'table as alias'` quote the alias like every other name. The result key is the alias as written on every database, `orderBy()`, `groupBy()` and `'alias.column'` find it under that name (write it the same way each time: a quoted name is case-sensitive on PostgreSQL), and a reserved word is a valid alias. The alias must be a plain word (letters, digits, underscore). An alias inside a `Database::raw()` entry is sent as written: PostgreSQL folds a bare one to lower case.

- **Builder clauses on `insert()`** - `table('t')->where(...)->insert($row)` inserts the row and ignores the clauses; use `insertWhen()` for a conditional insert (there, clauses throw).

- **PostgreSQL primary key convention** - `insert()` assumes the primary key column is named `id` and reads it from the `{table}_id_seq` sequence in the table's schema: for a table without that sequence it returns 0, and for a row inserted with an explicit `id` it returns 0 or, after an earlier sequence-based insert on the same connection, that earlier value (with persistent connections that may be a value from an earlier request). A table name so long that PostgreSQL shortened the sequence name is not found either. For custom PK names or explicit ids, use a raw query with `RETURNING`:
  ```php
  $stmt = $db->query('INSERT INTO users (name) VALUES (?) RETURNING user_id', ['John']);
  $userId = $stmt->fetch()['user_id'];
  ```

These limitations keep the QueryBuilder simple and predictable. For complex queries, use the `query()` method with raw SQL - prepared statements still protect against SQL injection.

## Requirements

- PHP 8.5 or a later 8.x (`^8.5`)
- PDO extension
- Database-specific PDO driver (pdo_mysql, pdo_pgsql, pdo_sqlite)

## Testing

```bash
# Install dependencies
composer install

# Run SQLite tests only (no Docker needed)
./vendor/bin/phpunit --exclude-group mysql --exclude-group postgres

# Run full test suite (requires Docker)
docker compose up -d
./vendor/bin/phpunit
MYSQL_PORT=3307 ./vendor/bin/phpunit --group mysql   # the same MySQL tests against MariaDB
docker compose down
```

## Acknowledgments

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).

## License

MIT
