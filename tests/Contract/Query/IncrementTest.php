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

    /**
     * The column cannot be set in $extra as well - also not in another case or as
     * "table.column", which name the same column: the second assignment would replace the step.
     */
    public function testTheColumnCannotBeSetInTheExtraValuesToo(): void
    {
        foreach (['increment', 'decrement'] as $method) {
            foreach (['attempts', 'ATTEMPTS', 'counters.attempts', 'Counters.Attempts'] as $key) {
                try {
                    $this->db->table('counters')->where('id', 1)->{$method}('attempts', 1, ['note' => 'x', $key => 9]);
                    $this->fail("Expected QueryException: {$method} with {$key}");
                } catch (QueryException $e) {
                    $this->assertSame('Update failed', $e->getMessage());
                    $this->assertSame(sprintf('%s() changes "attempts" itself; it cannot be set in $extra as well (as "%s")', $method, $key), $e->getDebugMessage());
                }
            }
        }
        $row = $this->db->findOne('counters', ['id' => 1]) ?? [];
        $this->assertSame(0, Fetched::int($row['attempts'] ?? null), 'nothing was sent');
        $this->assertSame('a', $row['note'] ?? null);
    }

    /**
     * The step is added exactly: a BIGINT beyond 2^53 and a DECIMAL with more digits than a double
     * holds come back exact, for an int step and for a float step.
     */
    public function testTheStepIsAddedExactly(): void
    {
        $this->create('amounts', ['id' => 'key', 'big' => 'bigint NOT NULL', 'exact' => 'decimal NOT NULL']);
        $this->db->insert('amounts', ['id' => 1, 'big' => 9007199254740993, 'exact' => '12345678901234567.1234']);
        $row = fn (): array => $this->db->findOne('amounts', ['id' => 1]) ?? [];

        $this->db->table('amounts')->where('id', 1)->increment('big', 1, ['exact' => '12345678901234567.1234']);
        $this->assertSame(9007199254740994, Fetched::int($row()['big']));
        $this->db->table('amounts')->where('id', 1)->decrement('big', 3);
        $this->assertSame(9007199254740991, Fetched::int($row()['big']));

        $this->db->table('amounts')->where('id', 1)->increment('exact', 1);
        $this->assertSame('12345678901234568.1234', $row()['exact']);
        $this->db->table('amounts')->where('id', 1)->increment('exact', 0.5);
        $this->assertSame('12345678901234568.6234', $row()['exact']);
        $this->db->table('amounts')->where('id', 1)->decrement('exact', 0.0001);
        $this->assertSame('12345678901234568.6233', $row()['exact']);
    }
}
