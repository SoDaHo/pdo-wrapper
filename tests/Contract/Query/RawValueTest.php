<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\RawExpression;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * Database::raw() as a value: the expression is inlined into the SQL instead of being bound as text.
 * Covers the driver helpers, the query builder and the driver's now()/utcNow() expressions.
 *
 * Strings are joined with CONCAT(), not with the standard "||": an engine may read "||" as a
 * logical OR (as the default SQL mode of some does), CONCAT() joins strings on every engine.
 */
class RawValueTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create('counters', ['id' => 'id', 'name' => 'text', 'hits' => 'int NOT NULL DEFAULT 0', 'seen_at' => 'text']);
        $this->db->insert('counters', ['name' => 'a', 'hits' => 1]);
        $this->db->insert('counters', ['name' => 'b', 'hits' => 5]);
    }

    public function testBuilderUpdateInlinesRawValue(): void
    {
        $affected = $this->db->table('counters')->where('name', 'a')->update(['hits' => Database::raw('hits + 1')]);

        $this->assertSame(1, $affected);
        $this->assertSame(2, $this->db->findOne('counters', ['name' => 'a'])['hits'] ?? null);
    }

    public function testBuilderWhereInlinesRawValueAndBindsTheRest(): void
    {
        [, $params] = $this->db->table('counters')
            ->where('hits', '>', Database::raw('1 + 1'))
            ->where('name', 'b')
            ->toSql();

        $this->assertSame(['b'], $params);
        $this->assertCount(1, $this->db->table('counters')->where('hits', '>', Database::raw('1 + 1'))->get());
    }

    public function testBuilderWhereInAndWhereBetweenInlineRawValues(): void
    {
        [, $params] = $this->db->table('counters')
            ->whereIn('hits', [1, Database::raw('2 + 3'), 9])
            ->whereBetween('hits', [Database::raw('0'), 7])
            ->toSql();

        $this->assertSame([1, 9, 7], $params);
        $this->assertSame(2, $this->db->table('counters')->whereIn('hits', [1, Database::raw('2 + 3'), 9])->count());
        $this->assertSame(1, $this->db->table('counters')->whereBetween('hits', [Database::raw('2'), 9])->count());
    }

    /**
     * Regression test: string keys in whereIn()/whereBetween() values were renumbered (or read as
     * index 0/1 and became NULL), so whereBetween(['min' => 1, 'max' => 5]) silently matched nothing.
     */
    public function testBuilderWhereInAndWhereBetweenAcceptStringKeys(): void
    {
        $this->assertSame(2, $this->db->table('counters')->whereBetween('hits', ['min' => 1, 'max' => 5])->count());
        $this->assertSame(2, $this->db->table('counters')->whereIn('hits', ['a' => 1, 'b' => 5])->count());
    }

    /**
     * Bound params must keep their SQL order when raw values are mixed in: SET before WHERE, WHERE before HAVING.
     */
    public function testMixedRawAndBoundValuesKeepParameterOrder(): void
    {
        [, $params] = $this->db->table('counters')
            ->select(['name', Database::raw('SUM(hits) AS total')])
            ->where('hits', '>', Database::raw('0'))
            ->where('name', 'b')
            ->groupBy('name')
            ->having(Database::raw('SUM(hits)'), '>=', 5)
            ->toSql();
        $this->assertSame(['b', 5], $params);

        $affected = $this->db->update('counters', ['hits' => Database::raw('hits + 1'), 'name' => 'z'], ['id' => 2]);
        $this->assertSame(1, $affected);
        $this->assertSame(['id' => 2, 'name' => 'z', 'hits' => 6, 'seen_at' => null], $this->db->findOne('counters', ['id' => 2]));

        $affected = $this->db->table('counters')->where('name', 'z')->update(['hits' => Database::raw('hits * 2'), 'name' => 'y']);
        $this->assertSame(1, $affected);
        $this->assertSame(12, $this->db->findOne('counters', ['name' => 'y'])['hits'] ?? null);
    }

    public function testRawLikePatternKeepsTheEscapeClause(): void
    {
        [, $params] = $this->db->table('counters')->whereLike('name', '100%')->toSql();
        $this->assertSame(['100%', '\\'], $params);

        [, $params] = $this->db->table('counters')->where('name', 'LIKE', Database::raw("CONCAT('a', '%')"))->toSql();
        $this->assertSame(['\\'], $params, 'the escape character is bound for a raw pattern too');
        $this->assertSame(1, $this->db->table('counters')->where('name', 'LIKE', Database::raw("CONCAT('a', '%')"))->count());
    }

    /**
     * The SUM() comes as the database delivers it: as the binding says.
     */
    public function testBuilderHavingInlinesRawValue(): void
    {
        $rows = $this->db->table('counters')
            ->select(['name', Database::raw('SUM(hits) AS total')])
            ->groupBy('name')
            ->having(Database::raw('SUM(hits)'), '>', Database::raw('2 + 2'))
            ->get();

        $this->assertSame([['name' => 'b', 'total' => self::binding()->deliveredIntSum(5)]], $rows);
    }

    /**
     * Consumers rely on raw() in select() lists staying byte-identical.
     */
    public function testRawInSelectIsUnchanged(): void
    {
        // The statement itself: tests/Unit/ContractQueryRenderingTest
        [, $params] = $this->db->table('counters')
            ->select([Database::raw('counters.*'), Database::raw('COUNT(*) AS n')])
            ->toSql();

        $this->assertSame([], $params);
    }

    // ---- a raw value with bindings of its own --------------------------------------------------

    /**
     * The statement the query hook sees: SQL and params as sent.
     *
     * @return array{string, array<int, mixed>}
     */
    private function sent(callable $run): array
    {
        $seen = [];
        $this->db->on('query', static function (array $data) use (&$seen): void {
            $seen[] = [$data['sql'], $data['params']];
        });
        $run();

        return $seen[0];
    }

    public function testBuilderUpdateBindsARawValuesBindingsWhereItStands(): void
    {
        [, $params] = $this->sent(fn () => $this->db->table('counters')->where('id', 1)->where('hits', '<', 100)->update([
            'name' => 'x',
            'hits' => Database::raw('hits + ? * ?', [10, 2]),
            'seen_at' => 'now',
        ]));

        $this->assertSame(['x', 10, 2, 'now', 1, 100], $params, "the raw value's bindings at its place, all of SET before WHERE");
        $this->assertSame(['id' => 1, 'name' => 'x', 'hits' => 21, 'seen_at' => 'now'], $this->db->findOne('counters', ['id' => 1]));
    }

    public function testBuilderWhereBindsARawValuesBindingsWhereItStands(): void
    {
        [, $params] = $this->db->table('counters')
            ->where('name', '!=', 'zzz')
            ->where('hits', '>', Database::raw('? + ?', [1, 1]))
            ->whereIn('hits', [1, Database::raw('? + 3', [2]), 9])
            ->whereBetween('hits', [Database::raw('?', [0]), Database::raw('? * 2', [4])])
            ->where('name', 'b')
            ->toSql();

        $this->assertSame(['zzz', 1, 1, 1, 2, 9, 0, 4, 'b'], $params);
        $this->assertSame(['b'], array_column($this->db->table('counters')
            ->where('hits', '>', Database::raw('? + ?', [1, 1]))
            ->whereIn('hits', [1, Database::raw('? + 3', [2]), 9])
            ->whereBetween('hits', [Database::raw('?', [0]), Database::raw('? * 2', [4])])
            ->get(), 'name'));
    }

    public function testARawLikePatternWithBindingsComesBeforeTheEscapeCharacter(): void
    {
        $query = $this->db->table('counters')->where('name', 'LIKE', Database::raw('CONCAT(?, ?)', ['a', '%']));
        [, $params] = $query->toSql();

        $this->assertSame(['a', '%', '\\'], $params);
        $this->assertSame(1, $query->count());
    }

    public function testBuilderHavingBindsARawValuesBindingsAfterTheWhereValues(): void
    {
        $query = $this->db->table('counters')
            ->select(['name', Database::raw('SUM(hits) AS total')])
            ->where('hits', '>', Database::raw('?', [0]))
            ->groupBy('name')
            ->having(Database::raw('SUM(hits)'), '>', Database::raw('? + ?', [2, 2]));
        [, $params] = $query->toSql();

        $this->assertSame([0, 2, 2], $params);
        $this->assertSame([['name' => 'b', 'total' => self::binding()->deliveredIntSum(5)]], $query->get(), 'the SUM() as the database delivers it');
    }

    public function testDriverHelpersBindARawValuesBindingsWhereItStands(): void
    {
        [, $params] = $this->sent(fn () => $this->db->insert('counters', ['name' => Database::raw('UPPER(?)', ['c']), 'hits' => 3, 'seen_at' => Database::raw('CONCAT(?, ?)', ['2026', '-01'])]));
        $this->assertSame(['c', 3, '2026', '-01'], $params);
        $this->assertSame(['id' => 3, 'name' => 'C', 'hits' => 3, 'seen_at' => '2026-01'], $this->db->findOne('counters', ['name' => 'C']));

        [, $params] = $this->sent(fn () => $this->db->update(
            'counters',
            ['hits' => Database::raw('hits + ?', [2]), 'seen_at' => 'then', 'name' => Database::raw('LOWER(?)', ['D'])],
            ['name' => Database::raw('UPPER(?)', ['c']), 'hits' => 3]
        ));
        $this->assertSame([2, 'then', 'D', 'c', 3], $params, 'the assignments in the order of the array, then the conditions');
        $this->assertSame(['id' => 3, 'name' => 'd', 'hits' => 5, 'seen_at' => 'then'], $this->db->findOne('counters', ['id' => 3]));

        $this->assertCount(1, $this->db->findAll('counters', ['hits' => Database::raw('? + ?', [2, 3]), 'name' => 'd']));
        $this->assertSame(1, $this->db->delete('counters', ['name' => Database::raw('LOWER(?)', ['D'])]));
        $this->assertSame(2, $this->db->table('counters')->count());
    }

    public function testInsertWhenAndInsertIgnoreBindARawValuesBindingsBeforeTheirOwn(): void
    {
        $data = ['name' => Database::raw('UPPER(?)', ['e']), 'hits' => 7];

        // The statement itself: tests/Driver
        [, $params] = $this->sent(fn () => $this->db->insertWhen('counters', $data, 'NOT EXISTS (SELECT 1 FROM counters WHERE name = ?)', ['E']));
        $this->assertSame(['e', 7, 'E'], $params, "the row's values in column order, then the condition's");
        $this->assertSame(0, $this->db->insertWhen('counters', $data, 'NOT EXISTS (SELECT 1 FROM counters WHERE name = ?)', ['E']));

        $this->assertSame(1, $this->db->insertIgnore('counters', ['id' => 9, 'name' => Database::raw('CONCAT(?, ?)', ['f', 'g']), 'hits' => 1]));
        $this->assertSame(0, $this->db->insertIgnore('counters', ['id' => 9, 'name' => Database::raw('CONCAT(?, ?)', ['x', 'y']), 'hits' => 1]));
        $this->assertSame('fg', $this->db->findOne('counters', ['id' => 9])['name'] ?? null);
    }

    /**
     * A value is rendered through __toString(), as before: a subclass that overrides it keeps
     * its say (and its bindings their place).
     */
    public function testASubclassThatOverridesToStringIsRenderedThroughIt(): void
    {
        $doubling = new class ('hits + ?', [41]) extends RawExpression {
            public function __toString(): string
            {
                return '(' . $this->value . ') * 2';
            }
        };

        [, $params] = $this->sent(fn () => $this->db->update('counters', ['hits' => $doubling], ['hits' => new class ('0') extends RawExpression {
            public function __toString(): string
            {
                return $this->value . ' + 1';
            }
        }]));
        // the whole statement: tests/Driver/MariaDb/Query/SentStatementsTest; here what it did -
        // only the WHERE override ("0 + 1") finds the row holding 1, and only the SET override
        // doubles: (1 + 41) * 2
        $this->assertSame([41], $params);
        $this->assertSame(84, $this->db->findOne('counters', ['name' => 'a'])['hits'] ?? null);
    }

    public function testARawExpressionWithBindingsIsRefusedWhereNoValueStands(): void
    {
        $bound = Database::raw('hits + ?', [1]);
        $attempts = [
            'select' => fn () => $this->db->table('counters')->select(['name', $bound]),
            'groupBy' => fn () => $this->db->table('counters')->groupBy($bound),
            'groupBy' . ' (array)' => fn () => $this->db->table('counters')->groupBy(['name', $bound]),
            'the column of having' => fn () => $this->db->table('counters')->groupBy('name')->having($bound, '>', 1),
        ];

        foreach ($attempts as $where => $attempt) {
            try {
                $attempt();
                $this->fail('Expected QueryException for ' . $where);
            } catch (QueryException $e) {
                $this->assertStringContainsString('only accepted as a value', (string) $e->getDebugMessage());
                $this->assertStringContainsString('not in ' . str_replace(' (array)', '', $where) . '()', (string) $e->getDebugMessage());
            }
        }

        // without bindings all of them stay what they were
        $rows = $this->db->table('counters')
            ->select(['name', Database::raw('SUM(hits) AS total')])
            ->groupBy(Database::raw('name'))
            ->having(Database::raw('SUM(hits)'), '>', 4)
            ->get();
        $this->assertSame([['name' => 'b', 'total' => self::binding()->deliveredIntSum(5)]], $rows, 'the SUM() as the database delivers it');
    }

    public function testTheBindingsOfARawExpressionAreAListOfValues(): void
    {
        $this->assertSame([], Database::raw('NOW()')->bindings);
        $this->assertSame([1, null, 'x'], Database::raw('? ? ?', ['a' => 1, 'b' => null, 7 => 'x'])->bindings, 'keys are dropped, the order stays');

        try {
            Database::raw('hits + ?', [Database::raw('1')]);
            $this->fail('Expected QueryException: a raw expression cannot be bound');
        } catch (QueryException $e) {
            $this->assertStringContainsString('write a raw expression into the SQL', (string) $e->getDebugMessage());
        }

        // as a parameter of whereRaw() or a plain statement an expression is still refused, bindings or not
        try {
            $this->db->table('counters')->whereRaw('hits > ?', [Database::raw('?', [1])]);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('whereRaw() binds its values', (string) $e->getDebugMessage());
        }
    }

    public function testDriverInsertInlinesRawValues(): void
    {
        $id = $this->db->insert('counters', ['name' => 'c', 'hits' => Database::raw('40 + 2'), 'seen_at' => $this->db->utcNow()]);
        $row = $this->db->findOne('counters', ['id' => $id]);

        $this->assertNotNull($row);
        $this->assertSame(42, $row['hits']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['seen_at']);
    }

    public function testDriverUpdateFindAndDeleteInlineRawValues(): void
    {
        $affected = $this->db->update('counters', ['hits' => Database::raw('hits * 2')], ['hits' => Database::raw('1 + 4')]);

        $this->assertSame(1, $affected);
        $this->assertSame(10, $this->db->findOne('counters', ['name' => 'b'])['hits'] ?? null);
        $this->assertCount(1, $this->db->findAll('counters', ['hits' => Database::raw('5 * 2')]));
        $this->assertSame(1, $this->db->delete('counters', ['hits' => Database::raw('5 * 2')]));
        $this->assertSame(1, $this->db->table('counters')->count());
    }

    public function testNowAndUtcNow(): void
    {
        $this->db->update('counters', ['seen_at' => $this->db->now()], ['name' => 'a']);
        $this->db->update('counters', ['seen_at' => $this->db->utcNow()], ['name' => 'b']);
        $local = (string) ($this->db->findOne('counters', ['name' => 'a'])['seen_at'] ?? '');
        $utc = (string) ($this->db->findOne('counters', ['name' => 'b'])['seen_at'] ?? '');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $local);
        $this->assertEqualsWithDelta(time(), (int) strtotime($utc . ' UTC'), 5);
        $this->assertSame(1, $this->db->table('counters')->where('name', 'b')->where('seen_at', '<=', $this->db->utcNow())->count());
    }
}
