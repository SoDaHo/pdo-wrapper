<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * Aggregates combined with distinct() and groupBy(): count() counts distinct rows or groups,
 * sum()/avg()/min()/max() respect distinct() and refuse groupBy().
 */
class AggregateTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // "note*" (always NULL) is there for testDistinctCountOverAJoinNeedsNamedColumns(), which
        // added it with ALTER TABLE: a test writes no DDL
        $this->create('users', ['id' => 'id', 'country' => 'text', 'status' => 'text', 'score' => 'int', 'note*' => 'text']);
        // scores 10, 20, 20, 30, 10: sum 90 vs. SUM(DISTINCT) 60, avg 18 vs. AVG(DISTINCT) 20
        foreach ([['IS', 'active', 10], ['DE', 'active', 20], ['DE', 'inactive', 20], ['AT', 'active', 30], ['AT', 'active', 10]] as [$country, $status, $score]) {
            $this->db->insert('users', ['country' => $country, 'status' => $status, 'score' => $score]);
        }
        $this->create('orders', ['id' => 'id', 'user_id' => 'int', 'status' => 'text']);
        $this->db->insert('orders', ['user_id' => 1, 'status' => 'paid']);
        $this->db->insert('orders', ['user_id' => 1, 'status' => 'open']);
        $this->db->insert('orders', ['user_id' => 2, 'status' => 'paid']);
    }

    /**
     * having() without groupBy() makes the whole set one group: when it filters that group out,
     * the aggregate has no row - no value, and count() 0.
     */
    public function testAHavingThatFiltersTheOneGroupGivesNoValue(): void
    {
        $filtered = fn (): QueryBuilder => $this->db->table('users')->having(Database::raw('COUNT(*)'), '>', Database::raw('5'));

        $this->assertNull($filtered()->sum('score'));
        $this->assertNull($filtered()->avg('score'));
        $this->assertNull($filtered()->min('score'));
        $this->assertNull($filtered()->max('score'));
        $this->assertSame(0, $filtered()->count());
        $this->assertNotNull($this->db->table('users')->having(Database::raw('COUNT(*)'), '>', Database::raw('4'))->sum('score'), 'the one group of five rows stays');
    }

    public function testCountWithGroupByKeepsAliasedSelectEntries(): void
    {
        // having() on a select alias and groupBy() on a select alias need the alias in the inner select
        $this->assertSame(2, $this->db->table('users')->select([Database::raw('COUNT(*) AS n')])->groupBy('country')->having('n', '>', Database::raw('1'))->count());
        $this->assertSame(2, $this->db->table('users')->select(['country', 'status', Database::raw('COUNT(*) AS n')])->groupBy('country')->having('n', '>', Database::raw('1'))->count(), 'plain columns are dropped, the alias stays');
        $this->assertSame(3, $this->db->table('users')->select([Database::raw('LOWER(country) AS c')])->groupBy('c')->count());
        $this->assertSame(3, $this->db->table('users')->select(['country as c'])->groupBy('c')->count(), 'string alias (a column, string entries are identifiers)');
        $this->assertSame(3, $this->db->table('users')->select(['users.*'])->groupBy('country')->count(), 'wildcards are dropped');
        $this->assertSame(3, $this->db->table('users')->select([Database::raw('COUNT(*)'), Database::raw('COUNT(*)')])->groupBy('country')->count(), 'unaliased raw entries are dropped (they would repeat a column name)');
        $this->assertSame(2, $this->db->table('users')->select([Database::raw('COUNT(*) AS n'), Database::raw('MAX(score) AS N')])->groupBy('country')->having('n', '>', Database::raw('1'))->count(), 'one entry per alias');
        // an alias in the database's own quote characters counts too: tests/Driver
        $this->assertSame(2, $this->db->table('users')->select([Database::raw('COUNT(*) AS "n"')])->groupBy('country')->having('n', '>', Database::raw('1'))->count(), 'a quoted alias counts as an alias');
        $this->assertTrue($this->db->table('users')->select([Database::raw('COUNT(*) AS n')])->groupBy('country')->having('n', '>', Database::raw('1'))->exists());
    }

    public function testGroupedExistsKeepsAliasedSelectEntriesForHaving(): void
    {
        $grouped = fn (string $min) => $this->db->table('users')->select(['country', Database::raw('COUNT(*) AS n')])->groupBy('country')->having('n', '>', Database::raw($min));

        $this->assertTrue($grouped('1')->exists(), 'DE and AT have two rows');
        $this->assertFalse($grouped('5')->exists());
        $this->assertTrue($this->db->table('users')->groupBy('country')->exists(), 'no alias: SELECT 1 per group');
        $this->assertFalse($this->db->table('users')->select([Database::raw('COUNT(*) AS n')])->where('id', 999)->exists(), 'without groupBy() the aggregate alias is not kept: no row, not one row with n = 0');
    }

    public function testDistinctCountOverAJoinNeedsNamedColumns(): void
    {
        $join = fn () => $this->db->table('users')->join('orders', 'users.id', '=', 'orders.user_id');

        $this->assertSame(2, $join()->select(['users.country'])->distinct()->count(), 'IS and DE have orders');
        $this->assertSame(2, $join()->select(['users.*'])->distinct()->count(), 'one wildcard: users 1 and 2');
        $this->assertSame(2, $join()->select(['users.country', 'note*'])->distinct()->count(), 'a column named with a trailing star is not a wildcard');
        $this->assertSame(2, $join()->distinct()->count('orders.status'));
        $this->assertSame(3, $join()->groupBy(['users.id', 'orders.id'])->count(), 'groups over a join with clashing names');
        $this->assertSame(3, $join()->select(['users.*', 'orders.*'])->groupBy(['users.id', 'orders.id'])->count(), 'wildcards are dropped from the grouped select');

        foreach ([['*'], [3 => '*'], ['users.*', 'orders.*'], ['*', 'orders.status'], ['users.*', 'users.id'], ['users.id', 'orders.id'], ['users.id', 'orders.user_id as id']] as $columns) {
            try {
                $join()->select($columns)->distinct()->count();
                $this->fail('Expected QueryException was not thrown for ' . implode(', ', $columns));
            } catch (QueryException $e) {
                $this->assertStringContainsString('needs unique output names', $e->getDebugMessage() ?? '');
            }
        }
        $this->assertSame(3, $join()->select(['users.id', 'orders.id as order_id'])->distinct()->count(), 'aliased apart: three user/order pairs');
        $this->assertSame(3, $join()->select('users.id, orders.id as order_id')->distinct()->count(), 'string form');
    }

    public function testDistinctCountWithoutAJoinStillNeedsUniqueOutputNames(): void
    {
        // 'ID' next to 'id': the database compares column names without case
        foreach ([['id', 'users.id'], ['id', 'ID'], ['users.*', 'score'], ['*', 'score']] as $columns) {
            try {
                $this->db->table('users')->select($columns)->distinct()->count();
                $this->fail('Expected QueryException was not thrown for ' . implode(', ', $columns));
            } catch (QueryException $e) {
                $this->assertStringContainsString('needs unique output names', $e->getDebugMessage() ?? '');
            }
        }
        $this->assertSame(5, $this->db->table('users')->select(['*'])->distinct()->count(), 'a bare "*" without a join is fine');
        $this->assertSame(5, $this->db->table('users')->select(['users.*'])->distinct()->count());
        $this->assertSame(4, $this->db->table('users')->select([Database::raw('LOWER(country) AS c'), 'status'])->distinct()->count(), 'raw entries are not inspected: is/de/de-inactive/at');
    }

    /**
     * Regression test: distinct()->count() rendered SELECT DISTINCT COUNT(*) and counted every row.
     */
    public function testCountWithDistinctCountsDistinctRows(): void
    {
        $this->assertSame(3, $this->db->table('users')->select('country')->distinct()->count());
        $this->assertSame(5, $this->db->table('users')->distinct()->count(), 'all rows differ by id');
        $this->assertSame(3, $this->db->table('users')->distinct()->count('country'), 'COUNT(DISTINCT country)');
        $this->assertSame(3, $this->db->table('users')->where('status', 'active')->select('score')->distinct()->count('score'), 'active scores 10, 20, 30');
        $this->assertSame(1, $this->db->table('users')->where('country', 'DE')->distinct()->count('score'), 'both DE rows have the score 20');
        // distinct() with having() but no groupBy(): the whole set is one group, counted as before
        $this->assertSame(5, $this->db->table('users')->distinct()->having(Database::raw('COUNT(*)'), '>', Database::raw('0'))->count());
        $this->assertTrue($this->db->table('users')->distinct()->having(Database::raw('COUNT(*)'), '>', Database::raw('0'))->exists());
        $this->assertSame(3, $this->db->table('users')->distinct()->having(Database::raw('COUNT(*)'), '>', Database::raw('0'))->count('country'), 'COUNT(DISTINCT country) still applies to that one group');
    }

    /**
     * Regression test: groupBy()->count() returned the first group's count instead of the number of groups.
     */
    public function testCountWithGroupByCountsTheGroups(): void
    {
        $this->assertSame(3, $this->db->table('users')->groupBy('country')->count());
        $this->assertSame(2, $this->db->table('users')->groupBy('country')->having(Database::raw('COUNT(*)'), '>', Database::raw('1'))->count(), 'DE and AT have two rows');
        $this->assertSame(3, $this->db->table('users')->select(['country', 'status'])->where('status', 'active')->groupBy(['country', 'status'])->count());
        $this->assertSame(0, $this->db->table('users')->where('id', 999)->groupBy('country')->count());
        // the projection does not matter: three countries, even though select('status')->distinct() would collapse to two rows
        $this->assertSame(3, $this->db->table('users')->select('status')->distinct()->groupBy('country')->count());
        $this->assertSame(3, $this->db->table('users')->groupBy('country')->count('status'), 'the column argument is irrelevant with groupBy()');
    }

    /**
     * sum() and avg() hand on what the database delivers: an integer, a float or an exact numeric
     * string, as the binding says.
     */
    public function testOtherAggregatesRespectDistinctAndRefuseGroupBy(): void
    {
        $binding = self::binding();

        $this->assertSame($binding->deliveredIntSum(90), $this->db->table('users')->sum('score'));
        $this->assertSame($binding->deliveredIntSum(60), $this->db->table('users')->distinct()->sum('score'), 'SUM(DISTINCT score): 10 + 20 + 30');
        $this->assertSame($binding->deliveredIntAvg(18), $this->db->table('users')->avg('score'));
        $this->assertSame($binding->deliveredIntAvg(20), $this->db->table('users')->distinct()->avg('score'), 'AVG(DISTINCT score)');

        try {
            $this->db->table('users')->groupBy('country')->sum('score');
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertStringContainsString('sum() with groupBy() is ambiguous', $e->getDebugMessage() ?? '');
        }
    }

    public function testAggregatesLeaveTheBuilderUntouched(): void
    {
        $builder = $this->db->table('users')->select('country')->distinct()->orderBy('country')->limit(2);
        $before = $builder->toSql();
        $builder->count();

        $this->assertSame($before, $builder->toSql());
        $this->assertSame(['AT', 'DE'], array_column($builder->get(), 'country'), 'DISTINCT, ORDER BY and LIMIT still apply');
    }
}
