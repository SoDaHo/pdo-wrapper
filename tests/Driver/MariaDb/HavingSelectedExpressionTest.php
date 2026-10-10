<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * MariaDB names an expression selected without an alias after its text: having('COUNT(*)') - the
 * quoted name - refers to a select() entry Database::raw('COUNT(*)') (measured); it is refused only
 * without such an entry, before anything is sent.
 */
class HavingSelectedExpressionTest extends ContractTestCase
{
    public function testHavingNamesTheSelectedExpression(): void
    {
        $this->create('tags', ['id' => 'key', 'name' => 'text']);
        foreach ([[1, 'x'], [2, 'x'], [3, 'y']] as [$id, $name]) {
            $this->db->insert('tags', ['id' => $id, 'name' => $name]);
        }

        $rows = $this->db->table('tags')->select(['name', Database::raw('COUNT(*)')])->groupBy('name')->having('COUNT(*)', '>', 1)->get();

        $this->assertSame([['name' => 'x', 'COUNT(*)' => 2]], $rows);

        $sent = [];
        $this->db->on('query.before', static function () use (&$sent): void {
            $sent[] = true;
        });
        try {
            $this->db->table('tags')->select(['name'])->groupBy('name')->having('COUNT(*)', '>', 1)->get();
            $this->fail('Expected QueryException: no select() entry is named so');
        } catch (QueryException) {
            $this->assertSame([], $sent, 'refused before anything is sent');
        }
    }
}
