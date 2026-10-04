<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
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
        $freed = null;
        $ends = [];
        $this->db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data['outcome'];
        });

        try {
            $this->db->transaction(function (DatabaseInterface $db) use (&$swallowed, &$blocked, &$freed): void {
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
                // The server rolled the whole transaction back, not the statement: the lock its
                // first update took on row 2 is gone while PDO still reports the transaction
                $freed = $this->other->query('SELECT name FROM snapshot_rows WHERE id = 2 FOR UPDATE NOWAIT')->fetchColumn();
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
        $this->assertSame('Anna', $freed, 'row 2 was free and unchanged before the library rolled back');
        $this->assertSame(['rolled_back'], $ends);
        $this->assertFalse($this->db->inTransaction());
        $rows = $this->other->table('snapshot_rows')->orderBy('id')->get();
        $this->assertSame(['changed by the other', 'Anna'], array_column($rows, 'name'), 'what the transaction did before is gone');
    }

    /**
     * The manual path after a 1020, as after a deadlock: further statements and a new
     * beginTransaction() are refused, the commit is refused without a COMMIT, and rollback() ends
     * the transaction - then the connection works again.
     */
    public function testAfterA1020OnlyRollbackEndsTheTransaction(): void
    {
        $ends = [];
        $this->db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data['outcome'];
        });

        $this->db->beginTransaction();
        $this->db->table('snapshot_rows')->get();
        $this->db->update('snapshot_rows', ['name' => 'changed in the transaction'], ['id' => 2]);
        $this->other->update('snapshot_rows', ['name' => 'changed by the other'], ['id' => 1]);
        try {
            $this->db->update('snapshot_rows', ['name' => 'too late'], ['id' => 1]);
            $this->fail('Expected QueryException: 1020');
        } catch (QueryException $e) {
            $failure = $e->getPrevious();
            $this->assertInstanceOf(PDOException::class, $failure);
            $this->assertSame(1020, $failure->errorInfo[1] ?? null);
        }

        try {
            $this->db->query('SELECT 1');
            $this->fail('Expected QueryException: nothing is sent');
        } catch (QueryException $e) {
            $this->assertSame($failure, $e->getPrevious());
        }
        try {
            $this->db->beginTransaction();
            $this->fail('Expected TransactionException: the begin is refused');
        } catch (TransactionException $e) {
            $this->assertSame($failure, $e->getPrevious());
        }
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException: the commit is refused');
        } catch (CommitFailedException $e) {
            $this->assertSame($failure, $e->getPrevious());
            $this->assertNull($e->outcome, 'the transaction is still the caller\'s to end');
        }
        $this->assertSame([], $ends);
        $this->assertTrue($this->db->inTransaction(), 'PDO still reports it');

        $this->db->rollback();

        $this->assertSame(['rolled_back'], $ends);
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(['changed by the other', 'Anna'], array_column($this->db->table('snapshot_rows')->orderBy('id')->get(), 'name'));
    }
}
