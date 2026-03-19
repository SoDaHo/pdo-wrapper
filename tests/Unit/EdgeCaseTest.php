<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * Edge case tests for bugs found by code review.
 * These tests verify specific bug fixes work correctly.
 */
class EdgeCaseTest extends TestCase
{
    // =========================================================================
    // SCHEMA QUOTING BUG FIX TEST
    // Bug: "public.users" was quoted as `"public.users"` instead of `"public"."users"`
    // =========================================================================

    public function testSchemaTableQuotingInSqlite(): void
    {
        $db = Database::sqlite(':memory:');

        // Test that schema.table format is handled correctly in queries
        // SQLite doesn't have schemas like PostgreSQL, but the quoting should still work
        $db->execute('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');
        $db->insert('test_table', ['name' => 'Test']);

        // The QueryBuilder should properly quote table.column in select
        $result = $db->table('test_table')
            ->select(['test_table.id', 'test_table.name'])
            ->first();

        $this->assertNotNull($result);
        $this->assertSame('Test', $result['name']);
    }

    public function testSchemaTableQuotingInQueryBuilder(): void
    {
        $db = Database::sqlite(':memory:');

        // Create tables for join test
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');

        $userId = $db->insert('users', ['name' => 'John']);
        $db->insert('posts', ['user_id' => $userId, 'title' => 'First Post']);

        // Join with qualified column names
        $result = $db->table('posts')
            ->select(['posts.title', 'users.name as author'])
            ->join('users', 'users.id', '=', 'posts.user_id')
            ->first();

        $this->assertSame('First Post', $result['title']);
        $this->assertSame('John', $result['author']);
    }

    // =========================================================================
    // FINDONE EMPTY WHERE BUG FIX TEST
    // Bug: findOne([]) would generate invalid SQL "SELECT * FROM table WHERE LIMIT 1"
    // =========================================================================

    public function testFindOneWithEmptyWhereThrowsException(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query failed');

        $db->findOne('users', []);
    }

    public function testFindOneWithValidWhereWorks(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->insert('users', ['name' => 'Alice']);

        $result = $db->findOne('users', ['name' => 'Alice']);

        $this->assertNotNull($result);
        $this->assertSame('Alice', $result['name']);
    }

    public function testFindAllWithEmptyWhereReturnsAllRows(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->insert('users', ['name' => 'Alice']);
        $db->insert('users', ['name' => 'Bob']);

        // findAll without WHERE should return all rows
        $results = $db->findAll('users');

        $this->assertCount(2, $results);
    }

    // =========================================================================
    // PARAMETER ORDER BUG FIX TEST
    // Bug: having() before where() caused parameters to be bound in wrong order
    // =========================================================================

    public function testParameterOrderWithHavingBeforeWhere(): void
    {
        $db = Database::sqlite(':memory:');

        // Build query with having() called before where()
        [$sql, $params] = $db->table('posts')
            ->select(['user_id', Database::raw('COUNT(*) as cnt')])
            ->groupBy('user_id')
            ->having(Database::raw('COUNT(*)'), '>=', 5)       // Called first, value=5
            ->where('status', 'published')      // Called second, value='published'
            ->toSql();

        // Params should be in SQL order: WHERE first, then HAVING
        $this->assertSame('published', $params[0], 'WHERE param should be first');
        $this->assertSame(5, $params[1], 'HAVING param should be second');

        // SQL should have WHERE before HAVING
        $wherePos = strpos($sql, 'WHERE');
        $havingPos = strpos($sql, 'HAVING');
        $this->assertLessThan($havingPos, $wherePos, 'WHERE must come before HAVING in SQL');
    }

    public function testParameterOrderWithMultipleWhereAndHaving(): void
    {
        $db = Database::sqlite(':memory:');

        // Complex query with multiple conditions
        [$sql, $params] = $db->table('posts')
            ->select(['user_id', Database::raw('COUNT(*) as cnt'), Database::raw('SUM(views) as total_views')])
            ->having(Database::raw('COUNT(*)'), '>', 3)          // Having condition 1
            ->where('status', 'published')        // Where condition 1
            ->where('type', 'article')            // Where condition 2
            ->groupBy('user_id')
            ->having(Database::raw('SUM(views)'), '>=', 100)     // Having condition 2
            ->toSql();

        // Params should be: WHERE1, WHERE2, HAVING1, HAVING2
        $this->assertSame('published', $params[0], 'First WHERE param');
        $this->assertSame('article', $params[1], 'Second WHERE param');
        $this->assertSame(3, $params[2], 'First HAVING param');
        $this->assertSame(100, $params[3], 'Second HAVING param');
    }

    // =========================================================================
    // ADDITIONAL EDGE CASES
    // =========================================================================

    public function testSelectWithWildcardInArray(): void
    {
        $db = Database::sqlite(':memory:');

        // Test that wildcard in array is not quoted
        [$sql, ] = $db->table('users')
            ->select(['id', '*'])
            ->toSql();

        // Wildcard should not be quoted as "*"
        $this->assertStringContainsString('"id", *', $sql);
        $this->assertStringNotContainsString('"*"', $sql);
    }

    public function testQuoteIdentifierWithSpecialCharacters(): void
    {
        $db = Database::sqlite(':memory:');

        // Create table with special column name containing quote
        $db->execute('CREATE TABLE "test" (id INTEGER PRIMARY KEY, "my""column" TEXT)');
        $db->insert('test', ['my"column' => 'value']);

        $result = $db->findOne('test', ['id' => 1]);
        $this->assertSame('value', $result['my"column']);
    }

    // =========================================================================
    // NULL IN CRUD WHERE BUG FIX TEST
    // Bug: buildWhereClause() generated "column = ?" with null, which is always false in SQL
    // =========================================================================

    public function testCrudUpdateWithNullWhereThrowsException(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT)');
        $db->insert('users', ['name' => 'Alice']);

        $this->expectException(QueryException::class);

        $db->update('users', ['name' => 'New'], ['deleted_at' => null]);
    }

    public function testCrudDeleteWithNullWhereThrowsException(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT)');
        $db->insert('users', ['name' => 'Alice']);

        $this->expectException(QueryException::class);

        $db->delete('users', ['deleted_at' => null]);
    }

    public function testCrudFindOneWithNullWhereThrowsException(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, deleted_at TEXT)');

        $this->expectException(QueryException::class);

        $db->findOne('users', ['deleted_at' => null]);
    }

    public function testCrudFindAllWithNullWhereThrowsException(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, deleted_at TEXT)');

        $this->expectException(QueryException::class);

        $db->findAll('users', ['deleted_at' => null]);
    }

    // =========================================================================
    // DIRECT QUERY BUILDER SCHEMA QUOTING TESTS
    // These test the QueryBuilder's quoteIdentifier directly
    // =========================================================================

    public function testQueryBuilderHandlesDottedIdentifiers(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT, user_id INTEGER)');
        $db->insert('orders', ['status' => 'pending', 'user_id' => 1]);

        // Query with table.column syntax
        $result = $db->table('orders')
            ->select(['orders.id', 'orders.status'])
            ->where('orders.status', 'pending')
            ->first();

        $this->assertNotNull($result);
        $this->assertSame('pending', $result['status']);
    }

    public function testQueryBuilderToSqlWithDottedColumns(): void
    {
        $db = Database::sqlite(':memory:');

        [$sql, $params] = $db->table('users')
            ->select(['users.id', 'users.name'])
            ->where('users.active', 1)
            ->toSql();

        // Verify the SQL contains properly quoted identifiers
        $this->assertStringContainsString('"users"."id"', $sql);
        $this->assertStringContainsString('"users"."name"', $sql);
        $this->assertStringContainsString('"users"."active"', $sql);
    }

    // =========================================================================
    // INSERT LASTINSERTID BUG FIX TEST
    // Bug: insert() returned false when lastInsertId() failed instead of throwing
    // =========================================================================

    public function testInsertThrowsExceptionWhenLastInsertIdReturnsFalse(): void
    {
        // Create a driver that returns false from lastInsertId()
        $driver = new class () extends SqliteDriver {
            public function __construct()
            {
                parent::__construct(':memory:');
            }

            public function lastInsertId(?string $name = null): string|false
            {
                return false;
            }
        };

        $driver->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Insert failed');

        $driver->insert('users', ['name' => 'Test']);
    }

    public function testInsertThrowsExceptionWithDebugMessageContainingSqlAndParams(): void
    {
        // Create a driver that returns false from lastInsertId()
        $driver = new class () extends SqliteDriver {
            public function __construct()
            {
                parent::__construct(':memory:');
            }

            public function lastInsertId(?string $name = null): string|false
            {
                return false;
            }
        };

        $driver->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        try {
            $driver->insert('users', ['name' => 'Test']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Failed to retrieve last insert ID', $e->getDebugMessage());
            $this->assertStringContainsString('SQL:', $e->getDebugMessage());
            $this->assertStringContainsString('Params:', $e->getDebugMessage());
        }
    }

    // =========================================================================
    // AGGREGATE ORDERBY BUG FIX TEST
    // Bug: count()/sum()/etc. included ORDER BY clause, causing invalid SQL
    // on PostgreSQL and unnecessary performance overhead
    // =========================================================================

    public function testAggregatesIgnoreOrderBy(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)');
        $db->insert('users', ['name' => 'Alice', 'age' => 30]);
        $db->insert('users', ['name' => 'Bob', 'age' => 25]);

        // Build query with orderBy, then call count()
        $builder = $db->table('users')->orderBy('name', 'DESC');

        // Get the SQL that would be generated for count
        // count() should NOT include ORDER BY
        $count = $builder->count();

        $this->assertSame(2, $count);

        // Verify orderBy is preserved for subsequent get() calls
        $users = $builder->get();
        $this->assertSame('Bob', $users[0]['name']); // DESC order
    }

    public function testAggregatesSqlDoesNotContainOrderBy(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        // We can't directly test the SQL generated by aggregate(),
        // but we can verify the pattern works correctly with PostgreSQL-like strictness
        $builder = $db->table('users')
            ->where('name', 'test')
            ->orderBy('name')
            ->limit(10)
            ->offset(5);

        // All these should work without ORDER BY in the generated SQL
        $this->assertSame(0, $builder->count());
        $this->assertNull($builder->sum('name'));
        $this->assertNull($builder->avg('name'));
        $this->assertNull($builder->min('name'));
        $this->assertNull($builder->max('name'));

        // Original builder state should be preserved
        [$sql, ] = $builder->toSql();
        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 5', $sql);
    }

    // =========================================================================
    // LIMIT/ORDERBY IN UPDATE/DELETE BUG FIX TEST
    // Bug: limit() and orderBy() were silently ignored in update()/delete(),
    // causing unintended data loss (e.g., deleting all rows instead of a subset)
    // =========================================================================

    public function testUpdateWithLimitThrowsException(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE logs (id INTEGER PRIMARY KEY, level TEXT)');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Update failed');

        $db->table('logs')
            ->where('level', 'info')
            ->limit(10)
            ->update(['level' => 'debug']);
    }

    public function testUpdateWithOrderByThrowsException(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE logs (id INTEGER PRIMARY KEY, level TEXT)');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Update failed');

        $db->table('logs')
            ->where('level', 'info')
            ->orderBy('id', 'ASC')
            ->update(['level' => 'debug']);
    }

    public function testUpdateWithOffsetThrowsException(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE logs (id INTEGER PRIMARY KEY, level TEXT)');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Update failed');

        $db->table('logs')
            ->where('level', 'info')
            ->offset(5)
            ->update(['level' => 'debug']);
    }

    public function testDeleteWithLimitThrowsException(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE logs (id INTEGER PRIMARY KEY, level TEXT)');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Delete failed');

        $db->table('logs')
            ->where('level', 'info')
            ->limit(10)
            ->delete();
    }

    public function testDeleteWithOrderByThrowsException(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE logs (id INTEGER PRIMARY KEY, level TEXT)');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Delete failed');

        $db->table('logs')
            ->where('level', 'info')
            ->orderBy('id', 'ASC')
            ->delete();
    }

    public function testDeleteWithLimitAndOrderByThrowsExceptionListingAll(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE logs (id INTEGER PRIMARY KEY, level TEXT)');

        try {
            $db->table('logs')
                ->where('level', 'info')
                ->orderBy('id')
                ->limit(10)
                ->delete();
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $debug = $e->getDebugMessage() ?? '';
            $this->assertStringContainsString('limit()', $debug);
            $this->assertStringContainsString('orderBy()', $debug);
        }
    }

    public function testUpdateWithoutLimitOrOrderByStillWorks(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE logs (id INTEGER PRIMARY KEY, level TEXT)');
        $db->insert('logs', ['level' => 'info']);
        $db->insert('logs', ['level' => 'info']);

        $affected = $db->table('logs')
            ->where('level', 'info')
            ->update(['level' => 'debug']);

        $this->assertSame(2, $affected);
    }

    public function testDeleteWithoutLimitOrOrderByStillWorks(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE logs (id INTEGER PRIMARY KEY, level TEXT)');
        $db->insert('logs', ['level' => 'info']);

        $affected = $db->table('logs')
            ->where('level', 'info')
            ->delete();

        $this->assertSame(1, $affected);
    }

    // =========================================================================
    // WHERE NULL BUG FIX TEST
    // Bug: where('column', null) generated "column = NULL" which is always false
    // in SQL. Users must use whereNull()/whereNotNull() instead.
    // =========================================================================

    public function testWhereTwoArgNullThrowsException(): void
    {
        $db = Database::sqlite(':memory:');

        $this->expectException(QueryException::class);

        $db->table('users')->where('status', null);
    }

    public function testWhereThreeArgNullThrowsException(): void
    {
        $db = Database::sqlite(':memory:');

        $this->expectException(QueryException::class);

        $db->table('users')->where('status', '=', null);
    }

    public function testWhereArraySyntaxNullThrowsException(): void
    {
        $db = Database::sqlite(':memory:');

        $this->expectException(QueryException::class);

        $db->table('users')->where(['status' => null]);
    }

    public function testWhereNullExceptionSuggestsWhereNull(): void
    {
        $db = Database::sqlite(':memory:');

        try {
            $db->table('users')->where('status', null);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $debug = $e->getDebugMessage() ?? '';
            $this->assertStringContainsString('whereNull', $debug);
            $this->assertStringContainsString('status', $debug);
        }
    }

    public function testWhereThreeArgNullExceptionSuggestsWhereNull(): void
    {
        $db = Database::sqlite(':memory:');

        try {
            $db->table('users')->where('deleted_at', '=', null);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $debug = $e->getDebugMessage() ?? '';
            $this->assertStringContainsString('whereNull', $debug);
            $this->assertStringContainsString('deleted_at', $debug);
        }
    }

    public function testWhereIsNullThrowsExceptionSuggestsWhereNull(): void
    {
        $db = Database::sqlite(':memory:');

        try {
            $db->table('users')->where('deleted_at', 'IS', null);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $debug = $e->getDebugMessage() ?? '';
            $this->assertStringContainsString('whereNull', $debug);
        }
    }

    public function testWhereIsNotNullThrowsExceptionSuggestsWhereNotNull(): void
    {
        $db = Database::sqlite(':memory:');

        try {
            $db->table('users')->where('deleted_at', 'IS NOT', null);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $debug = $e->getDebugMessage() ?? '';
            $this->assertStringContainsString('whereNotNull', $debug);
        }
    }

    public function testWhereWithNonNullValuesStillWorks(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT)');
        $db->insert('users', ['status' => 'active']);

        $result = $db->table('users')->where('status', 'active')->first();

        $this->assertNotNull($result);
        $this->assertSame('active', $result['status']);
    }

    // =========================================================================
    // ESCAPE LIKE TESTS
    // =========================================================================

    public function testEscapeLikeEscapesPercent(): void
    {
        $this->assertSame('100\\%', Database::escapeLike('100%'));
    }

    public function testEscapeLikeEscapesUnderscore(): void
    {
        $this->assertSame('user\\_name', Database::escapeLike('user_name'));
    }

    public function testEscapeLikeEscapesBackslash(): void
    {
        $this->assertSame('path\\\\to', Database::escapeLike('path\\to'));
    }

    public function testEscapeLikeEscapesAllSpecialChars(): void
    {
        $this->assertSame('100\\% of\\_all\\\\data', Database::escapeLike('100% of_all\\data'));
    }

    public function testEscapeLikeLeavesNormalStringsUnchanged(): void
    {
        $this->assertSame('hello world', Database::escapeLike('hello world'));
    }

    public function testEscapeLikeWithEmptyString(): void
    {
        $this->assertSame('', Database::escapeLike(''));
    }

    public function testEscapeLikeEndToEndWithWhereLike(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
        $db->insert('products', ['name' => 'Rabatt: 100%']);
        $db->insert('products', ['name' => 'Rabatt: 1000 Euro']);

        $search = Database::escapeLike('100%');
        $results = $db->table('products')
            ->whereLike('name', '%' . $search . '%')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('Rabatt: 100%', $results[0]['name']);
    }
}
