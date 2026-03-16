<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;

class QueryBuilderTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = Database::sqlite();
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, age INTEGER, active INTEGER DEFAULT 1)');
        $this->db->execute('CREATE TABLE profiles (id INTEGER PRIMARY KEY, user_id INTEGER, bio TEXT)');

        // Seed data
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com', 'age' => 25, 'active' => 1]);
        $this->db->insert('users', ['name' => 'Anna', 'email' => 'anna@example.com', 'age' => 30, 'active' => 1]);
        $this->db->insert('users', ['name' => 'Tom', 'email' => 'tom@example.com', 'age' => 20, 'active' => 0]);

        $this->db->insert('profiles', ['user_id' => 1, 'bio' => 'Developer']);
        $this->db->insert('profiles', ['user_id' => 2, 'bio' => 'Designer']);
    }

    // =========================================================================
    // BASIC SELECT
    // =========================================================================

    public function testTableReturnsQueryBuilder(): void
    {
        $builder = $this->db->table('users');

        $this->assertInstanceOf(QueryBuilder::class, $builder);
    }

    public function testGetReturnsAllRows(): void
    {
        $users = $this->db->table('users')->get();

        $this->assertCount(3, $users);
    }

    public function testFirstReturnsFirstRow(): void
    {
        $user = $this->db->table('users')->first();

        $this->assertSame('Max', $user['name']);
    }

    /**
     * Regression test: first() should not mutate the builder state.
     *
     * Previously, first() would set limit(1) on the builder permanently,
     * causing subsequent get() calls to return only 1 row.
     */
    public function testFirstDoesNotMutateBuilderState(): void
    {
        $builder = $this->db->table('users');

        // Call first() - this should NOT affect the builder
        $first = $builder->first();
        $this->assertNotNull($first);

        // get() should still return all 3 rows, not just 1
        $all = $builder->get();
        $this->assertCount(3, $all, 'first() mutated the builder state - get() returned only ' . count($all) . ' row(s) instead of 3');
    }

    public function testFirstReturnsNullWhenEmpty(): void
    {
        $user = $this->db->table('users')->where('id', 999)->first();

        $this->assertNull($user);
    }

    public function testSelectSpecificColumns(): void
    {
        $user = $this->db->table('users')->select(['name', 'email'])->first();

        $this->assertArrayHasKey('name', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertArrayNotHasKey('age', $user);
    }

    public function testSelectWithStringColumns(): void
    {
        $user = $this->db->table('users')->select('name, email')->first();

        $this->assertArrayHasKey('name', $user);
        $this->assertArrayHasKey('email', $user);
    }

    public function testDistinct(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max2@example.com', 'age' => 35, 'active' => 1]);

        $names = $this->db->table('users')->select('name')->distinct()->get();

        $this->assertCount(3, $names); // Max, Anna, Tom (distinct)
    }

    public function testSelectStar(): void
    {
        $users = $this->db->table('users')->select('*')->get();

        $this->assertCount(3, $users);
        $this->assertArrayHasKey('id', $users[0]);
        $this->assertArrayHasKey('name', $users[0]);
        $this->assertArrayHasKey('email', $users[0]);
    }

    // =========================================================================
    // WHERE
    // =========================================================================

    public function testWhereEquals(): void
    {
        $users = $this->db->table('users')->where('name', 'Max')->get();

        $this->assertCount(1, $users);
        $this->assertSame('Max', $users[0]['name']);
    }

    public function testWhereWithOperator(): void
    {
        $users = $this->db->table('users')->where('age', '>', 22)->get();

        $this->assertCount(2, $users);
    }

    public function testWhereWithArraySyntax(): void
    {
        $users = $this->db->table('users')->where(['name' => 'Max', 'active' => 1])->get();

        $this->assertCount(1, $users);
    }

    public function testWhereIn(): void
    {
        $users = $this->db->table('users')->whereIn('name', ['Max', 'Anna'])->get();

        $this->assertCount(2, $users);
    }

    public function testWhereInEmptyArrayThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->table('users')->whereIn('name', [])->get();
    }

    public function testWhereNotIn(): void
    {
        $users = $this->db->table('users')->whereNotIn('name', ['Max', 'Anna'])->get();

        $this->assertCount(1, $users);
        $this->assertSame('Tom', $users[0]['name']);
    }

    public function testWhereNotInEmptyArrayThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->table('users')->whereNotIn('name', [])->get();
    }

    public function testWhereBetween(): void
    {
        $users = $this->db->table('users')->whereBetween('age', [22, 28])->get();

        $this->assertCount(1, $users);
        $this->assertSame('Max', $users[0]['name']);
    }

    public function testWhereBetweenInvalidValuesThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->table('users')->whereBetween('age', [22])->get();
    }

    public function testWhereNotBetween(): void
    {
        $users = $this->db->table('users')->whereNotBetween('age', [22, 28])->get();

        $this->assertCount(2, $users);
    }

    public function testWhereNotBetweenInvalidValuesThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->table('users')->whereNotBetween('age', [22])->get();
    }

    public function testWhereNull(): void
    {
        $this->db->execute("UPDATE users SET email = NULL WHERE name = 'Tom'");

        $users = $this->db->table('users')->whereNull('email')->get();

        $this->assertCount(1, $users);
        $this->assertSame('Tom', $users[0]['name']);
    }

    public function testWhereNotNull(): void
    {
        $this->db->execute("UPDATE users SET email = NULL WHERE name = 'Tom'");

        $users = $this->db->table('users')->whereNotNull('email')->get();

        $this->assertCount(2, $users);
    }

    public function testWhereLike(): void
    {
        $users = $this->db->table('users')->whereLike('email', '%@example.com')->get();

        $this->assertCount(3, $users);
    }

    public function testWhereNotLike(): void
    {
        $users = $this->db->table('users')->whereNotLike('name', 'M%')->get();

        $this->assertCount(2, $users);
    }

    public function testMultipleWhereConditions(): void
    {
        $users = $this->db->table('users')
            ->where('active', 1)
            ->where('age', '>', 22)
            ->get();

        $this->assertCount(2, $users);
    }

    // =========================================================================
    // JOINS
    // =========================================================================

    public function testInnerJoin(): void
    {
        $results = $this->db->table('users')
            ->select('users.name, profiles.bio')
            ->join('profiles', 'users.id', '=', 'profiles.user_id')
            ->get();

        $this->assertCount(2, $results);
    }

    public function testLeftJoin(): void
    {
        $results = $this->db->table('users')
            ->select('users.name, profiles.bio')
            ->leftJoin('profiles', 'users.id', '=', 'profiles.user_id')
            ->get();

        $this->assertCount(3, $results);
    }

    // =========================================================================
    // ORDER BY, LIMIT, OFFSET
    // =========================================================================

    public function testOrderBy(): void
    {
        $users = $this->db->table('users')->orderBy('age', 'ASC')->get();

        $this->assertSame('Tom', $users[0]['name']); // age 20
        $this->assertSame('Max', $users[1]['name']); // age 25
        $this->assertSame('Anna', $users[2]['name']); // age 30
    }

    public function testOrderByDesc(): void
    {
        $users = $this->db->table('users')->orderBy('age', 'DESC')->get();

        $this->assertSame('Anna', $users[0]['name']); // age 30
    }

    public function testOrderByInvalidDirectionDefaultsToAsc(): void
    {
        $users = $this->db->table('users')->orderBy('age', 'INVALID')->get();

        // Invalid direction should default to ASC
        $this->assertSame('Tom', $users[0]['name']); // age 20 (lowest)
    }

    public function testLimit(): void
    {
        $users = $this->db->table('users')->limit(2)->get();

        $this->assertCount(2, $users);
    }

    public function testOffset(): void
    {
        $users = $this->db->table('users')->orderBy('id')->limit(2)->offset(1)->get();

        $this->assertCount(2, $users);
        $this->assertSame('Anna', $users[0]['name']);
    }

    // =========================================================================
    // AGGREGATIONS
    // =========================================================================

    public function testCount(): void
    {
        $count = $this->db->table('users')->count();

        $this->assertSame(3, $count);
    }

    public function testCountWithWhere(): void
    {
        $count = $this->db->table('users')->where('active', 1)->count();

        $this->assertSame(2, $count);
    }

    /**
     * Regression test: Aggregates must ignore limit/offset.
     *
     * Previously, count() on a query with limit(10) would return max 10,
     * instead of the total count. This broke pagination patterns like:
     * $total = $query->count(); $page = $query->limit(10)->get();
     */
    public function testAggregatesIgnoreLimitAndOffset(): void
    {
        // count() should return total, not limited count
        $builder = $this->db->table('users')->limit(1)->offset(1);
        $this->assertSame(3, $builder->count());

        // sum() should sum all rows, not limited
        $builder = $this->db->table('users')->limit(1);
        $this->assertEquals(75, $builder->sum('age')); // 25 + 30 + 20

        // avg() should average all rows
        $builder = $this->db->table('users')->limit(1);
        $this->assertEquals(25, $builder->avg('age'));

        // min/max should consider all rows
        $builder = $this->db->table('users')->limit(1)->orderBy('age', 'DESC');
        $this->assertEquals(20, $builder->min('age'));
        $this->assertEquals(30, $builder->max('age'));

        // limit/offset should still work for get() after aggregate
        $builder = $this->db->table('users')->orderBy('id')->limit(2)->offset(1);
        $builder->count(); // should not affect subsequent get()
        $users = $builder->get();
        $this->assertCount(2, $users);
        $this->assertSame('Anna', $users[0]['name']); // offset=1 skips Max
        $this->assertSame('Tom', $users[1]['name']);
    }

    public function testCountWithColumn(): void
    {
        $this->db->execute("UPDATE users SET email = NULL WHERE name = 'Tom'");

        $count = $this->db->table('users')->count('email');

        $this->assertSame(2, $count); // Only non-null emails
    }

    public function testExists(): void
    {
        $exists = $this->db->table('users')->where('name', 'Max')->exists();

        $this->assertTrue($exists);
    }

    public function testExistsFalse(): void
    {
        $exists = $this->db->table('users')->where('name', 'Unknown')->exists();

        $this->assertFalse($exists);
    }

    public function testSum(): void
    {
        $sum = $this->db->table('users')->sum('age');

        $this->assertEquals(75, $sum); // 25 + 30 + 20
    }

    public function testAvg(): void
    {
        $avg = $this->db->table('users')->avg('age');

        $this->assertEquals(25, $avg);
    }

    public function testMin(): void
    {
        $min = $this->db->table('users')->min('age');

        $this->assertEquals(20, $min);
    }

    public function testMax(): void
    {
        $max = $this->db->table('users')->max('age');

        $this->assertEquals(30, $max);
    }

    // =========================================================================
    // GROUP BY, HAVING
    // =========================================================================

    public function testGroupBy(): void
    {
        $results = $this->db->table('users')
            ->select('active')
            ->groupBy('active')
            ->get();

        $this->assertCount(2, $results);
    }

    public function testHaving(): void
    {
        $results = $this->db->table('users')
            ->select('active')
            ->groupBy('active')
            ->having('active', '=', 1)
            ->get();

        $this->assertCount(1, $results);
    }

    // =========================================================================
    // INSERT, UPDATE, DELETE via Builder
    // =========================================================================

    public function testInsertViaBuilder(): void
    {
        $id = $this->db->table('users')->insert([
            'name' => 'New User',
            'email' => 'new@example.com',
            'age' => 40,
        ]);

        $this->assertSame('4', $id);
    }

    public function testUpdateViaBuilder(): void
    {
        $affected = $this->db->table('users')
            ->where('id', 1)
            ->update(['name' => 'Maximilian']);

        $this->assertSame(1, $affected);

        $user = $this->db->findOne('users', ['id' => 1]);
        $this->assertSame('Maximilian', $user['name']);
    }

    public function testUpdateWithoutWhereThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->table('users')->update(['name' => 'Test']);
    }

    public function testDeleteViaBuilder(): void
    {
        $affected = $this->db->table('users')
            ->where('id', 3)
            ->delete();

        $this->assertSame(1, $affected);

        $count = $this->db->table('users')->count();
        $this->assertSame(2, $count);
    }

    public function testDeleteWithoutWhereThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->table('users')->delete();
    }

    // =========================================================================
    // toSql DEBUG
    // =========================================================================

    public function testToSqlReturnsArrayWithSqlAndParams(): void
    {
        [$sql, $params] = $this->db->table('users')
            ->where('id', 5)
            ->toSql();

        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertSame([5], $params);
    }

    public function testToSqlComplexQuery(): void
    {
        [$sql, $params] = $this->db->table('users')
            ->select('name, email')
            ->where('active', 1)
            ->whereIn('age', [25, 30])
            ->orderBy('name')
            ->limit(10)
            ->toSql();

        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertSame([1, 25, 30], $params);
    }
}
