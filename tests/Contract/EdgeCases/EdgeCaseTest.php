<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\EdgeCases;

use ErrorException;
use PDO;
use PDOException;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\UniqueViolationException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * Edge cases of the builder and the CRUD methods against a database: each test pins one behavior
 * at a boundary of the input or of the connection's state.
 *
 * What the builder renders without a database is in tests/Unit/EdgeCaseRenderingTest and
 * tests/Unit/ContractQueryRenderingTest; what needs a subclass of the driver itself is in
 * tests/Driver.
 */
class EdgeCaseTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        InsertIdPdo::reset();
    }

    protected function tearDown(): void
    {
        InsertIdPdo::reset();
        parent::tearDown();
    }

    // =========================================================================
    // SCHEMA QUOTING BUG FIX TEST
    // Bug: "public.users" was quoted as `"public.users"` instead of `"public"."users"`
    // =========================================================================

    public function testSchemaTableQuotingInSelect(): void
    {
        // Test that schema.table format is handled correctly in queries:
        // table.column is quoted part by part, whether the database has schemas or not
        $this->create('test_table', ['id' => 'id', 'name' => 'text']);
        $this->db->insert('test_table', ['name' => 'Test']);

        // The QueryBuilder should properly quote table.column in select
        $result = $this->db->table('test_table')
            ->select(['test_table.id', 'test_table.name'])
            ->first();

        $this->assertNotNull($result);
        $this->assertSame('Test', $result['name']);
    }

    public function testSchemaTableQuotingInQueryBuilder(): void
    {
        // Create tables for join test
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $this->create('posts', ['id' => 'id', 'user_id' => 'int', 'title' => 'text']);

        $userId = $this->db->insert('users', ['name' => 'John']);
        $this->db->insert('posts', ['user_id' => $userId, 'title' => 'First Post']);

        // Join with qualified column names
        $result = $this->db->table('posts')
            ->select(['posts.title', 'users.name as author'])
            ->join('users', 'users.id', '=', 'posts.user_id')
            ->first();

        $this->assertNotNull($result);
        $this->assertSame('First Post', $result['title']);
        $this->assertSame('John', $result['author']);
    }

    // =========================================================================
    // FINDONE EMPTY WHERE BUG FIX TEST
    // Bug: findOne([]) would generate invalid SQL "SELECT * FROM table WHERE LIMIT 1"
    // =========================================================================

    public function testFindOneWithEmptyWhereThrowsException(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query failed');

        $this->db->findOne('users', []);
    }

    public function testFindOneWithValidWhereWorks(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $this->db->insert('users', ['name' => 'Alice']);

        $result = $this->db->findOne('users', ['name' => 'Alice']);

        $this->assertNotNull($result);
        $this->assertSame('Alice', $result['name']);
    }

    public function testFindAllWithEmptyWhereReturnsAllRows(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $this->db->insert('users', ['name' => 'Alice']);
        $this->db->insert('users', ['name' => 'Bob']);

        // findAll without WHERE should return all rows
        $results = $this->db->findAll('users');

        $this->assertCount(2, $results);
    }

    // =========================================================================
    // ADDITIONAL EDGE CASES
    // =========================================================================

    public function testQuoteIdentifierWithSpecialCharacters(): void
    {
        // Create table with special column name containing quote
        $this->create('test', ['id' => 'id', 'my"column' => 'text']);
        $this->db->insert('test', ['my"column' => 'value']);

        $result = $this->db->findOne('test', ['id' => 1]);
        $this->assertNotNull($result);
        $this->assertSame('value', $result['my"column']);
    }

    // =========================================================================
    // NULL IN CRUD WHERE BUG FIX TEST
    // Bug: buildWhereClause() generated "column = ?" with null, which is always false in SQL
    // =========================================================================

    public function testCrudUpdateWithNullWhereThrowsException(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text', 'deleted_at' => 'text']);
        $this->db->insert('users', ['name' => 'Alice']);

        $this->expectException(QueryException::class);

        $this->db->update('users', ['name' => 'New'], ['deleted_at' => null]);
    }

    public function testCrudDeleteWithNullWhereThrowsException(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text', 'deleted_at' => 'text']);
        $this->db->insert('users', ['name' => 'Alice']);

        $this->expectException(QueryException::class);

        $this->db->delete('users', ['deleted_at' => null]);
    }

    public function testCrudFindOneWithNullWhereThrowsException(): void
    {
        $this->create('users', ['id' => 'id', 'deleted_at' => 'text']);

        $this->expectException(QueryException::class);

        $this->db->findOne('users', ['deleted_at' => null]);
    }

    public function testCrudFindAllWithNullWhereThrowsException(): void
    {
        $this->create('users', ['id' => 'id', 'deleted_at' => 'text']);

        $this->expectException(QueryException::class);

        $this->db->findAll('users', ['deleted_at' => null]);
    }

    // =========================================================================
    // DIRECT QUERY BUILDER SCHEMA QUOTING TESTS
    // These test the QueryBuilder's quoteIdentifier directly
    // =========================================================================

    public function testQueryBuilderHandlesDottedIdentifiers(): void
    {
        $this->create('orders', ['id' => 'id', 'status' => 'text', 'user_id' => 'int']);
        $this->db->insert('orders', ['status' => 'pending', 'user_id' => 1]);

        // Query with table.column syntax
        $result = $this->db->table('orders')
            ->select(['orders.id', 'orders.status'])
            ->where('orders.status', 'pending')
            ->first();

        $this->assertNotNull($result);
        $this->assertSame('pending', $result['status']);
    }

    // =========================================================================
    // INSERT LASTINSERTID BUG FIX TEST
    // Bug: insert() returned false when lastInsertId() failed instead of throwing
    // =========================================================================

    public function testInsertThrowsExceptionWhenLastInsertIdReturnsFalse(): void
    {
        // A connection whose lastInsertId() returns false
        InsertIdPdo::$returnsFalse = true;
        $driver = $this->connect(['pdoClass' => InsertIdPdo::class]);

        $this->create('users', ['id' => 'id', 'name' => 'text']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Insert failed');

        $driver->insert('users', ['name' => 'Test']);
    }

    public function testInsertThrowsExceptionWithDebugMessageContainingSqlAndParams(): void
    {
        // A connection whose lastInsertId() returns false
        InsertIdPdo::$returnsFalse = true;
        $driver = $this->connect(['pdoClass' => InsertIdPdo::class]);

        $this->create('users', ['id' => 'id', 'name' => 'text']);

        try {
            $driver->insert('users', ['name' => 'Test']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Failed to retrieve last insert ID', $e->getDebugMessage() ?? '');
            $this->assertStringContainsString('SQL:', $e->getDebugMessage() ?? '');
            $this->assertStringContainsString('Params:', $e->getDebugMessage() ?? '');
        }
    }

    // =========================================================================
    // AGGREGATE ORDERBY BUG FIX TEST
    // Bug: count()/sum()/etc. included ORDER BY clause, causing invalid SQL
    // on databases that refuse it there and unnecessary performance overhead
    // =========================================================================

    public function testAggregatesIgnoreOrderBy(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text', 'age' => 'int']);
        $this->db->insert('users', ['name' => 'Alice', 'age' => 30]);
        $this->db->insert('users', ['name' => 'Bob', 'age' => 25]);

        // Build query with orderBy, then call count()
        $builder = $this->db->table('users')->orderBy('name', 'DESC');

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
        $this->create('users', ['id' => 'id', 'name' => 'text', 'age' => 'int']);
        foreach ([20, 30, 40] as $age) {
            $this->db->insert('users', ['name' => 'test', 'age' => $age]);
        }
        $this->db->insert('users', ['name' => 'other', 'age' => 99]);

        // We can't directly test the SQL generated by aggregate(), but the results show it: with the
        // OFFSET 5 of the builder each aggregate would find no row (three match), with ORDER BY a
        // database that refuses one there would fail (sum() and avg() over a number: over text they
        // are the database's own matter)
        $builder = $this->db->table('users')
            ->where('name', 'test')
            ->orderBy('name')
            ->limit(10)
            ->offset(5);
        $before = $builder->toSql();

        $this->assertSame(3, $builder->count());
        $this->assertEquals(90, $builder->sum('age'));
        $this->assertEquals(30, $builder->avg('age'));
        $this->assertSame('test', $builder->min('name'));
        $this->assertSame('test', $builder->max('name'));
        $this->assertSame(20, $builder->min('age'));

        // Original builder state should be preserved
        $this->assertSame($before, $builder->toSql());
    }

    // =========================================================================
    // LIMIT/ORDERBY IN UPDATE/DELETE BUG FIX TEST
    // Bug: limit() and orderBy() were silently ignored in update()/delete(),
    // causing unintended data loss (e.g., deleting all rows instead of a subset)
    // =========================================================================

    public function testUpdateWithOrderByThrowsException(): void
    {
        $this->create('logs', ['id' => 'id', 'level' => 'text']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Update failed');

        $this->db->table('logs')
            ->where('level', 'info')
            ->orderBy('id', 'ASC')
            ->update(['level' => 'debug']);
    }

    public function testUpdateWithOffsetThrowsException(): void
    {
        $this->create('logs', ['id' => 'id', 'level' => 'text']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Update failed');

        $this->db->table('logs')
            ->where('level', 'info')
            ->offset(5)
            ->update(['level' => 'debug']);
    }

    public function testDeleteWithOrderByThrowsException(): void
    {
        $this->create('logs', ['id' => 'id', 'level' => 'text']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Delete failed');

        $this->db->table('logs')
            ->where('level', 'info')
            ->orderBy('id', 'ASC')
            ->delete();
    }

    /**
     * Regression test: update()/delete() never render joins, so a delete narrowed down by a join
     * hit every matching row of the base table. join() is now rejected like limit()/orderBy().
     */
    public function testDeleteWithJoinThrowsExceptionAndDeletesNothing(): void
    {
        $this->create('users', ['id' => 'id', 'status' => 'text']);
        $this->create('orders', ['id' => 'id', 'user_id' => 'int', 'status' => 'text']);
        foreach (['active', 'active', 'inactive'] as $status) {
            $this->db->insert('users', ['status' => $status]);
        }
        $this->db->insert('orders', ['user_id' => 1, 'status' => 'active']);

        try {
            $this->db->table('users')
                ->join('orders', 'users.id', '=', 'orders.user_id')
                ->where('status', 'active')
                ->delete();
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('Delete failed', $e->getMessage());
            $this->assertStringContainsString('join()', $e->getDebugMessage() ?? '');
        }

        $this->assertSame(3, $this->db->table('users')->count());
    }

    public function testUpdateWithGroupByAndHavingThrowsExceptionListingBoth(): void
    {
        $this->create('logs', ['id' => 'id', 'level' => 'text']);

        try {
            $this->db->table('logs')
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
        $this->create('users', ['id' => 'id', 'country' => 'text']);
        $this->db->insert('users', ['country' => 'IS']);
        $this->db->insert('users', ['country' => 'DE']);

        [, $params] = $this->db->table('users')->where('country', 'IS')->toSql();
        $this->assertSame(['IS'], $params);
        $this->assertCount(1, $this->db->table('users')->where('country', 'IS')->get());
        $this->assertCount(0, $this->db->table('users')->where('country', 'LIKE')->get());
    }

    /**
     * Regression test: select(['users.*']) produced "users"."*" (no such column).
     */
    public function testSelectTableWildcardIsNotQuoted(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $this->create('orders', ['id' => 'id', 'user_id' => 'int', 'total' => 'int']);
        $this->db->insert('users', ['name' => 'Max']);
        $this->db->insert('orders', ['user_id' => 1, 'total' => 5]);

        $query = $this->db->table('users')->select(['users.*', 'orders.total'])->join('orders', 'users.id', '=', 'orders.user_id');

        $this->assertSame([['id' => 1, 'name' => 'Max', 'total' => 5]], $query->get());
    }

    public function testBuilderUpdateWithEmptyDataThrowsClearException(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text']);

        try {
            $this->db->table('users')->where('id', 1)->update([]);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('Update failed', $e->getMessage());
            $this->assertSame('Cannot update with empty data', $e->getDebugMessage());
        }
    }

    public function testUpdateWithoutLimitOrOrderByStillWorks(): void
    {
        $this->create('logs', ['id' => 'id', 'level' => 'text']);
        $this->db->insert('logs', ['level' => 'info']);
        $this->db->insert('logs', ['level' => 'info']);

        $affected = $this->db->table('logs')
            ->where('level', 'info')
            ->update(['level' => 'debug']);

        $this->assertSame(2, $affected);
    }

    public function testDeleteWithoutLimitOrOrderByStillWorks(): void
    {
        $this->create('logs', ['id' => 'id', 'level' => 'text']);
        $this->db->insert('logs', ['level' => 'info']);

        $affected = $this->db->table('logs')
            ->where('level', 'info')
            ->delete();

        $this->assertSame(1, $affected);
    }

    // =========================================================================
    // WHERE NULL BUG FIX TEST
    // Bug: where('column', null) generated "column = NULL" which is always false
    // in SQL. Users must use whereNull()/whereNotNull() instead.
    // =========================================================================

    /**
     * IS / IS NOT are the null-safe comparison: a value that may be null is compared as it is.
     * The SQL is the database's own null-safe comparison (tests/Unit), bound like any value.
     */
    public function testWhereIsAndIsNotTakeANullValue(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text', 'deleted_at' => 'text']);
        $this->db->insert('users', ['name' => 'kept', 'deleted_at' => null]);
        $this->db->insert('users', ['name' => 'gone', 'deleted_at' => '2026-01-01']);

        [, $params] = $this->db->table('users')->where('deleted_at', 'IS', null)->toSql();
        $this->assertSame([null], $params);

        foreach ([[null, 'IS', 'kept'], [null, 'IS NOT', 'gone'], ['2026-01-01', 'is', 'gone'], ['2026-01-01', 'is not', 'kept']] as [$value, $operator, $expected]) {
            $rows = $this->db->table('users')->where('deleted_at', $operator, $value)->get();
            $this->assertSame([$expected], array_column($rows, 'name'), "{$operator} " . var_export($value, true));
        }
    }

    public function testWhereWithNonNullValuesStillWorks(): void
    {
        $this->create('users', ['id' => 'id', 'status' => 'text']);
        $this->db->insert('users', ['status' => 'active']);

        $result = $this->db->table('users')->where('status', 'active')->first();

        $this->assertNotNull($result);
        $this->assertSame('active', $result['status']);
    }

    // =========================================================================
    // ESCAPE LIKE TESTS
    // =========================================================================

    public function testEscapeLikeEndToEndWithWhereLike(): void
    {
        $this->create('products', ['id' => 'id', 'name' => 'text']);
        $this->db->insert('products', ['name' => 'Rabatt: 100%']);
        $this->db->insert('products', ['name' => 'Rabatt: 1000 Euro']);

        $search = Database::escapeLike('100%');
        $results = $this->db->table('products')
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
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $this->db->insert('users', ['name' => 'A']);
        $this->db->insert('users', ['name' => 'B']);

        try {
            $this->db->table('users')->limit(-1);
            $this->fail('limit(-1) must throw: a database may read it as "no limit"');
        } catch (QueryException $e) {
            $this->assertSame('limit() needs 0 or more, got -1', $e->getDebugMessage());
        }

        try {
            $this->db->table('users')->limit(1)->offset(-5);
            $this->fail('offset(-5) must throw: a database may read it as 0');
        } catch (QueryException $e) {
            $this->assertSame('offset() needs 0 or more, got -5', $e->getDebugMessage());
        }

        $this->assertSame([], $this->db->table('users')->limit(0)->get());
        $this->assertCount(2, $this->db->table('users')->limit(2)->offset(0)->get());
    }

    public function testHavingWithNullThrowsExceptForTheNullSafeOperators(): void
    {
        $this->create('users', ['id' => 'id', 'team' => 'text']);
        $this->db->insert('users', ['team' => 'a']);
        $this->db->insert('users', ['team' => null]);

        foreach (['=', '!=', '>', 'LIKE'] as $operator) {
            try {
                $this->db->table('users')->groupBy('team')->having('team', $operator, null);
                $this->fail("having() with null and {$operator} must throw: the comparison is never true");
            } catch (QueryException $e) {
                $this->assertSame(
                    sprintf('Cannot use a null value in having() (operator "%s", column "team"). Use the operator IS or IS NOT instead.', $operator),
                    $e->getDebugMessage()
                );
            }
        }

        try {
            $this->db->table('users')->having(Database::raw('COUNT(*)'), '>', null);
            $this->fail('having() with null must throw for a raw column too');
        } catch (QueryException $e) {
            $this->assertStringContainsString('column "COUNT(*)"', (string) $e->getDebugMessage());
        }

        $rows = $this->db->table('users')->select('team')->groupBy('team')->having('team', 'IS', null)->get();
        $this->assertSame([['team' => null]], $rows);
        $rows = $this->db->table('users')->select('team')->groupBy('team')->having('team', 'is not', null)->get();
        $this->assertSame([['team' => 'a']], $rows);
    }

    // =========================================================================
    // AGGREGATE RESULT KEY
    // Bug: aggregates read the key "aggregate"; with PDO::ATTR_CASE it is "AGGREGATE" and
    // count() returned 0, sum()/max() null
    // =========================================================================

    public function testAggregatesDoNotDependOnTheResultKeyCase(): void
    {
        $this->create('orders', ['id' => 'id', 'amount' => 'int', 'status' => 'text']);
        $this->db->insert('orders', ['amount' => 10, 'status' => 'a']);
        $this->db->insert('orders', ['amount' => 30, 'status' => 'b']);
        // sum() is in the database's native type: an integer, or a numeric string where the sum of
        // integers is a DECIMAL. Read once without ATTR_CASE, it is what must come back with it.
        $sum = $this->db->table('orders')->sum('amount');
        $this->assertSame('40', (string) $sum);
        $this->db->getPdo()->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);

        $this->assertSame(2, $this->db->table('orders')->count());
        $this->assertSame($sum, $this->db->table('orders')->sum('amount'));
        $this->assertSame(30, $this->db->table('orders')->max('amount'));
        $this->assertSame(2, $this->db->table('orders')->groupBy('status')->count());
        $this->assertSame(2, $this->db->table('orders')->select('status')->distinct()->count());
        $this->assertNull($this->db->table('orders')->where('amount', '>', 100)->max('amount'));
    }

    // =========================================================================
    // INSERT ID AND THE QUERY HOOK
    // Bug: insert() read lastInsertId() after the 'query' hook; a listener that inserted
    // replaced the id
    // =========================================================================

    public function testAFailingInsertIdReadStillFiresTheQueryHookFirst(): void
    {
        // A connection whose next lastInsertId() throws: the driver reports it as its own failure
        InsertIdPdo::$throwingReads = 1;
        $driver = $this->connect(['pdoClass' => InsertIdPdo::class]);
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $seen = [];
        $driver->on('query', function (array $data) use (&$seen): void {
            $seen[] = (string) $data['sql'];
        });

        try {
            $driver->insert('users', ['name' => 'Test']);
            $this->fail('The failed id read must reach the caller');
        } catch (QueryException $e) {
            $this->assertSame('Failed to get last insert ID', $e->getMessage());
        }

        // the statement's text: tests/Driver
        $this->assertCount(1, $seen, 'the statement ran, so its hook fired');
        $this->assertSame(1, $driver->table('users')->count());
    }

    public function testAnInsertIdReadThatFailsOnceIsNotRetried(): void
    {
        InsertIdPdo::$throwingReads = 1;
        $driver = $this->connect(['pdoClass' => InsertIdPdo::class]);
        $this->create('users', ['id' => 'id', 'name' => 'text']);

        try {
            $driver->insert('users', ['name' => 'Test']);
            $this->fail('The failed id read must reach the caller');
        } catch (QueryException $e) {
            $this->assertSame('Failed to get last insert ID', $e->getMessage());
        }
        $this->assertSame(1, InsertIdPdo::$reads);
    }

    public function testDebugMessageSurvivesBinaryParameters(): void
    {
        try {
            $this->db->query('SELECT * FROM missing WHERE a = ? AND b = ?', ["\xFF\xFE", 'ok']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertStringContainsString('| Params: ["\ufffd\ufffd","ok"]', (string) $e->getDebugMessage());
        }
    }

    /**
     * The id step is taken by the statement it was set for and by nothing else: a listener that
     * runs the very same statement - told about the insert itself, or about a statement the
     * driver sent ahead of it - does not take the step or make it run twice.
     *
     * The case of a statement sent ahead needs a driver that overrides query(): see
     * testAListenerRepeatingTheInsertWhenAStatementSentAheadIsToldDoesNotReplaceTheId in tests/Driver.
     */
    public function testAListenerRepeatingTheSameInsertDoesNotReplaceTheId(): void
    {
        $this->create('notes', ['id' => 'id', 'body' => 'text']);
        $db = $this->db;
        $repeated = false;
        $db->on('query', static function (array $data) use ($db, &$repeated): void {
            if (!$repeated) { // the first statement told: the insert itself
                $repeated = true;
                $db->execute((string) $data['sql'], is_array($data['params']) ? $data['params'] : []);
            }
        });

        $this->assertSame(1, $db->insert('notes', ['body' => 'same']));
        $this->assertSame(2, $db->table('notes')->count());
    }

    // =========================================================================
    // WARNING MODE WITH A THROWING ERROR HANDLER
    // Bug: with PDO::ERRMODE_WARNING and an error handler that throws, a failed statement left
    // query() as ErrorException: no 'error' hook, no QueryException, not remembered for commit()
    // =========================================================================

    public function testWarningModeWithAThrowingErrorHandlerIsAFailedQuery(): void
    {
        $this->create('notes', ['id' => 'id', 'body' => 'text']);
        // The database's own message for the failure, as PDO reports it in exception mode
        $reported = null;
        try {
            self::binding()->pdo()->prepare('SELECT * FROM missing_table WHERE id = ?');
        } catch (PDOException $e) {
            $reported = $e->errorInfo[2] ?? null;
        }
        $this->assertIsString($reported, 'missing_table must not exist');
        $this->assertStringContainsString('missing_table', $reported);

        $db = $this->db;
        $db->getPdo()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
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
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            try {
                $db->query('SELECT * FROM missing_table WHERE id = ?', [1]);
                $this->fail('Expected QueryException');
            } catch (QueryException $e) {
                $this->assertSame('Query failed', $e->getMessage());
                $this->assertStringContainsString('PDO reported a warning: ' . $reported, (string) $e->getDebugMessage());
                $this->assertInstanceOf(PDOException::class, $e->getPrevious());
            }
            $this->assertCount(1, $errors);
            $this->assertSame('SELECT * FROM missing_table WHERE id = ?', $errors[0]['sql']);

            // An exception of the handler that is not about a PDO failure passes unchanged
            try {
                $db->query('SELECT * FROM notes WHERE body = ?', [$noisy]);
                $this->fail('Expected ErrorException');
            } catch (ErrorException $e) {
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
        $driver = $this->connect(['pdoClass' => PrepareFailingPdo::class]);
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
    // UNIQUE VIOLATIONS
    // =========================================================================

    /**
     * An exception from reading the insert ID that is no database failure - someone else's -
     * passes unchanged: it is not wrapped as a failed statement.
     */
    public function testAForeignExceptionFromTheInsertIdReadPassesUnchanged(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $db = $this->connect(['pdoClass' => InsertIdPdo::class]);
        $foreign = new \RuntimeException('not a database failure');
        InsertIdPdo::$foreign = $foreign;

        try {
            $db->insert('users', ['name' => 'A']);
            $this->fail('Expected the foreign exception');
        } catch (\RuntimeException $e) {
            $this->assertSame($foreign, $e);
        } finally {
            InsertIdPdo::reset();
        }
        $this->assertSame(1, $this->db->table('users')->count(), 'the row is there');
    }

    /**
     * A custom driver knows no code for a duplicate until it says so: it overrides
     * isUniqueViolation(), and violatedConstraint() if its database names the key.
     */
    public function testACustomDriverOptsInToUniqueViolations(): void
    {
        $this->create('users', ['email' => 'text UNIQUE']);
        $pdo = self::binding()->pdo();
        [$duplicate] = self::binding()->failureCodes()['duplicate'];
        $plain = new class ($pdo) extends AbstractDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
        $optedIn = new class ($pdo) extends AbstractDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            protected function isUniqueViolation(PDOException $failure): bool
            {
                // SQLSTATE class 23, integrity constraint violation: standard SQL, unlike the wording of the message
                $state = $failure->errorInfo[0] ?? null;

                return is_string($state) && str_starts_with($state, '23');
            }

            protected function violatedConstraint(PDOException $failure): string
            {
                return 'named by the driver';
            }
        };
        // Opted in, without a name: the default names none
        $unnamed = new class ($pdo, $duplicate) extends AbstractDriver {
            public function __construct(PDO $pdo, private readonly string $duplicate)
            {
                $this->pdo = $pdo;
            }

            protected function isUniqueViolation(PDOException $failure): bool
            {
                return ($failure->errorInfo[0] ?? null) === $this->duplicate;
            }
        };
        $plain->execute('INSERT INTO users (email) VALUES (?)', ['a@test.com']);

        try {
            $plain->execute('INSERT INTO users (email) VALUES (?)', ['a@test.com']);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertNotInstanceOf(UniqueViolationException::class, $e);
        }

        try {
            $optedIn->execute('INSERT INTO users (email) VALUES (?)', ['a@test.com']);
            $this->fail('Expected UniqueViolationException');
        } catch (UniqueViolationException $e) {
            $this->assertSame('named by the driver', $e->constraint);
        }

        try {
            $unnamed->execute('INSERT INTO users (email) VALUES (?)', ['a@test.com']);
            $this->fail('Expected UniqueViolationException');
        } catch (UniqueViolationException $e) {
            $this->assertNull($e->constraint);
        }
    }

    // =========================================================================
    // WHAT A DRIVER MAY BIND
    // =========================================================================

    public function testARawExpressionAsParameterIsRejected(): void
    {
        $this->create('notes', ['id' => 'id', 'created_at' => 'text']);
        $db = $this->db;

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

    // =========================================================================
    // LIKE IN A JOIN CONDITION
    // =========================================================================

    public function testLikeInAJoinConditionBindsTheEscapeCharacterToo(): void
    {
        $this->create('files', ['id' => 'id', 'path' => 'text']);
        $this->create('rules', ['id' => 'id', 'pattern' => 'text']);
        $this->db->insert('files', ['path' => '100% done']);
        $this->db->insert('files', ['path' => '1000 lines']);
        $this->db->insert('rules', ['pattern' => Database::escapeLike('100%') . '%']);

        $query = $this->db->table('files')->select('files.path')->join('rules', 'files.path', 'LIKE', 'rules.pattern')->where('files.id', '>', 0);
        [, $params] = $query->toSql();
        $this->assertSame(['\\', 0], $params, 'the join comes before the WHERE: so does its parameter');
        $this->assertSame([['path' => '100% done']], $query->get());
        $this->assertSame(1, $query->count());

        $others = $this->db->table('files')->select('files.path')->leftJoin('rules', 'files.path', 'NOT LIKE', 'rules.pattern')->whereNotNull('rules.id')->get();
        $this->assertSame([['path' => '1000 lines']], $others);
    }
}
