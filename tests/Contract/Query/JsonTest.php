<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Fetched;

/**
 * Database::json() against the database: the value inside a document as text, a missing field
 * as null, the fallback column, and the expression in conditions, select(), groupBy(),
 * orderBy() and having().
 */
class JsonTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create('events', ['id' => 'key', 'payload' => 'text', 'ip' => 'text']);
        $this->db->insert('events', ['id' => 1, 'payload' => '{"net": "a", "n": 5, "price": 1.50, "items": [{"id": 7}]}', 'ip' => '10.0.0.1']);
        $this->db->insert('events', ['id' => 2, 'payload' => '{"net": "b", "n": 12}', 'ip' => '10.0.0.2']);
        $this->db->insert('events', ['id' => 3, 'payload' => '{"net": "a", "n": 1}', 'ip' => '10.0.0.3']);
        $this->db->insert('events', ['id' => 4, 'payload' => '{"other": 1}', 'ip' => '10.0.0.4']);
    }

    public function testTheValueArrivesAsText(): void
    {
        $rows = $this->db->table('events')
            ->select(['id', Database::json('payload', '$.net')->as('net'), Database::json('payload', '$.n')->as('n'), Database::json('payload', '$.price')->as('price'), Database::json('payload', '$.items[0].id')->as('item')])
            ->orderBy('id')
            ->get();

        $this->assertSame([
            ['id' => 1, 'net' => 'a', 'n' => '5', 'price' => '1.50', 'item' => '7'],
            ['id' => 2, 'net' => 'b', 'n' => '12', 'price' => null, 'item' => null],
            ['id' => 3, 'net' => 'a', 'n' => '1', 'price' => null, 'item' => null],
            ['id' => 4, 'net' => null, 'n' => null, 'price' => null, 'item' => null],
        ], $rows);
    }

    /**
     * A missing field is SQL NULL: the row matches no comparison, whereNull() finds it.
     */
    public function testConditionsOnTheValue(): void
    {
        $net = Database::json('payload', '$.net');
        $ids = static fn (QueryBuilder $builder): array => array_column($builder->orderBy('id')->get(), 'id');

        $this->assertSame([1, 3], $ids($this->db->table('events')->where($net, 'a')));
        $this->assertSame([2], $ids($this->db->table('events')->where($net, '<>', 'a')), 'the row without the field matches no comparison');
        $this->assertSame([1, 2, 3], $ids($this->db->table('events')->whereIn($net, ['a', 'b'])));
        $this->assertSame([2], $ids($this->db->table('events')->whereNotIn($net, ['a'])));
        $this->assertSame([4], $ids($this->db->table('events')->whereNull($net)));
        $this->assertSame([1, 2, 3], $ids($this->db->table('events')->whereNotNull($net)));
        $this->assertSame([2], $ids($this->db->table('events')->whereLike($net, 'b%')));
        $this->assertSame([1, 3], $ids($this->db->table('events')->whereNotLike($net, 'b%')));
        $this->assertSame([1, 2], $ids($this->db->table('events')->whereBetween($net, ['a', 'b'])->where('id', '<', 3)));
        $this->assertSame([3], $ids($this->db->table('events')->whereNotBetween($net, ['b', 'z'])->where('id', '>', 1)));
        $this->assertSame(2, $this->db->table('events')->where($net, 'a')->count());
        $this->assertSame(1, $this->db->table('events')->where($net, 'b')->update(['ip' => 'changed']));
        $this->assertSame('changed', $this->db->table('events')->where('id', 2)->first()['ip'] ?? null);
    }

    /**
     * orColumn() falls back to the column where the document has no value.
     */
    public function testOrColumnFillsTheMissingValue(): void
    {
        $net = Database::json('payload', '$.net')->orColumn('ip');

        $this->assertSame(['a', 'b', 'a', '10.0.0.4'], array_column($this->db->table('events')->select([$net->as('net')])->orderBy('id')->get(), 'net'));
        $this->assertSame([4], array_column($this->db->table('events')->where($net, '10.0.0.4')->get(), 'id'));
    }

    /**
     * Grouped by the value, counted, filtered with having() on the alias and ordered by the value.
     */
    public function testGroupedByTheValue(): void
    {
        $net = Database::json('payload', '$.net')->orColumn('ip');

        $groups = $this->db->table('events')
            ->select([$net->as('net'), Database::raw('COUNT(*) AS n')])
            ->groupBy($net)
            ->having('net', '<>', 'b')
            ->orderBy($net, 'DESC')
            ->get();

        $this->assertSame(['a', '10.0.0.4'], array_column($groups, 'net'));
        $this->assertSame([2, 1], array_map(static fn (array $row): int => Fetched::int($row['n']), $groups));
        $this->assertSame(3, $this->db->table('events')->groupBy($net)->count(), 'three groups');
    }
}
