<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Fetched;

/**
 * increment() and decrement(): one statement that adds to a column, with further columns set in
 * the same statement, under the rules of update().
 */
class IncrementTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create('counters', ['id' => 'key', 'attempts' => 'int NOT NULL', 'score' => 'double NOT NULL', 'note' => 'text']);
        $this->db->insert('counters', ['id' => 1, 'attempts' => 0, 'score' => 1.5, 'note' => 'a']);
        $this->db->insert('counters', ['id' => 2, 'attempts' => 5, 'score' => 0, 'note' => 'b']);
    }

    public function testIncrementAndDecrementChangeTheColumnInPlace(): void
    {
        $this->assertSame(1, $this->db->table('counters')->where('id', 1)->increment('attempts'));
        $this->assertSame(1, $this->db->table('counters')->where('id', 1)->increment('attempts', 4, ['note' => 'raised']));
        $this->assertSame(1, $this->db->table('counters')->where('id', 2)->decrement('attempts', 2));
        $this->assertSame(1, $this->db->table('counters')->where('id', 2)->increment('score', 0.25));
        $this->assertSame(2, $this->db->table('counters')->where('id', '>', 0)->decrement('score', 0.5));

        $rows = $this->db->table('counters')->orderBy('id')->get();
        $this->assertSame([5, 3], array_map(static fn (array $row): int => Fetched::int($row['attempts']), $rows));
        $this->assertSame([1.0, -0.25], array_column($rows, 'score'));
        $this->assertSame(['raised', 'b'], array_column($rows, 'note'));
    }

    /**
     * The rules of update(): a WHERE condition is required, limit() needs orderBy().
     */
    public function testTheRulesOfUpdateApply(): void
    {
        try {
            $this->db->table('counters')->increment('attempts');
            $this->fail('Expected QueryException: no WHERE condition');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Cannot update without WHERE conditions', (string) $e->getDebugMessage());
        }

        try {
            $this->db->table('counters')->where('id', '>', 0)->limit(1)->decrement('attempts');
            $this->fail('Expected QueryException: limit() without orderBy()');
        } catch (QueryException $e) {
            $this->assertStringContainsString('needs an orderBy()', (string) $e->getDebugMessage());
        }

        $this->assertSame(1, $this->db->table('counters')->where('id', '>', 0)->orderBy('id', 'DESC')->limit(1)->increment('attempts', 10));
        $this->assertSame([0, 15], array_map(static fn (array $row): int => Fetched::int($row['attempts']), $this->db->table('counters')->orderBy('id')->get()));
    }

    public function testTheColumnCannotBeSetInTheExtraValuesToo(): void
    {
        foreach (['increment' => fn (): int => $this->db->table('counters')->where('id', 1)->increment('attempts', 1, ['attempts' => 9]), 'decrement' => fn (): int => $this->db->table('counters')->where('id', 1)->decrement('attempts', 1, ['attempts' => 9])] as $method => $call) {
            try {
                $call();
                $this->fail('Expected QueryException: ' . $method);
            } catch (QueryException $e) {
                $this->assertSame('Update failed', $e->getMessage());
                $this->assertSame(sprintf('%s() changes "attempts" itself; it cannot be set in $extra as well', $method), $e->getDebugMessage());
            }
        }
        $this->assertSame(0, Fetched::int($this->db->findOne('counters', ['id' => 1])['attempts'] ?? null));
    }
}
