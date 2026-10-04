<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * Error 1020 under innodb_snapshot_isolation (on by default since MariaDB 11.6.2; the variable
 * exists since 10.6.18): writing a row another transaction changed since this one read it fails,
 * and the server rolls the whole transaction back while PDO still reports it - as after a
 * deadlock. The library treats it as one: nothing more is sent, the commit is refused, and what
 * the transaction did before is gone (measured on 10.11, 11.4 and 12.3).
 */
class SnapshotIsolationTest extends ContractTestCase
{
    private DatabaseInterface $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->create('snapshot_rows', ['id' => 'key', 'name' => 'text']);
        $this->db->insert('snapshot_rows', ['id' => 1, 'name' => 'Max']);
        $this->db->insert('snapshot_rows', ['id' => 2, 'name' => 'Anna']);
        $this->db->execute('SET SESSION innodb_snapshot_isolation = ON');
        $this->other = $this->connect();
    }

    protected function closeConnections(): void
    {
        unset($this->other);
    }

    public function testASwallowedChangedRowEndsTheTransactionLikeADeadlock(): void
    {
        $swallowed = null;
        $blocked = null;
        $ends = [];
        $this->db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data['outcome'];
        });

        try {
            $this->db->transaction(function (DatabaseInterface $db) use (&$swallowed, &$blocked): void {
                $db->table('snapshot_rows')->get(); // the snapshot
                $db->update('snapshot_rows', ['name' => 'changed in the transaction'], ['id' => 2]);
                $this->other->update('snapshot_rows', ['name' => 'changed by the other'], ['id' => 1]);
                try {
                    $db->update('snapshot_rows', ['name' => 'too late'], ['id' => 1]);
                } catch (QueryException $e) {
                    $swallowed = $e->getPrevious(); // swallowed: the callback goes on
                }
                try {
                    $db->update('snapshot_rows', ['name' => 'after the 1020'], ['id' => 2]);
                } catch (QueryException $e) {
                    $blocked = $e;
                }
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertInstanceOf(PDOException::class, $swallowed);
            $this->assertSame(1020, $swallowed->errorInfo[1] ?? null);
            $this->assertSame($swallowed, $e->getPrevious());
            $this->assertStringContainsString('error 1020', (string) $e->getDebugMessage());
            $this->assertSame('rolled_back', $e->outcome);
        }

        $this->assertInstanceOf(QueryException::class, $blocked);
        $this->assertStringContainsString('Not sent', (string) $blocked->getDebugMessage());
        $this->assertSame(['rolled_back'], $ends);
        $this->assertFalse($this->db->inTransaction());
        $rows = $this->other->table('snapshot_rows')->orderBy('id')->get();
        $this->assertSame(['changed by the other', 'Anna'], array_column($rows, 'name'), 'what the transaction did before is gone');
    }
}
