<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;

/**
 * Database::raw() as a value: the expression is inlined into the SQL instead of being bound as text.
 * Covers the driver helpers, the query builder and the now()/utcNow() expressions on SQLite.
 */
class RawValueTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = Database::sqlite();
        $this->db->execute('CREATE TABLE counters (id INTEGER PRIMARY KEY, name TEXT, hits INTEGER NOT NULL DEFAULT 0, seen_at TEXT)');
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
        [$sql, $params] = $this->db->table('counters')
            ->where('hits', '>', Database::raw('1 + 1'))
            ->where('name', 'b')
            ->toSql();

        $this->assertSame('SELECT * FROM `counters` WHERE `hits` > 1 + 1 AND `name` = ?', $sql);
        $this->assertSame(['b'], $params);
        $this->assertCount(1, $this->db->table('counters')->where('hits', '>', Database::raw('1 + 1'))->get());
    }

    public function testBuilderWhereInAndWhereBetweenInlineRawValues(): void
    {
        [$sql, $params] = $this->db->table('counters')
            ->whereIn('hits', [1, Database::raw('2 + 3'), 9])
            ->whereBetween('hits', [Database::raw('0'), 7])
            ->toSql();

        $this->assertSame('SELECT * FROM `counters` WHERE `hits` IN (?, 2 + 3, ?) AND `hits` BETWEEN 0 AND ?', $sql);
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
        [$sql, $params] = $this->db->table('counters')
            ->select(['name', Database::raw('SUM(hits) AS total')])
            ->where('hits', '>', Database::raw('0'))
            ->where('name', 'b')
            ->groupBy('name')
            ->having(Database::raw('SUM(hits)'), '>=', 5)
            ->toSql();
        $this->assertSame('SELECT `name`, SUM(hits) AS total FROM `counters` WHERE `hits` > 0 AND `name` = ? GROUP BY `name` HAVING SUM(hits) >= ?', $sql);
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
        [$sql, $params] = $this->db->table('counters')->whereLike('name', '100%')->toSql();
        $this->assertSame('SELECT * FROM `counters` WHERE `name` LIKE ? ESCAPE ?', $sql);
        $this->assertSame(['100%', '\\'], $params);

        [$sql, $params] = $this->db->table('counters')->where('name', 'LIKE', Database::raw("'a' || '%'"))->toSql();
        $this->assertSame('SELECT * FROM `counters` WHERE `name` LIKE \'a\' || \'%\' ESCAPE ?', $sql);
        $this->assertSame(['\\'], $params);
        $this->assertSame(1, $this->db->table('counters')->where('name', 'LIKE', Database::raw("'a' || '%'"))->count());
    }

    public function testBuilderHavingInlinesRawValue(): void
    {
        $rows = $this->db->table('counters')
            ->select(['name', Database::raw('SUM(hits) AS total')])
            ->groupBy('name')
            ->having(Database::raw('SUM(hits)'), '>', Database::raw('2 + 2'))
            ->get();

        $this->assertSame([['name' => 'b', 'total' => 5]], $rows);
    }

    /**
     * Consumers rely on raw() in select() lists staying byte-identical.
     */
    public function testRawInSelectIsUnchanged(): void
    {
        [$sql, $params] = $this->db->table('counters')
            ->select([Database::raw('counters.*'), Database::raw('COUNT(*) AS n')])
            ->toSql();

        $this->assertSame('SELECT counters.*, COUNT(*) AS n FROM `counters`', $sql);
        $this->assertSame([], $params);
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

    public function testNowAndUtcNowOnSqlite(): void
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
