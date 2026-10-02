<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\UniqueViolationException;

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
        $this->assertStringContainsString('`id`, *', $sql);
        $this->assertStringNotContainsString('`*`', $sql);
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
        $this->assertStringContainsString('`users`.`id`', $sql);
        $this->assertStringContainsString('`users`.`name`', $sql);
        $this->assertStringContainsString('`users`.`active`', $sql);
    }

    // =========================================================================
    // INSERT LASTINSERTID BUG FIX TEST
    // Bug: insert() returned false when lastInsertId() failed instead of throwing
    // =========================================================================

    public function testInsertThrowsExceptionWhenLastInsertIdReturnsFalse(): void
    {
        // Create a driver that returns false from lastInsertId()
        $driver = new class (':memory:') extends SqliteDriver {
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
        $driver = new class (':memory:') extends SqliteDriver {
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

    /**
     * Regression test: update()/delete() never render joins, so a delete narrowed down by a join
     * hit every matching row of the base table. join() is now rejected like limit()/orderBy().
     */
    public function testDeleteWithJoinThrowsExceptionAndDeletesNothing(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT)');
        $db->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT)');
        foreach (['active', 'active', 'inactive'] as $status) {
            $db->insert('users', ['status' => $status]);
        }
        $db->insert('orders', ['user_id' => 1, 'status' => 'active']);

        try {
            $db->table('users')
                ->join('orders', 'users.id', '=', 'orders.user_id')
                ->where('status', 'active')
                ->delete();
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('Delete failed', $e->getMessage());
            $this->assertStringContainsString('join()', $e->getDebugMessage() ?? '');
        }

        $this->assertSame(3, $db->table('users')->count());
    }

    public function testUpdateWithGroupByAndHavingThrowsExceptionListingBoth(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE logs (id INTEGER PRIMARY KEY, level TEXT)');

        try {
            $db->table('logs')
                ->where('level', 'info')
                ->groupBy('level')
                ->having(Database::raw('COUNT(*)'), '>', 1)
                ->update(['level' => 'debug']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('Update failed', $e->getMessage());
            $debug = $e->getDebugMessage() ?? '';
            $this->assertStringContainsString('groupBy()', $debug);
            $this->assertStringContainsString('having()', $debug);
        }
    }

    /**
     * Regression test: where('country', 'IS') threw "null value", because the value spelled an operator.
     * With two arguments the second one is always the value.
     */
    public function testWhereWithTwoArgumentsTreatsAnOperatorNamedValueAsValue(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, country TEXT)');
        $db->insert('users', ['country' => 'IS']);
        $db->insert('users', ['country' => 'DE']);

        [$sql, $params] = $db->table('users')->where('country', 'IS')->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `country` = ?', $sql);
        $this->assertSame(['IS'], $params);
        $this->assertCount(1, $db->table('users')->where('country', 'IS')->get());
        $this->assertCount(0, $db->table('users')->where('country', 'LIKE')->get());
    }

    public function testWhereWithThreeArgumentsValidatesOperatorAndRejectsNull(): void
    {
        $db = Database::sqlite(':memory:');

        try {
            $db->table('users')->where('name', '=', null);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertStringContainsString('whereNull', $e->getDebugMessage() ?? '');
        }

        try {
            $db->table('users')->where('name', 'A', null);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Invalid operator "A"', $e->getDebugMessage() ?? '');
        }
    }

    /**
     * Regression test: select(['users.*']) produced "users"."*" (no such column).
     */
    public function testSelectTableWildcardIsNotQuoted(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total INTEGER)');
        $db->insert('users', ['name' => 'Max']);
        $db->insert('orders', ['user_id' => 1, 'total' => 5]);

        $query = $db->table('users')->select(['users.*', 'orders.total'])->join('orders', 'users.id', '=', 'orders.user_id');
        [$sql] = $query->toSql();

        $this->assertSame('SELECT `users`.*, `orders`.`total` FROM `users` INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id`', $sql);
        $this->assertSame([['id' => 1, 'name' => 'Max', 'total' => 5]], $query->get());
    }

    public function testBuilderUpdateWithEmptyDataThrowsClearException(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        try {
            $db->table('users')->where('id', 1)->update([]);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('Update failed', $e->getMessage());
            $this->assertSame('Cannot update with empty data', $e->getDebugMessage());
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

    /**
     * IS / IS NOT are the null-safe comparison: a value that may be null is compared as it is.
     */
    public function testWhereIsAndIsNotTakeANullValue(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT)');
        $db->insert('users', ['name' => 'kept', 'deleted_at' => null]);
        $db->insert('users', ['name' => 'gone', 'deleted_at' => '2026-01-01']);

        [$sql, $params] = $db->table('users')->where('deleted_at', 'IS', null)->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS ?', $sql);
        $this->assertSame([null], $params);

        foreach ([[null, 'IS', 'kept'], [null, 'IS NOT', 'gone'], ['2026-01-01', 'is', 'gone'], ['2026-01-01', 'is not', 'kept']] as [$value, $operator, $expected]) {
            $rows = $db->table('users')->where('deleted_at', $operator, $value)->get();
            $this->assertSame([$expected], array_column($rows, 'name'), "{$operator} " . var_export($value, true));
        }
    }

    public function testWhereInWithANullElementThrows(): void
    {
        $db = Database::sqlite(':memory:');

        foreach (['whereIn', 'whereNotIn'] as $method) {
            try {
                $db->table('users')->{$method}('status', ['active', null]);
                $this->fail("{$method}() with a null element must throw: NOT IN with NULL matches no row");
            } catch (QueryException $e) {
                $this->assertSame(
                    sprintf('Cannot use a null element in %s() for column "status". Add whereNull() or whereNotNull() for it.', $method),
                    $e->getDebugMessage()
                );
            }
        }

        [$sql, $params] = $db->table('users')->whereIn('status', ['active', Database::raw('NULL')])->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `status` IN (?, NULL)', $sql, 'a raw element is the caller\'s own SQL');
        $this->assertSame(['active'], $params);
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

    // =========================================================================
    // SILENTLY ACCEPTED INPUT
    // Bug: a negative limit()/offset(), a null in having()/whereBetween() and a list given to
    // where() were accepted and returned wrong rows (or a TypeError) instead of a QueryException
    // =========================================================================

    public function testNegativeLimitAndOffsetThrow(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->insert('users', ['name' => 'A']);
        $db->insert('users', ['name' => 'B']);

        try {
            $db->table('users')->limit(-1);
            $this->fail('limit(-1) must throw: SQLite reads it as "no limit"');
        } catch (QueryException $e) {
            $this->assertSame('limit() needs 0 or more, got -1', $e->getDebugMessage());
        }

        try {
            $db->table('users')->limit(1)->offset(-5);
            $this->fail('offset(-5) must throw: SQLite reads it as 0');
        } catch (QueryException $e) {
            $this->assertSame('offset() needs 0 or more, got -5', $e->getDebugMessage());
        }

        $this->assertSame([], $db->table('users')->limit(0)->get());
        $this->assertCount(2, $db->table('users')->limit(2)->offset(0)->get());
    }

    public function testWhereBetweenWithANullBoundThrows(): void
    {
        $db = Database::sqlite(':memory:');

        foreach ([[null, 5], [1, null], ['min' => 1, 'max' => null]] as $values) {
            foreach (['whereBetween', 'whereNotBetween'] as $method) {
                try {
                    $db->table('users')->{$method}('age', $values);
                    $this->fail("{$method}() with a null bound must throw: BETWEEN with NULL matches no row");
                } catch (QueryException $e) {
                    $this->assertSame(
                        sprintf('Cannot use a null bound in %s() for column "age". Use where() with a comparison operator for an open range.', $method),
                        $e->getDebugMessage()
                    );
                }
            }
        }

        [$sql, $params] = $db->table('users')->whereBetween('age', [0, Database::raw('18 + 0')])->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `age` BETWEEN ? AND 18 + 0', $sql);
        $this->assertSame([0], $params);
    }

    public function testHavingWithNullThrowsExceptForTheNullSafeOperators(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, team TEXT)');
        $db->insert('users', ['team' => 'a']);
        $db->insert('users', ['team' => null]);

        foreach (['=', '!=', '>', 'LIKE'] as $operator) {
            try {
                $db->table('users')->groupBy('team')->having('team', $operator, null);
                $this->fail("having() with null and {$operator} must throw: the comparison is never true");
            } catch (QueryException $e) {
                $this->assertSame(
                    sprintf('Cannot use a null value in having() (operator "%s", column "team"). Use the operator IS or IS NOT instead.', $operator),
                    $e->getDebugMessage()
                );
            }
        }

        try {
            $db->table('users')->having(Database::raw('COUNT(*)'), '>', null);
            $this->fail('having() with null must throw for a raw column too');
        } catch (QueryException $e) {
            $this->assertStringContainsString('column "COUNT(*)"', (string) $e->getDebugMessage());
        }

        $rows = $db->table('users')->select('team')->groupBy('team')->having('team', 'IS', null)->get();
        $this->assertSame([['team' => null]], $rows);
        $rows = $db->table('users')->select('team')->groupBy('team')->having('team', 'is not', null)->get();
        $this->assertSame([['team' => 'a']], $rows);
    }

    public function testWhereArrayWithNumericKeysThrows(): void
    {
        $db = Database::sqlite(':memory:');

        try {
            $db->table('users')->where(['active', 1]);
            $this->fail('A list given to where() must throw');
        } catch (QueryException $e) {
            $this->assertSame(
                'where() with an array needs column names as keys, got the numeric key 0. Use where(\'column\', $value) instead.',
                $e->getDebugMessage()
            );
        }

        // PHP turns the key '2024' into the integer 2024: a numeric column name needs the two-argument form
        try {
            $db->table('users')->where(['name' => 'x', '2024' => 1]);
            $this->fail('A numeric key in where() must throw');
        } catch (QueryException $e) {
            $this->assertStringContainsString('got the numeric key 2024', (string) $e->getDebugMessage());
        }

        [$sql] = $db->table('stats')->where('2024', 1)->toSql();
        $this->assertSame('SELECT * FROM `stats` WHERE `2024` = ?', $sql);
    }

    // =========================================================================
    // AGGREGATE RESULT KEY
    // Bug: aggregates read the key "aggregate"; with PDO::ATTR_CASE it is "AGGREGATE" and
    // count() returned 0, sum()/max() null
    // =========================================================================

    public function testAggregatesDoNotDependOnTheResultKeyCase(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, amount INTEGER, status TEXT)');
        $db->insert('orders', ['amount' => 10, 'status' => 'a']);
        $db->insert('orders', ['amount' => 30, 'status' => 'b']);
        $db->getPdo()->setAttribute(\PDO::ATTR_CASE, \PDO::CASE_UPPER);

        $this->assertSame(2, $db->table('orders')->count());
        $this->assertSame(40, $db->table('orders')->sum('amount'));
        $this->assertSame(30, $db->table('orders')->max('amount'));
        $this->assertSame(2, $db->table('orders')->groupBy('status')->count());
        $this->assertSame(2, $db->table('orders')->select('status')->distinct()->count());
        $this->assertNull($db->table('orders')->where('amount', '>', 100)->max('amount'));
    }

    // =========================================================================
    // INSERT ID AND THE QUERY HOOK
    // Bug: insert() read lastInsertId() after the 'query' hook; a listener that inserted
    // replaced the id
    // =========================================================================

    public function testAFailingInsertIdReadStillFiresTheQueryHookFirst(): void
    {
        $driver = new class (':memory:') extends SqliteDriver {
            public function lastInsertId(?string $name = null): string|false
            {
                throw new QueryException(message: 'Failed to get last insert ID');
            }
        };
        $driver->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $seen = [];
        $driver->on('query', function (array $data) use (&$seen): void {
            $seen[] = $data['sql'];
        });

        try {
            $driver->insert('users', ['name' => 'Test']);
            $this->fail('The failed id read must reach the caller');
        } catch (QueryException $e) {
            $this->assertSame('Failed to get last insert ID', $e->getMessage());
        }

        $this->assertSame(['INSERT INTO `users` (`name`) VALUES (?)'], $seen, 'the statement ran, so its hook fired');
        $this->assertSame(1, $driver->table('users')->count());
    }

    public function testAnInsertIdReadThatFailsOnceIsNotRetried(): void
    {
        $driver = new class (':memory:') extends SqliteDriver {
            public int $reads = 0;

            public function lastInsertId(?string $name = null): string|false
            {
                if (++$this->reads === 1) {
                    throw new QueryException(message: 'Failed to get last insert ID');
                }

                return parent::lastInsertId($name);
            }
        };
        $driver->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        try {
            $driver->insert('users', ['name' => 'Test']);
            $this->fail('The failed id read must reach the caller');
        } catch (QueryException $e) {
            $this->assertSame('Failed to get last insert ID', $e->getMessage());
        }
        $this->assertSame(1, $driver->reads);
    }

    public function testInsertReadsTheIdItselfWhenAnOverriddenQueryBypassesTheDriver(): void
    {
        $driver = new class (':memory:') extends SqliteDriver {
            public int $reads = 0;

            public function query(string $sql, array $params = []): \PDOStatement
            {
                if (!str_starts_with($sql, 'INSERT')) {
                    return parent::query($sql, $params);
                }
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array_values($params));

                return $stmt;
            }

            public function lastInsertId(?string $name = null): string|false
            {
                $this->reads++;

                return parent::lastInsertId($name);
            }
        };
        $driver->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        $this->assertSame(1, $driver->insert('users', ['name' => 'A']));
        $this->assertSame(2, $driver->insert('users', ['name' => 'B']));
        $this->assertSame(2, $driver->reads);

        // The step set for a bypassed insert does not run with a later statement
        $this->assertSame(2, $driver->table('users')->count());
        $this->assertSame(2, $driver->reads);
    }

    public function testDebugMessageSurvivesBinaryParameters(): void
    {
        $db = Database::sqlite(':memory:');

        try {
            $db->query('SELECT * FROM missing WHERE a = ? AND b = ?', ["\xFF\xFE", 'ok']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertStringContainsString('| Params: ["\ufffd\ufffd","ok"]', (string) $e->getDebugMessage());
        }
    }

    public function testInsertReturnsItsIdWhenAnOverriddenQuerySendsAStatementAhead(): void
    {
        $driver = new class (':memory:') extends SqliteDriver {
            public int $reads = 0;

            public function query(string $sql, array $params = []): \PDOStatement
            {
                parent::query('SELECT ?', ['session setting']);

                return parent::query($sql, $params);
            }

            public function lastInsertId(?string $name = null): string|false
            {
                $this->reads++;

                return parent::lastInsertId($name);
            }
        };
        $driver->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $driver->execute('CREATE TABLE audit (id INTEGER PRIMARY KEY, note TEXT)');
        for ($i = 0; $i < 5; $i++) {
            $driver->execute('INSERT INTO audit (note) VALUES (?)', ['filler']);
        }

        $this->assertSame(1, $driver->insert('users', ['name' => 'A']));
        $this->assertSame(1, $driver->reads, 'read once, after the INSERT - not after the statement sent ahead');

        // A listener that inserts when the statement sent ahead is told, and again when the INSERT is
        // told: the outer step survives the first and has run before the second
        $busy = false;
        $driver->on('query', static function (array $data) use ($driver, &$busy): void {
            if (!$busy && ($data['params'] === ['session setting'] || str_contains($data['sql'], '`users`'))) {
                $busy = true;
                $driver->insert('audit', ['note' => 'from the listener']);
                $busy = false;
            }
        });
        $this->assertSame(2, $driver->insert('users', ['name' => 'B']));
        // counted on raw PDO: a query through the driver would trigger the listener once more
        $this->assertSame(7, (int) $driver->getPdo()->query('SELECT COUNT(*) FROM audit')->fetchColumn());
    }

    /**
     * The id step is taken by the statement it was set for and by nothing else: a listener that
     * runs the very same statement - told about the insert itself, or about a statement the
     * driver sent ahead of it - does not take the step or make it run twice.
     */
    public function testAListenerRepeatingTheSameInsertDoesNotReplaceTheId(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $repeated = false;
        $db->on('query', static function (array $data) use ($db, &$repeated): void {
            if (!$repeated && str_starts_with($data['sql'], 'INSERT')) {
                $repeated = true;
                $db->execute($data['sql'], $data['params']);
            }
        });

        $this->assertSame(1, $db->insert('notes', ['body' => 'same']));
        $this->assertSame(2, $db->table('notes')->count());

        // A driver that sends a statement ahead, and a listener that mirrors the insert when that one is told
        $driver = new class (':memory:') extends SqliteDriver {
            public int $reads = 0;

            public function query(string $sql, array $params = []): \PDOStatement
            {
                parent::query('SELECT ?', ['session setting']);

                return parent::query($sql, $params);
            }

            public function lastInsertId(?string $name = null): string|false
            {
                $this->reads++;

                return parent::lastInsertId($name);
            }
        };
        $driver->getPdo()->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $driver->getPdo()->exec('CREATE TABLE mirror (id INTEGER PRIMARY KEY, body TEXT)');
        $driver->getPdo()->exec("INSERT INTO mirror (body) VALUES ('filler'), ('filler'), ('filler')");
        $mirrored = false;
        $driver->on('query', static function (array $data) use ($driver, &$mirrored): void {
            if (!$mirrored && $data['params'] === ['session setting']) {
                $mirrored = true;
                $driver->getPdo()->exec("INSERT INTO mirror (body) VALUES ('raw, so that the last insert id moves')");
                // the outer insert's own SQL and parameters, run by the listener before the outer statement
                $driver->execute('INSERT INTO `notes` (`body`) VALUES (?)', ['outer']);
            }
        });

        $this->assertSame(2, $driver->insert('notes', ['body' => 'outer']), 'the listener\'s row is 1, the outer row 2');
        $this->assertSame(1, $driver->reads, 'read once, after the outer INSERT: the listener\'s identical statement did not run the step');
    }

    // =========================================================================
    // WARNING MODE WITH A THROWING ERROR HANDLER
    // Bug: with PDO::ERRMODE_WARNING and an error handler that throws, a failed statement left
    // query() as ErrorException: no 'error' hook, no QueryException, not remembered for commit()
    // =========================================================================

    public function testWarningModeWithAThrowingErrorHandlerIsAFailedQuery(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $db->getPdo()->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);
        $errors = [];
        $db->on('error', static function (array $data) use (&$errors): void {
            $errors[] = $data;
        });
        $noisy = new class () {
            public function __toString(): string
            {
                trigger_error('not a database failure', E_USER_WARNING);

                return 'x';
            }
        };

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            try {
                $db->query('SELECT * FROM missing_table WHERE id = ?', [1]);
                $this->fail('Expected QueryException');
            } catch (QueryException $e) {
                $this->assertSame('Query failed', $e->getMessage());
                $this->assertStringContainsString('PDO reported a warning: no such table: missing_table', (string) $e->getDebugMessage());
                $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
            }
            $this->assertCount(1, $errors);
            $this->assertSame('SELECT * FROM missing_table WHERE id = ?', $errors[0]['sql']);

            // An exception of the handler that is not about a PDO failure passes unchanged
            try {
                $db->query('SELECT * FROM notes WHERE body = ?', [$noisy]);
                $this->fail('Expected ErrorException');
            } catch (\ErrorException $e) {
                $this->assertSame('not a database failure', $e->getMessage());
            }
            $this->assertCount(1, $errors);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * A PDOException is a failed query whoever built it: also one that carries no errorInfo
     * (PDO's own argument errors, a PDO subclass).
     */
    public function testAPdoExceptionWithoutErrorInfoIsStillAFailedQuery(): void
    {
        $driver = new class (':memory:') extends SqliteDriver {
            public function __construct()
            {
                $this->pdo = new class ('sqlite::memory:') extends \PDO {
                    public function prepare(string $query, array $options = []): \PDOStatement|false
                    {
                        throw new \PDOException('no errorInfo on this one');
                    }
                };
            }
        };
        $errors = [];
        $driver->on('error', static function (array $data) use (&$errors): void {
            $errors[] = $data['error'];
        });

        try {
            $driver->query('SELECT 1');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
            $this->assertSame('no errorInfo on this one', $e->getPrevious()?->getMessage());
        }
        $this->assertSame(['no errorInfo on this one'], $errors);
    }

    // =========================================================================
    // UNIQUE VIOLATIONS, AS THE DRIVERS REPORT THEM (replayed: no server needed)
    // =========================================================================

    /**
     * Each driver with a connection whose prepare() fails the way the given server would.
     *
     * @param class-string<SqliteDriver|MySqlDriver|PostgresDriver> $driverClass
     * @param array{string, int, string}|null $errorInfo
     * @param string $serverVersion What the connection reports as PDO::ATTR_SERVER_VERSION
     */
    private function failWith(string $driverClass, string $message, ?array $errorInfo, string $serverVersion = '8.0.46'): QueryException
    {
        $pdo = new class ('sqlite::memory:') extends \PDO {
            public ?\PDOException $failure = null;

            public string $serverVersion = '';

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                throw $this->failure ?? new \PDOException('unset');
            }

            public function getAttribute(int $attribute): mixed
            {
                if ($attribute === \PDO::ATTR_SERVER_VERSION && $this->serverVersion === 'unreadable') {
                    throw new \PDOException('server version unreadable');
                }
                if ($attribute === \PDO::ATTR_SERVER_VERSION && $this->serverVersion === 'not a string') {
                    return null;
                }

                return $attribute === \PDO::ATTR_SERVER_VERSION ? $this->serverVersion : parent::getAttribute($attribute);
            }
        };
        $pdo->failure = new \PDOException($message);
        $pdo->failure->errorInfo = $errorInfo;
        $pdo->serverVersion = $serverVersion;
        $driver = match ($driverClass) {
            SqliteDriver::class => new class ($pdo) extends SqliteDriver {
                public function __construct(\PDO $pdo)
                {
                    $this->pdo = $pdo;
                }
            },
            MySqlDriver::class => new class ($pdo) extends MySqlDriver {
                public function __construct(\PDO $pdo)
                {
                    $this->pdo = $pdo;
                }
            },
            PostgresDriver::class => new class ($pdo) extends PostgresDriver {
                public function __construct(\PDO $pdo)
                {
                    $this->pdo = $pdo;
                }
            },
        };

        try {
            $driver->query('INSERT INTO users (email) VALUES (?)', ['a@test.com']);
        } catch (QueryException $e) {
            return $e;
        }
        $this->fail('Expected QueryException');
    }

    public function testTheDriversReadTheViolatedKeyFromTheServersMessage(): void
    {
        $mysql = '8.0.46';
        $mariadb = '11.4.12-MariaDB-ubu2404';
        $cases = [
            // MySQL since 8.0.19 puts the table in front: exactly one dot, the key is behind it
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'a@test.com' for key 'users.email'"], 'email', $mysql],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry '7' for key 'users.PRIMARY'"], 'PRIMARY', $mysql],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'a@test.com' for key 'users.email'"], 'email', '8.0.19'],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'a@test.com' for key 'users.email'"], 'email', '8.4.3-log'],
            // more than one dot there: the table (`a.b`) or the key (`my.key`) contains one - no name rather than a wrong one
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'a' for key 'a.b.email'"], null, $mysql],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'n' for key 'probe_k.my.key'"], null, $mysql],
            // the duplicate value comes from outside: only the end of the message counts
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'x' for key 'evil' for key 'users.email'"], 'email', $mysql],
            // another message language (lc_messages): a duplicate, the name is not readable
            [MySqlDriver::class, ['23000', 1062, "Doppelter Eintrag 'a@test.com' für Schlüssel 'email'"], null, $mysql],
            // MySQL up to 8.0.18 prints the key alone, a dot in it belongs to the name (measured on 5.7.44 and 8.0.18)
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'a@test.com' for key 'email'"], 'email', '5.7.44'],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'n' for key 'my.key'"], 'my.key', '5.7.44'],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'n' for key 'my.key'"], 'my.key', '8.0.18'],
            // so does MariaDB
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'a@test.com' for key 'email'"], 'email', $mariadb],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry '7' for key 'PRIMARY'"], 'PRIMARY', $mariadb],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'n' for key 'my.key'"], 'my.key', $mariadb],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'n' for key 'my.key'"], 'my.key', '5.5.5-10.4.8-MariaDB'],
            // the version cannot be read, or is no version number: a name without a dot is the key, one with a dot cannot be told
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'a@test.com' for key 'email'"], 'email', 'unreadable'],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'a@test.com' for key 'users.email'"], null, 'unreadable'],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'a@test.com' for key 'users.email'"], null, 'some proxy'],
            [MySqlDriver::class, ['23000', 1062, "Duplicate entry 'a@test.com' for key 'users.email'"], null, 'not a string'],
            [PostgresDriver::class, ['23505', 7, "ERROR:  duplicate key value violates unique constraint \"users_email_key\"\nDETAIL:  Key (email)=(a@test.com) already exists."], 'users_email_key', ''],
            // the name is printed as it is, the detail line may contain quotes of its own
            [PostgresDriver::class, ['23505', 7, "ERROR:  duplicate key value violates unique constraint \"we\"ird\"\nDETAIL:  Key (\"Name\")=(a \"b\") already exists."], 'we"ird', ''],
            // the detail repeats the duplicate value, line breaks included: only the first line is read
            [PostgresDriver::class, ['23505', 7, "ERROR:  duplicate key value violates unique constraint \"users_email_key\"\nDETAIL:  Key (email)=(x\n violates unique constraint \"admin_pkey\"\ny) already exists."], 'users_email_key', ''],
            [PostgresDriver::class, ['23505', 7, "FEHLER:  doppelter Schlüsselwert verletzt Unique-Constraint »users_email_key«\nDETAIL:  Schlüssel »(email)=(x\n violates unique constraint \"admin_pkey\"\ny)« existiert bereits."], null, ''],
            [PostgresDriver::class, ['23505', 7, "ERROR:  could not create unique index \"users_email_idx\"\nDETAIL:  Key (email)=(x\n violates unique constraint \"admin_pkey\"\ny) is duplicated."], null, ''],
            [PostgresDriver::class, ['23505', 7, 'FEHLER:  doppelter Schlüsselwert verletzt Unique-Constraint »users_email_key«'], null, ''],
            [SqliteDriver::class, ['23000', 19, 'UNIQUE constraint failed: users.email'], null, ''],
        ];

        foreach ($cases as [$driverClass, $errorInfo, $constraint, $serverVersion]) {
            $e = $this->failWith($driverClass, 'SQLSTATE[' . $errorInfo[0] . ']: ' . $errorInfo[2], $errorInfo, $serverVersion);
            $this->assertInstanceOf(UniqueViolationException::class, $e, $errorInfo[2]);
            $this->assertSame($constraint, $e->constraint, $serverVersion . ' | ' . $errorInfo[2]);
        }
    }

    /**
     * The driver's own error code decides, not the wording: other constraint failures, and a
     * PDOException without errorInfo (a PDO subclass, a proxy), are plain failed queries.
     */
    public function testAFailureWithoutTheDriversCodeForADuplicateIsNotAUniqueViolation(): void
    {
        $cases = [
            [SqliteDriver::class, 'UNIQUE constraint failed: users.email', null],
            [SqliteDriver::class, 'UNIQUE constraint failed: users.email', ['HY000', 1, 'UNIQUE constraint failed: users.email']],
            [SqliteDriver::class, 'NOT NULL constraint failed: users.name', ['23000', 19, 'NOT NULL constraint failed: users.name']],
            [MySqlDriver::class, "Duplicate entry 'a' for key 'email'", null],
            [MySqlDriver::class, "Column 'name' cannot be null", ['23000', 1048, "Column 'name' cannot be null"]],
            [PostgresDriver::class, 'duplicate key value violates unique constraint "users_email_key"', null],
            [PostgresDriver::class, 'null value in column "name" violates not-null constraint', ['23502', 7, 'ERROR:  null value in column "name" violates not-null constraint']],
        ];

        foreach ($cases as [$driverClass, $message, $errorInfo]) {
            $e = $this->failWith($driverClass, $message, $errorInfo);
            $this->assertNotInstanceOf(UniqueViolationException::class, $e, $driverClass . ': ' . $message);
            $this->assertSame('Query failed', $e->getMessage());
        }
    }

    /**
     * A custom driver knows no code for a duplicate until it says so: it overrides
     * isUniqueViolation(), and violatedConstraint() if its database names the key.
     */
    public function testACustomDriverOptsInToUniqueViolations(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (email TEXT UNIQUE)');
        $plain = new class ($pdo) extends AbstractDriver {
            public function __construct(\PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
        $optedIn = new class ($pdo) extends AbstractDriver {
            public function __construct(\PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            protected function isUniqueViolation(\PDOException $failure): bool
            {
                return str_starts_with(self::driverMessage($failure), 'UNIQUE constraint failed');
            }

            protected function violatedConstraint(\PDOException $failure): ?string
            {
                return 'named by the driver';
            }
        };
        $plain->insert('users', ['email' => 'a@test.com']);

        try {
            $plain->insert('users', ['email' => 'a@test.com']);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertNotInstanceOf(UniqueViolationException::class, $e);
        }

        try {
            $optedIn->insert('users', ['email' => 'a@test.com']);
            $this->fail('Expected UniqueViolationException');
        } catch (UniqueViolationException $e) {
            $this->assertSame('named by the driver', $e->constraint);
        }
    }

    // =========================================================================
    // WHAT A DRIVER MAY BIND
    // =========================================================================

    public function testARawExpressionAsParameterIsRejected(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, created_at TEXT)');

        try {
            $db->execute('INSERT INTO notes (created_at) VALUES (?)', [$db->now()]);
            $this->fail('A raw expression must not be bound: it would be stored as its own text');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Cannot bind a raw expression (parameter #1): write it into the SQL instead', (string) $e->getDebugMessage());
        }
        try {
            $db->query('SELECT * FROM notes WHERE created_at < :t', ['t' => Database::raw('1')]);
            $this->fail('A raw expression must not be bound');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Cannot bind a raw expression (parameter "t")', (string) $e->getDebugMessage());
        }

        $this->assertSame(0, $db->table('notes')->count());
        // Where the library takes a raw value, it is inlined as before
        $db->insert('notes', ['created_at' => $db->now()]);
        $this->assertSame(1, $db->table('notes')->where('created_at', '<=', $db->now())->count());
    }

    public function testADriverThatBindsStreamsDecidesWhatItLetsThrough(): void
    {
        $driver = new class (':memory:') extends SqliteDriver {
            protected function unbindableParameter(array $params): ?string
            {
                return parent::unbindableParameter(array_filter($params, static fn (mixed $value): bool => !is_resource($value)));
            }

            protected function bindAndExecute(\PDOStatement $stmt, array $params): bool
            {
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key + 1, $value, is_resource($value) ? \PDO::PARAM_LOB : \PDO::PARAM_STR);
                }

                return $stmt->execute();
            }
        };
        $driver->execute('CREATE TABLE files (id INTEGER PRIMARY KEY, name TEXT, content BLOB)');
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, "binary\x00content");
        rewind($stream);

        $driver->insert('files', ['name' => 'a.bin', 'content' => $stream]);
        fclose($stream);

        $this->assertSame("binary\x00content", $driver->query('SELECT content FROM files')->fetchColumn());
        try {
            $driver->insert('files', ['name' => ['not', 'a', 'name'], 'content' => 'x']);
            $this->fail('The rest of the rule still holds');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Cannot bind a value of type array (parameter #1)', (string) $e->getDebugMessage());
        }
    }

    // =========================================================================
    // LIKE IN A JOIN CONDITION
    // =========================================================================

    public function testLikeInAJoinConditionBindsTheEscapeCharacterToo(): void
    {
        $db = Database::sqlite(':memory:');
        $db->execute('CREATE TABLE files (id INTEGER PRIMARY KEY, path TEXT)');
        $db->execute('CREATE TABLE rules (id INTEGER PRIMARY KEY, pattern TEXT)');
        $db->insert('files', ['path' => '100% done']);
        $db->insert('files', ['path' => '1000 lines']);
        $db->insert('rules', ['pattern' => Database::escapeLike('100%') . '%']);

        $query = $db->table('files')->select('files.path')->join('rules', 'files.path', 'LIKE', 'rules.pattern')->where('files.id', '>', 0);
        [$sql, $params] = $query->toSql();
        $this->assertSame('SELECT `files`.`path` FROM `files` INNER JOIN `rules` ON `files`.`path` LIKE `rules`.`pattern` ESCAPE ? WHERE `files`.`id` > ?', $sql);
        $this->assertSame(['\\', 0], $params, 'the join comes before the WHERE: so does its parameter');
        $this->assertSame([['path' => '100% done']], $query->get());
        $this->assertSame(1, $query->count());

        $others = $db->table('files')->select('files.path')->leftJoin('rules', 'files.path', 'NOT LIKE', 'rules.pattern')->whereNotNull('rules.id')->get();
        $this->assertSame([['path' => '1000 lines']], $others);
    }
}
