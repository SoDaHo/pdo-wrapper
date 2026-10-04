<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * orderBy() with an expression: only as an object, never as a string, and without bindings.
 */
class OrderByExpressionTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create('tasks', ['id' => 'key', 'status' => 'text', 'title' => 'text']);
        foreach ([[1, 'done', 'b'], [2, 'open', 'a'], [3, 'blocked', 'c'], [4, 'open', 'd']] as [$id, $status, $title]) {
            $this->db->insert('tasks', ['id' => $id, 'status' => $status, 'title' => $title]);
        }
    }

    public function testAnExpressionOrdersTheRows(): void
    {
        $order = Database::raw("CASE status WHEN 'open' THEN 0 WHEN 'blocked' THEN 1 ELSE 2 END");

        $ids = array_column($this->db->table('tasks')->orderBy($order)->orderBy('id', 'DESC')->get(), 'id');

        $this->assertSame([4, 2, 3, 1], $ids);
    }

    /**
     * The alias of a select() entry, named through an expression.
     */
    public function testAnAliasOfTheSelectOrdersTheRows(): void
    {
        $rows = $this->db->table('tasks')
            ->select(['id', Database::raw('LENGTH(status) AS status_length')])
            ->orderBy(Database::raw('status_length'), 'DESC')
            ->orderBy('id')
            ->get();

        $this->assertSame([3, 1, 2, 4], array_column($rows, 'id'));
    }

    /**
     * A string is a column name and nothing else: what a request sends cannot become SQL. (The
     * name quoted as one: tests/Unit/ContractQueryRenderingTest.)
     */
    public function testAStringIsAlwaysAQuotedColumnName(): void
    {
        $this->expectException(QueryException::class);
        $this->db->table('tasks')->orderBy('status DESC, (SELECT 1)')->get();
    }

    public function testAnExpressionWithBindingsIsRefused(): void
    {
        try {
            $this->db->table('tasks')->orderBy(Database::raw('CASE WHEN status = ? THEN 0 ELSE 1 END', ['open']));
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('not in orderBy()', (string) $e->getDebugMessage());
        }
    }

    public function testAnExpressionWithAnUnknownDirectionIsRefused(): void
    {
        try {
            $this->db->table('tasks')->orderBy(Database::raw('LENGTH(title)'), 'sideways');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Invalid orderBy() direction "sideways" for "LENGTH(title)". Allowed: ASC, DESC', $e->getDebugMessage());
        }
    }
}
