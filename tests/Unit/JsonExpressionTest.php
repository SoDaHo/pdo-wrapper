<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Query\RawExpression;

/**
 * Database::json(): the expression it renders, the paths and aliases it refuses, and where the
 * builder takes it - every where*() method, select(), groupBy(), orderBy(), having().
 */
class JsonExpressionTest extends TestCase
{
    /** A builder on a driver without a connection: toSql() needs none */
    private function builder(string $table = 'events'): QueryBuilder
    {
        $db = new class () extends AbstractDriver {
        };

        return $db->table($table);
    }

    public function testTheExpressionReadsTheValueAsText(): void
    {
        $this->assertSame("JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net'))", (string) Database::json('payload', '$.net'));
        $this->assertSame("JSON_UNQUOTE(JSON_EXTRACT(`events`.`payload`, '$.items[0].id'))", (string) Database::json('events.payload', '$.items[0].id'));
        $this->assertSame("JSON_UNQUOTE(JSON_EXTRACT(`pay``load`, '$'))", (string) Database::json('pay`load', '$'), 'a backtick in the name is doubled');
        $this->assertSame("JSON_UNQUOTE(JSON_EXTRACT(`p`, '$[12][0]._x.A9'))", (string) Database::json('p', '$[12][0]._x.A9'));
        $this->assertInstanceOf(RawExpression::class, Database::json('p', '$'));
        $this->assertSame([], Database::json('p', '$.a')->bindings, 'the path is written into the SQL, nothing is bound');
    }

    public function testOrColumnFallsBackToColumnsInOrder(): void
    {
        $json = Database::json('payload', '$.net');

        $this->assertSame("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')), `ip`)", (string) $json->orColumn('ip'));
        $this->assertSame("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')), `ip`, `t`.`host`)", (string) $json->orColumn('ip')->orColumn('t.host'));
        $this->assertSame("JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net'))", (string) $json, 'orColumn() returns a new expression');
    }

    public function testAsNamesTheExpressionForSelect(): void
    {
        $this->assertSame("JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')) AS `net`", (string) Database::json('payload', '$.net')->as('net'));
        $this->assertSame("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')), `ip`) AS `Net_2`", (string) Database::json('payload', '$.net')->orColumn('ip')->as('Net_2'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathsOfAnotherForm(): array
    {
        return [
            'empty' => [''],
            'no $' => ['net'],
            'a quote' => ["$.a'"],
            'a quoted key' => ['$."a b"'],
            'a wildcard' => ['$.*'],
            'any depth' => ['$**.a'],
            'a name starting with a digit' => ['$.1a'],
            'a negative index' => ['$[-1]'],
            'an open bracket' => ['$.a['],
            'a trailing dot' => ['$.a.'],
            'text after an index' => ['$[0]x'],
            'a space' => ['$. a'],
            'a newline at the end' => ["$.a\n"],
            'a last index' => ['$[last]'],
        ];
    }

    #[DataProvider('pathsOfAnotherForm')]
    public function testAPathOfAnotherFormIsRefused(string $path): void
    {
        try {
            Database::json('payload', $path);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
            $this->assertSame(sprintf('Invalid JSON path "%s": write $ followed by .name and [n] steps (a name starts with a letter or an underscore and holds letters, digits and underscores)', $path), $e->getDebugMessage());
        }
    }

    public function testAnAliasOfAnotherFormIsRefused(): void
    {
        foreach (['', 'a b', 'a`b', "a\n", 'net.x'] as $alias) {
            try {
                Database::json('payload', '$.net')->as($alias);
                $this->fail('Expected QueryException: ' . var_export($alias, true));
            } catch (QueryException $e) {
                $this->assertSame(sprintf('Invalid alias "%s" for a JSON value: use letters, digits and underscores', $alias), $e->getDebugMessage());
            }
        }
    }

    /**
     * Every where*() method takes an expression as its column and renders it as it is; the
     * values are bound as usual.
     */
    public function testEveryWhereMethodTakesTheExpressionAsColumn(): void
    {
        $net = Database::json('payload', '$.net');
        $sql = static fn (QueryBuilder $builder): string => $builder->toSql()[0];

        $this->assertSame("SELECT * FROM `events` WHERE JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')) = ?", $sql($this->builder()->where($net, 'a')));
        $this->assertSame("SELECT * FROM `events` WHERE JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')) <> ?", $sql($this->builder()->where($net, '<>', 'a')));
        $this->assertSame("SELECT * FROM `events` WHERE JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')) IN (?, ?)", $sql($this->builder()->whereIn($net, ['a', 'b'])));
        $this->assertSame("SELECT * FROM `events` WHERE JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')) NOT IN (?)", $sql($this->builder()->whereNotIn($net, ['a'])));
        $this->assertSame("SELECT * FROM `events` WHERE JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')) BETWEEN ? AND ?", $sql($this->builder()->whereBetween($net, ['a', 'c'])));
        $this->assertSame("SELECT * FROM `events` WHERE JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')) NOT BETWEEN ? AND ?", $sql($this->builder()->whereNotBetween($net, ['a', 'c'])));
        $this->assertSame("SELECT * FROM `events` WHERE JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')) IS NULL", $sql($this->builder()->whereNull($net)));
        $this->assertSame("SELECT * FROM `events` WHERE JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')) IS NOT NULL", $sql($this->builder()->whereNotNull($net)));
        $this->assertSame("SELECT * FROM `events` WHERE JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')) LIKE ? ESCAPE ?", $sql($this->builder()->whereLike($net, 'a%')));
        $this->assertSame("SELECT * FROM `events` WHERE JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')) NOT LIKE ? ESCAPE ?", $sql($this->builder()->whereNotLike($net, 'a%')));
        $this->assertSame('SELECT * FROM `events` WHERE LOWER(email) = ?', $sql($this->builder()->where(Database::raw('LOWER(email)'), 'x')), 'any expression without bindings');
        $this->assertSame(['a', 'c', 1], $this->builder()->whereBetween($net, ['a', 'c'])->where('id', 1)->toSql()[1]);
    }

    /**
     * select() takes it named with as(), groupBy() and orderBy() as it is; having() refers to the
     * alias (MariaDB takes the JSON column inside the expression there for unknown).
     */
    public function testSelectGroupByAndOrderByTakeTheExpression(): void
    {
        $net = Database::json('payload', '$.net')->orColumn('ip');

        [$sql] = $this->builder()
            ->select([$net->as('net'), Database::raw('COUNT(*) AS n')])
            ->groupBy($net)
            ->having('net', '<>', 'x')
            ->orderBy($net, 'DESC')
            ->toSql();

        $this->assertSame(
            "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')), `ip`) AS `net`, COUNT(*) AS n FROM `events` "
            . "GROUP BY COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')), `ip`) "
            . 'HAVING `net` <> ? '
            . "ORDER BY COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net')), `ip`) DESC",
            $sql
        );
    }

    /**
     * An expression with bindings has no place as the column of a condition: its values would
     * have to stand before the condition's own.
     */
    public function testAColumnExpressionWithBindingsIsRefused(): void
    {
        $bound = Database::raw('LOWER(?)', ['X']);
        foreach ([
            'where' => fn (): QueryBuilder => $this->builder()->where($bound, 'x'),
            'whereIn' => fn (): QueryBuilder => $this->builder()->whereIn($bound, ['x']),
            'whereNotIn' => fn (): QueryBuilder => $this->builder()->whereNotIn($bound, ['x']),
            'whereBetween' => fn (): QueryBuilder => $this->builder()->whereBetween($bound, ['a', 'b']),
            'whereNotBetween' => fn (): QueryBuilder => $this->builder()->whereNotBetween($bound, ['a', 'b']),
            'whereNull' => fn (): QueryBuilder => $this->builder()->whereNull($bound),
            'whereNotNull' => fn (): QueryBuilder => $this->builder()->whereNotNull($bound),
            'whereLike' => fn (): QueryBuilder => $this->builder()->whereLike($bound, 'x'),
            'whereNotLike' => fn (): QueryBuilder => $this->builder()->whereNotLike($bound, 'x'),
        ] as $method => $call) {
            try {
                $call();
                $this->fail('Expected QueryException: ' . $method);
            } catch (QueryException $e) {
                $this->assertSame(sprintf('A raw expression with bindings is only accepted as a value (insert()/update() data, where(), whereIn(), whereBetween(), the value of having()), not in the column of %s(). Use whereRaw() for a condition, or query() for the whole statement.', $method), $e->getDebugMessage());
            }
        }
    }

    /**
     * The messages of the null guards name the expression as its SQL.
     */
    public function testTheNullGuardsNameTheExpression(): void
    {
        $net = Database::json('payload', '$.net');
        foreach ([
            fn (): QueryBuilder => $this->builder()->where($net, null),
            fn (): QueryBuilder => $this->builder()->whereIn($net, [null]),
            fn (): QueryBuilder => $this->builder()->whereBetween($net, [null, 'b']),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected QueryException');
            } catch (QueryException $e) {
                $this->assertStringContainsString("JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net'))", (string) $e->getDebugMessage());
            }
        }
    }
}
