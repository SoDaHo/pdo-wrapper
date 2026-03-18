# PDO Wrapper

A lightweight PHP PDO wrapper with fluent Query Builder, supporting MySQL/MariaDB, PostgreSQL, and SQLite.

## Why This Library?

- **No dependencies** -- just PDO, which ships with PHP.
- **Readable codebase** -- the entire source fits in a handful of files. You can read and understand all of it in minutes.
- **Multi-database** -- MySQL, MariaDB, PostgreSQL, SQLite behind one API, with driver-specific details handled internally.
- **Safe defaults** -- prepared statements, identifier quoting, operator whitelist. Hard to accidentally write an injection vulnerability.
- **Intentionally limited** -- no OR conditions, no subqueries, no UNION in the query builder. When you need complex SQL, you write SQL. The builder handles the straightforward queries.

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

### Environment Variables

All drivers support configuration via environment variables:

```php
// MySQL/PostgreSQL read from:
// DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_PORT

// SQLite reads from:
// DB_SQLITE_PATH
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
```

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
```

### Debug Query

```php
[$sql, $params] = $db->table('users')
    ->where('active', 1)
    ->orderBy('name')
    ->toSql();

// $sql = 'SELECT * FROM "users" WHERE "active" = ? ORDER BY "name" ASC'
// $params = [1]
```

## Transactions

```php
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
} catch (Exception $e) {
    $db->rollback();
    throw $e;
}
```

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
$db->on('transaction.begin', fn() => echo "Transaction started\n");
$db->on('transaction.commit', fn() => echo "Transaction committed\n");
$db->on('transaction.rollback', fn() => echo "Transaction rolled back\n");
```

## Exceptions

All exceptions extend `DatabaseException`, which extends PHP's base `Exception`:

```php
use Sodaho\PdoWrapper\Exception\DatabaseException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;

// Catch all pdo-wrapper exceptions
try {
    $db->query('...');
} catch (DatabaseException $e) {
    // Catches ConnectionException, QueryException, TransactionException
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
    $db->transaction(fn() => throw new Exception('oops'));
} catch (TransactionException $e) {
    // Transaction failed
}
```

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

- **Prepared statements** for all values (WHERE, INSERT, UPDATE)
- **Identifier quoting** for all column and table names
- **Operator whitelist** validation (only `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IS`, `IS NOT`)

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
    ->select(['id', 'name', 'email'])  // Becomes: "id", "name", "email"
    ->get();
```

**Security Note:** Never pass user input to `Database::raw()`. Raw expressions bypass all identifier quoting.

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

- **OR conditions** - All `where()` calls are joined with AND. For OR conditions, use raw queries:
  ```php
  $db->query('SELECT * FROM users WHERE role = ? OR role = ?', ['admin', 'moderator']);
  ```

- **Nested WHERE groups** - Complex conditions like `(A AND B) OR (C AND D)` require raw queries.

- **Subqueries** - Use raw queries for subqueries in SELECT, WHERE, or FROM clauses.

- **UNION** - Combine queries manually or use raw SQL.

- **LIMIT/ORDER BY in update/delete** - `limit()`, `offset()`, and `orderBy()` are not supported with `update()` or `delete()` (not portable across databases). The QueryBuilder throws an exception if you try. Use a subquery instead:
  ```php
  // Delete the 10 oldest logs (works on all databases)
  $db->execute(
      'DELETE FROM logs WHERE id IN (SELECT id FROM logs ORDER BY created_at ASC LIMIT 10)'
  );
  ```

- **NULL in where()** - `where('column', null)` throws an exception because `column = NULL` is always false in SQL. Use `whereNull()` or `whereNotNull()` instead.

- **PostgreSQL primary key convention** - `insert()` assumes the primary key column is named `id`. For custom PK names, use raw query with `RETURNING`:
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
