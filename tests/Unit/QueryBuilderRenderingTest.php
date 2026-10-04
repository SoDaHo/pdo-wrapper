<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;

/**
 * The SQL the builder renders, without executing it: quoting and aliases, the null-safe IS / IS NOT,
 * OFFSET without LIMIT, row locks and what they cannot be combined with.
 */
class QueryBuilderRenderingTest extends TestCase
{
    /** A builder on a driver without a connection: toSql() needs none */
    private function builder(string $table = 'users'): QueryBuilder
    {
        $db = new class () extends AbstractDriver {
        };

        return $db->table($table);
    }

    /**
     * The alias of a column or a table is quoted, like the name it stands for.
     */
    public function testAliasesAreQuoted(): void
    {
        [$sql] = $this->builder('users as U')
            ->select(['U.name as UserName', 'id  AS  order'])
            ->join('posts as P', 'P.user_id', '=', 'U.id')
            ->orderBy('UserName')
            ->toSql();

        $this->assertSame('SELECT `U`.`name` as `UserName`, `id` as `order` FROM `users` as `U` INNER JOIN `posts` as `P` ON `P`.`user_id` = `U`.`id` ORDER BY `UserName` ASC', $sql);
    }

    public function testIsAndIsNotAreNullSafeEquality(): void
    {
        [$sql, $params] = $this->builder()->where('name', 'IS', 'x')->where('role', 'IS NOT', 'y')->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `name` <=> ? AND NOT (`role` <=> ?)', $sql);
        $this->assertSame(['x', 'y'], $params);
    }

    /**
     * A raw right side keeps IS / IS NOT verbatim: truth tests like `flag IS TRUE` were valid before.
     */
    public function testIsWithARawValueIsPassedThroughUnchanged(): void
    {
        [$sql, $params] = $this->builder()
            ->where('flag', 'IS', Database::raw('TRUE'))
            ->where('deleted', 'IS NOT', Database::raw('NULL'))
            ->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `flag` IS TRUE AND `deleted` IS NOT NULL', $sql);
        $this->assertSame([], $params);

        [$sql] = $this->builder()->groupBy('flag')->having('flag', 'IS', Database::raw('UNKNOWN'))->toSql();
        $this->assertSame('SELECT * FROM `users` GROUP BY `flag` HAVING `flag` IS UNKNOWN', $sql);
    }

    public function testIsInJoinAndHavingUsesTheSameRendering(): void
    {
        [$sql, $params] = $this->builder()
            ->join('profiles', 'users.id', 'IS', 'profiles.user_id')
            ->groupBy('name')
            ->having('name', 'IS NOT', 'x')
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `users` INNER JOIN `profiles` ON `users`.`id` <=> `profiles`.`user_id` GROUP BY `name` HAVING NOT (`name` <=> ?)',
            $sql
        );
        $this->assertSame(['x'], $params);
    }

    public function testWhereAcceptsNamedArguments(): void
    {
        [$sql, $params] = $this->builder()->where(column: 'id', value: 5)->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ?', $sql);
        $this->assertSame([5], $params);

        [$sql, $params] = $this->builder()->where(column: 'age', operatorOrValue: '>', value: 18)->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `age` > ?', $sql);
        $this->assertSame([18], $params);
    }

    public function testOffsetWithoutLimitGetsTheLargestLimit(): void
    {
        [$sql] = $this->builder()->offset(5)->toSql();
        $this->assertSame('SELECT * FROM `users` LIMIT 18446744073709551615 OFFSET 5', $sql);

        [$sql] = $this->builder()->limit(10)->offset(5)->toSql();
        $this->assertSame('SELECT * FROM `users` LIMIT 10 OFFSET 5', $sql);
    }

    public function testRowLocks(): void
    {
        [$sql] = $this->builder()->where('id', 1)->limit(1)->lockForUpdate()->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ? LIMIT 1 FOR UPDATE', $sql);

        [$sql] = $this->builder()->sharedLock()->toSql();
        $this->assertSame('SELECT * FROM `users` LOCK IN SHARE MODE', $sql);
    }

    /**
     * The lock prohibition holds for both lock kinds and all grouping clauses.
     */
    public function testLocksRejectDistinctGroupByAndHaving(): void
    {
        $cases = [
            'distinct() and sharedLock()' => static fn (QueryBuilder $q): QueryBuilder => $q->distinct()->sharedLock(),
            'having() and lockForUpdate()' => static fn (QueryBuilder $q): QueryBuilder => $q->having(Database::raw('COUNT(*)'), '>', 1)->lockForUpdate(),
            'groupBy() and lockForUpdate()' => static fn (QueryBuilder $q): QueryBuilder => $q->groupBy('role')->lockForUpdate(),
            'groupBy() and sharedLock()' => static fn (QueryBuilder $q): QueryBuilder => $q->groupBy('role')->sharedLock(),
        ];

        foreach ($cases as $case => $configure) {
            try {
                $configure($this->builder())->toSql();
                $this->fail("Expected QueryException for {$case}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('cannot be combined with distinct(), groupBy() or having()', $e->getDebugMessage() ?? '', $case);
            }
        }
    }

    /**
     * An orderBy() direction that is not ASC/DESC (case and surrounding whitespace aside) is refused
     * by orderBy() itself; selects tolerate case and whitespace.
     */
    public function testOrderByRefusesAnUnknownDirection(): void
    {
        foreach (['DESCENDING', 'down', 'DESC NULLS LAST', '', 'ASC;'] as $direction) {
            try {
                $this->builder()->orderBy('id', $direction);
                $this->fail("Expected QueryException for direction '{$direction}'");
            } catch (QueryException $e) {
                $this->assertSame('Query failed', $e->getMessage());
                $this->assertSame(sprintf('Invalid orderBy() direction "%s" for "id". Allowed: ASC, DESC', $direction), $e->getDebugMessage());
            }
        }

        $this->assertSame('SELECT * FROM `users` ORDER BY `id` DESC', $this->builder()->orderBy('id', ' Desc ')->toSql()[0]);
    }
}
