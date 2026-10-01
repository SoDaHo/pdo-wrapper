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
]);
```

### SQLite

```php
// In-memory database
$db = Database::sqlite(':memory:');

// File-based database
$db = Database::sqlite('/path/to/database.db');
```

SQLite identifiers are quoted with backticks. SQLite reads an unknown name in double quotes as a string literal (a typo in a column name then silently compares or sorts by a constant); a backtick-quoted name is always an identifier and fails with `no such column`, as it would on MySQL or PostgreSQL. Integers are bound as integers and booleans as `0`/`1` (PDO binds everything as text by default, and SQLite converts text to a number only through a column's affinity: `HAVING COUNT(*) > ?` with a text `1` is always false, and `false` would be stored as `''`).

**Upgrading to 1.2 with an existing SQLite database:** earlier versions bound every value as text. That matters wherever SQLite kept the text: a `false` is stored as `''` in every column type, and integers are TEXT in columns declared without a type or as `BLOB` (columns with numeric affinity converted them on write; `TEXT` columns keep text, which an integer parameter still matches through the column's affinity). An integer or boolean parameter no longer matches those rows in `=`, `IN`, `BETWEEN` or range comparisons, new numeric values sort before old text values, and `UNIQUE` tells the storage classes apart. Either keep passing strings for such columns (when writing and when reading), or convert the data once. Run each statement only on columns you know to hold booleans or integers, after checking for `UNIQUE` collisions; the integer statement converts only values whose integer round trip is lossless (so `'007'`, `'+5'`, `' 5'`, `'1.5'`, `''`, text and out-of-range numbers stay as they are), and `COLLATE BINARY` keeps a column collation such as `RTRIM` from matching trailing spaces:

```sql
-- a boolean column: former false
UPDATE t SET flag = 0 WHERE typeof(flag) = 'text' AND flag = '' COLLATE BINARY;
-- an integer column declared without a type or as BLOB: former integers
UPDATE t SET n = CAST(n AS INTEGER) WHERE typeof(n) = 'text' AND CAST(CAST(n AS INTEGER) AS TEXT) = n COLLATE BINARY;
```

### One Config for Every Environment

`Database::connect()` picks the driver from the config (`driver`) or from `DB_DRIVER`, and delegates to `mysql()`, `postgres()` or `sqlite()` with the same keys and environment fallbacks - MariaDB in production, SQLite in tests, one call:

```php
$db = Database::connect(['driver' => 'mysql', 'host' => 'localhost', 'database' => 'myapp', 'username' => 'root', 'password' => 'secret']);
$db = Database::connect(['driver' => 'sqlite', 'path' => ':memory:']);
$db = Database::connect(); // driver and connection values from the environment
```

Accepted driver names: `mysql` (also `mariadb`), `pgsql` (also `postgres`, `postgresql`), `sqlite`. A missing or unknown driver throws a `ConnectionException`.

### Environment Variables

All drivers support configuration via environment variables:

```php
// MySQL/PostgreSQL read from:
// DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_PORT

// SQLite reads from:
// DB_SQLITE_PATH

// Database::connect() reads the driver from:
// DB_DRIVER (mysql, pgsql or sqlite)
```

**Priority:** `$config` array > `$_ENV` > `getenv()`. The library checks `$_ENV` first (thread-safe), then falls back to `getenv()` for legacy compatibility. Use a library like [sodaho/env-loader](https://github.com/sodaho/env-loader) to load `.env` files.

## Raw Queries

```php
// SELECT query
$stmt = $db->query('SELECT * FROM users WHERE id = ?', [1]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// INSERT/UPDATE/DELETE (returns affected rows)
$affected = $db->execute('UPDATE users SET active = ? WHERE id = ?', [1, 5]);

// Get last insert ID
$id = $db->lastInsertId();

// Access underlying PDO — for features not covered by the wrapper
// (e.g., LOCK TABLES, driver-specific methods, passing PDO to third-party tools)
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
```

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

### Update Multiple

```php
$db->updateMultiple('users', [
    ['id' => 1, 'name' => 'John'],
    ['id' => 2, 'name' => 'Jane'],
], 'id');  // key column
```

**Note:** This method executes one UPDATE query per row within a transaction. Best suited for batch sizes under ~100 rows. For larger datasets, consider using `execute()` with database-specific bulk update syntax (e.g., `INSERT ... ON DUPLICATE KEY UPDATE` for MySQL).

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

`IS` and `IS NOT` with a bound value compare **null-safely**: `where('nick', 'IS NOT', 'anna')` also matches rows whose `nick` is NULL. The builder renders them in each database's own syntax (SQLite `IS`, MySQL/MariaDB `<=>`, PostgreSQL `IS NOT DISTINCT FROM`). With a raw value (`where('flag', 'IS', Database::raw('TRUE'))`) the SQL is passed through unchanged, so truth tests keep their database semantics. For a plain NULL test use `whereNull()` / `whereNotNull()`.

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

`offset()` works without `limit()` on every driver (MySQL/MariaDB and SQLite get the "no limit" value they require).

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
```

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

// Insert only when a condition holds, in one statement:
// the row's values are bound first, then the condition's bindings
$inserted = $db->table('codes')->insertWhen(
    ['user_id' => $userId, 'code' => $code],
    'NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL)',
    [$userId]
); // 1 or 0
```

`insertWhen()` renders `INSERT INTO codes (...) SELECT ?, ? WHERE (condition)` (`FROM DUAL` on MySQL/MariaDB). The condition is trusted developer SQL, like `whereRaw()`: never build it from user input. Check and insert see one snapshot, but two concurrent calls can still both insert: an invariant like "one open code per user" needs a `UNIQUE` constraint, a row lock (`lockForUpdate()` on the user row) or `SERIALIZABLE` on top. After a return of 0, `lastInsertId()` is meaningless. Clauses set on the builder (`where*()`, joins, `groupBy()`/`having()`, `orderBy()`, `limit()`/`offset()`, `distinct()`, locks) are not part of the statement and make `insertWhen()` throw; a `select()` is ignored.

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

Any `Database::raw()` expression works the same way as a **value** in `insert()`, `update()`, `where()`, `whereIn()`, `whereBetween()` and `having()`, in the CRUD methods and in the query builder alike. It is inlined into the SQL instead of being bound, which allows expressions on the row itself (in `where()`, the automatic `ESCAPE '\'` clause of `LIKE` on PostgreSQL and SQLite applies to raw patterns too):

```php
$db->update('counters', ['hits' => Database::raw('hits + 1')], ['id' => $id]);
$db->table('jobs')->where('attempts', '<', Database::raw('max_attempts'))->get();
```

**Security Note:** a raw value is not a bound parameter. Never build it from user input (see [Raw Expressions](#raw-expressions)).

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
    throw $e; // Committed: never roll back
} catch (Throwable $e) {
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
- **The transaction cannot be started** (`BEGIN` fails, or a `transaction.begin` hook throws) - the callback does not run; after a throwing hook a rollback is attempted (best effort); the exception is re-thrown, a `PDOException` from the hook as `TransactionException`.
- **The callback throws** - rollback attempted, the callback's exception is re-thrown unchanged. Best effort: if the rollback itself fails, the connection may still be in a transaction.
- **The commit fails** - rollback attempted, `TransactionException` is thrown. The commit may or may not have taken effect (e.g. connection lost during `COMMIT`).
- **A `transaction.commit` hook fails** (throws, or leaves the connection in a state that cannot be verified or cleaned up) - the data **is committed**, nothing is rolled back, `CommitHookException` is thrown (see [Hooks](#hooks)).

With a manual `commit()`, a failing commit hook likewise throws `CommitHookException` after the commit: the committed transaction cannot be rolled back and must not be retried. Only a transaction a hook left open (`$e->connectionInTransaction`) still needs a rollback.

## Hooks

Register callbacks for query logging, debugging, or monitoring:

```php
// Log all queries
$db->on('query', function (array $data) {
    echo "SQL: {$data['sql']}\n";
    echo "Params: " . json_encode($data['params']) . "\n";
    echo "Duration: {$data['duration']}s\n";
    echo "Rows: {$data['rows']}\n";
});

// Log errors
$db->on('error', function (array $data) {
    error_log("Query failed: {$data['error']} | SQL: {$data['sql']}");
});

// Transaction hooks
$db->on('transaction.begin', fn() => print "Transaction started\n");
$db->on('transaction.commit', fn() => print "Transaction committed\n");
$db->on('transaction.rollback', fn() => print "Transaction rolled back\n");
```

For `query`, `error` and `transaction.begin`, a throwing hook stops the remaining hooks of its event and its exception reaches the caller (a `PDOException` from a `query` hook arrives as `QueryException` with the message `Query hook failed`: the statement did run and no `error` hook fires); after a throwing `transaction.begin` hook a rollback of the transaction it was told about is attempted first (best effort, directly and without `transaction.rollback` hooks; if that rollback fails, the transaction may still be open). A throwing `transaction.rollback` hook does the same on a manual `rollback()`, but is ignored during the automatic rollback in `transaction()` and `updateMultiple()` (the original exception is re-thrown). `transaction.commit` hooks run after the commit and cannot undo it, so they work differently:

- **All of them run**, even if one throws (only exception: see the last point). Keep them independent: steps that depend on each other belong in one hook.
- **Failures are reported together** as `CommitHookException`: `getPrevious()` is the first failure, `$e->failures` lists all of them in hook order.
- **A transaction a hook leaves open** is rolled back before the next hook runs - directly, without `transaction.rollback` hooks - and reported as a `LogicException`. If that rollback fails or does not end the transaction (MySQL `completion_type=CHAIN` opens the next one), or the connection state cannot be read, the remaining hooks are skipped, listed as failures, and `$e->connectionInTransaction` is `true`: the connection is, or may still be, in a transaction (an unreadable state counts as `true`, fail-closed). Do not continue on that connection as if it were in autocommit: check `$db->inTransaction()` and roll back, or discard the connection.

## Exceptions

All exceptions extend `DatabaseException`, which extends PHP's base `Exception`:

```php
use Sodaho\PdoWrapper\Exception\DatabaseException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;

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
}

try {
    $db->transaction(fn($db) => $db->insert('users', ['name' => 'John']));
} catch (CommitHookException $e) {
    // Committed, but a transaction.commit hook failed: do not retry
    $hookError = $e->getPrevious();
} catch (TransactionException $e) {
    // Begin or commit failed; a failed commit may or may not have taken effect
}
// An exception thrown by the callback itself is re-thrown unchanged after the rollback.
```

`CommitHookException` means the data is committed; a hook failed or the connection state could not be verified or cleaned up after a hook. It extends `DatabaseException`, not `TransactionException`: a broad `catch (DatabaseException)` also sees committed data, so catch `CommitHookException` first where that matters.

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

## Security

This library protects against SQL injection through:

- **Prepared statements** for all values (WHERE, INSERT, UPDATE) - the one exception is a `Database::raw()` expression given as a value, which is inlined by design
- **Identifier quoting** for all column and table names
- **Operator whitelist** validation (only `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IS`, `IS NOT`)
- **Connection values that cannot redirect the connection**: `host`, `database` and (MySQL) `charset` are rejected with a `ConnectionException` before connecting if they contain a `;` (PDO's DSN separator) or NUL; PostgreSQL values are additionally quoted the libpq way, because libpq would otherwise treat a space inside a value (`app host=evil`) as the start of another parameter

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

**Security Note:** Never pass user input to `Database::raw()`. Raw expressions bypass all identifier quoting, and as values in `insert()`, `update()`, `where()` or `having()` they are inlined instead of bound (see [Timestamps and Raw Values](#timestamps-and-raw-values)).

### User Input in Column Names

Column names are safely quoted against SQL injection, but you should still validate user input to provide meaningful error messages instead of database errors:

```php
// ✅ RECOMMENDED - Whitelist for better error handling
$allowedColumns = ['id', 'name', 'email', 'created_at'];
$column = $_GET['column'];

if (!in_array($column, $allowedColumns, true)) {
    throw new InvalidArgumentException('Invalid column');
}

$db->table('users')->orderBy($column)->get();
```

This applies to `select()`, `orderBy()`, `groupBy()`, and `join()`.

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

- **LIMIT/ORDER BY/JOIN in update/delete** - `offset()`, `join()` (also `leftJoin()`/`rightJoin()`), `groupBy()` and `having()` are not supported with `update()` or `delete()`, nor are `limit()` and `orderBy()` with `update()`: they are not part of the generated statement, and ignoring them could silently change the affected rows. The QueryBuilder throws an exception if you try, also for combinations that happen to be row-neutral (such as `groupBy()` on the primary key). The one exception is `delete()->orderBy()->limit(n)` on MySQL/MariaDB (see above). On PostgreSQL and SQLite use a subquery (MySQL and MariaDB reject `LIMIT` inside `IN (...)`, MySQL also a subquery on the target table - there the builder form above is the way):
  ```php
  // Delete the 10 oldest logs - PostgreSQL and SQLite
  $db->execute(
      'DELETE FROM logs WHERE id IN (SELECT id FROM logs ORDER BY created_at ASC LIMIT 10)'
  );
  ```
  `select()`, `distinct()` and a row lock on an `update()`/`delete()` have no meaning there and are ignored (the statement takes its own row locks), so a builder locked for a `first()` can be reused for the update.

- **NULL in where()** - `where('column', null)` throws an exception because `column = NULL` is always false in SQL. Use `whereNull()` or `whereNotNull()` instead.

- **PostgreSQL primary key convention** - `insert()` assumes the primary key column is named `id` and reads it from the `{table}_id_seq` sequence: for a table without that sequence it returns 0, and for a row inserted with an explicit `id` it returns 0 or, after an earlier sequence-based insert on the same connection, that earlier value. For custom PK names or explicit ids, use a raw query with `RETURNING`:
  ```php
  $stmt = $db->query('INSERT INTO users (name) VALUES (?) RETURNING user_id', ['John']);
  $userId = $stmt->fetch()['user_id'];
  ```

These limitations keep the QueryBuilder simple and predictable. For complex queries, use the `query()` method with raw SQL - prepared statements still protect against SQL injection.

## Requirements

- PHP 8.2+
- PDO extension
- Database-specific PDO driver (pdo_mysql, pdo_pgsql, pdo_sqlite)

## Testing

```bash
# Install dependencies
composer install

# Run SQLite tests only (no Docker needed)
./vendor/bin/phpunit --exclude-group mysql,postgres

# Run full test suite (requires Docker)
docker-compose up -d
./vendor/bin/phpunit
docker-compose down
```

## Acknowledgments

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).

## License

MIT
