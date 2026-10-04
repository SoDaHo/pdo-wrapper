<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Tests\Support\Untyped;

/**
 * Edge case tests for bugs found by code review, as far as the builder alone decides them: the
 * SQL it renders and the input it rejects, without a database. The builder is the one the
 * MariaDB driver creates (backticks); it reaches its database only to execute,
 * which no test here does. The edge cases that run against a database are in
 * tests/Contract/EdgeCases.
 */
class EdgeCaseRenderingTest extends TestCase
{
    /** What a driver's table() builds, on a connection that is never opened */
    private function table(string $table): QueryBuilder
    {
        $db = new class () extends AbstractDriver {
        };

        return $db->table($table);
    }

    // =========================================================================
    // PARAMETER ORDER BUG FIX TEST
    // Bug: having() before where() caused parameters to be bound in wrong order
    // =========================================================================

    public function testParameterOrderWithHavingBeforeWhere(): void
    {
        // Build query with having() called before where()
        [$sql, $params] = $this->table('posts')
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
        // Complex query with multiple conditions
        [$sql, $params] = $this->table('posts')
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
        // Test that wildcard in array is not quoted
        [$sql, ] = $this->table('users')
            ->select(['id', '*'])
            ->toSql();

        // Wildcard should not be quoted as "*"
        $this->assertStringContainsString('`id`, *', $sql);
        $this->assertStringNotContainsString('`*`', $sql);
    }

    // =========================================================================
    // DIRECT QUERY BUILDER SCHEMA QUOTING TESTS
    // These test the QueryBuilder's quoteIdentifier directly
    // =========================================================================

    public function testQueryBuilderToSqlWithDottedColumns(): void
    {
        [$sql, $params] = $this->table('users')
            ->select(['users.id', 'users.name'])
            ->where('users.active', 1)
            ->toSql();

        // Verify the SQL contains properly quoted identifiers
        $this->assertStringContainsString('`users`.`id`', $sql);
        $this->assertStringContainsString('`users`.`name`', $sql);
        $this->assertStringContainsString('`users`.`active`', $sql);
    }

    public function testWhereWithThreeArgumentsValidatesOperatorAndRejectsNull(): void
    {
        try {
            $this->table('users')->where('name', '=', null);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertStringContainsString('whereNull', $e->getDebugMessage() ?? '');
        }

        try {
            $this->table('users')->where('name', 'A', null);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Invalid operator "A"', $e->getDebugMessage() ?? '');
        }
    }

    // =========================================================================
    // WHERE NULL BUG FIX TEST
    // Bug: where('column', null) generated "column = NULL" which is always false
    // in SQL. Users must use whereNull()/whereNotNull() instead.
    // =========================================================================

    public function testWhereTwoArgNullThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->table('users')->where('status', null);
    }

    public function testWhereThreeArgNullThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->table('users')->where('status', '=', null);
    }

    public function testWhereArraySyntaxNullThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->table('users')->where(['status' => null]);
    }

    public function testWhereNullExceptionSuggestsWhereNull(): void
    {
        try {
            $this->table('users')->where('status', null);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $debug = $e->getDebugMessage() ?? '';
            $this->assertStringContainsString('whereNull', $debug);
            $this->assertStringContainsString('status', $debug);
        }
    }

    public function testWhereThreeArgNullExceptionSuggestsWhereNull(): void
    {
        try {
            $this->table('users')->where('deleted_at', '=', null);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $debug = $e->getDebugMessage() ?? '';
            $this->assertStringContainsString('whereNull', $debug);
            $this->assertStringContainsString('deleted_at', $debug);
        }
    }

    public function testWhereInWithANullElementThrows(): void
    {
        foreach (['whereIn', 'whereNotIn'] as $method) {
            try {
                $this->table('users')->{$method}('status', ['active', null]);
                $this->fail("{$method}() with a null element must throw: NOT IN with NULL matches no row");
            } catch (QueryException $e) {
                $this->assertSame(
                    sprintf('Cannot use a null element in %s() for column "status". Add whereNull() or whereNotNull() for it.', $method),
                    $e->getDebugMessage()
                );
            }
        }

        [$sql, $params] = $this->table('users')->whereIn('status', ['active', Database::raw('NULL')])->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `status` IN (?, NULL)', $sql, 'a raw element is the caller\'s own SQL');
        $this->assertSame(['active'], $params);
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

    // =========================================================================
    // SILENTLY ACCEPTED INPUT
    // Bug: a negative limit()/offset(), a null in having()/whereBetween() and a list given to
    // where() were accepted and returned wrong rows (or a TypeError) instead of a QueryException
    // =========================================================================

    public function testWhereBetweenWithANullBoundThrows(): void
    {
        foreach ([[null, 5], [1, null], ['min' => 1, 'max' => null]] as $values) {
            foreach (['whereBetween', 'whereNotBetween'] as $method) {
                try {
                    $this->table('users')->{$method}('age', $values);
                    $this->fail("{$method}() with a null bound must throw: BETWEEN with NULL matches no row");
                } catch (QueryException $e) {
                    $this->assertSame(
                        sprintf('Cannot use a null bound in %s() for column "age". Use where() with a comparison operator for an open range.', $method),
                        $e->getDebugMessage()
                    );
                }
            }
        }

        [$sql, $params] = $this->table('users')->whereBetween('age', [0, Database::raw('18 + 0')])->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `age` BETWEEN ? AND 18 + 0', $sql);
        $this->assertSame([0], $params);
    }

    public function testWhereArrayWithNumericKeysThrows(): void
    {
        try {
            Untyped::call($this->table('users')->where(...), ['active', 1]); // a list is no array<string, mixed>
            $this->fail('A list given to where() must throw');
        } catch (QueryException $e) {
            $this->assertSame(
                'where() with an array needs column names as keys, got the numeric key 0. Use where(\'column\', $value) instead.',
                $e->getDebugMessage()
            );
        }

        // PHP turns the key '2024' into the integer 2024: a numeric column name needs the two-argument form
        try {
            Untyped::call($this->table('users')->where(...), ['name' => 'x', '2024' => 1]);
            $this->fail('A numeric key in where() must throw');
        } catch (QueryException $e) {
            $this->assertStringContainsString('got the numeric key 2024', (string) $e->getDebugMessage());
        }

        [$sql] = $this->table('stats')->where('2024', 1)->toSql();
        $this->assertSame('SELECT * FROM `stats` WHERE `2024` = ?', $sql);
    }
}
