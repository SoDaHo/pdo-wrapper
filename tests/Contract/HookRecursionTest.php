<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract;

use LogicException;

/**
 * A query.before, query or error listener that runs a statement for every statement it is told
 * about recurses: the driver stops it at 32 levels with a LogicException - unstopped, PHP runs out
 * of memory, a fatal error nothing catches. One statement of its own (an audit row) goes through.
 */
class HookRecursionTest extends ContractTestCase
{
    public function testAQueryListenerThatAlwaysQueriesIsStopped(): void
    {
        $this->assertStoppedFor('query');
    }

    public function testAQueryBeforeListenerThatAlwaysQueriesIsStopped(): void
    {
        $this->assertStoppedFor('query.before');
    }

    public function testAnErrorListenerThatAlwaysFailsAStatementIsStopped(): void
    {
        $runs = 0;
        $listener = function () use (&$runs): void {
            $runs++;
            $this->db->query('SELECT * FROM hook_recursion_missing');
        };
        $this->db->on('error', $listener);

        try {
            $this->db->query('SELECT * FROM hook_recursion_missing');
            $this->fail('Expected LogicException');
        } catch (LogicException $e) {
            $this->assertStringStartsWith('Hook recursion: 32 ', $e->getMessage());
        }
        $this->assertSame(32, $runs);

        $this->db->off('error', $listener);
        $this->assertSame(1, $this->db->query('SELECT 1')->fetchColumn(), 'the nesting is counted down again');
    }

    public function testAListenerWithOneStatementOfItsOwnGoesThrough(): void
    {
        $audited = [];
        $this->db->on('query', function (array $data) use (&$audited): void {
            if ($data['sql'] !== 'SELECT 2') {
                $audited[] = $this->db->query('SELECT 2')->fetchColumn();
            }
        });

        $this->db->query('SELECT 1');

        $this->assertSame([2], $audited);
    }

    /**
     * A listener of $event that runs a statement for every statement is stopped at the limit: a
     * LogicException after 32 levels, one run of the listener per level - and once it is removed,
     * the nesting is counted down again and a statement runs.
     */
    private function assertStoppedFor(string $event): void
    {
        $runs = 0;
        $listener = function () use (&$runs): void {
            $runs++;
            $this->db->query('SELECT 1');
        };
        $this->db->on($event, $listener);

        try {
            $this->db->query('SELECT 1');
            $this->fail('Expected LogicException');
        } catch (LogicException $e) {
            $this->assertStringStartsWith('Hook recursion: 32 ', $e->getMessage());
        }
        $this->assertSame(32, $runs, 'one listener per level up to the limit');

        $this->db->off($event, $listener);
        $this->assertSame(1, $this->db->query('SELECT 1')->fetchColumn(), 'the nesting is counted down again');
    }
}
