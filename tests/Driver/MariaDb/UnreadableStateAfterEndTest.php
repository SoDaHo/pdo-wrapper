<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use LogicException;
use PDOException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Contract\TransactionEnd\TransactionEndTestCase;

/**
 * A COMMIT or ROLLBACK that went through on a session that chains transactions (set after the
 * driver connected), and right after it the connection state cannot be read once - what a PDO
 * class of the caller's may do. Whether a chained transaction is open is not known: reported as
 * the chained one is, fail-closed, never taken for "none".
 */
class UnreadableStateAfterEndTest extends TransactionEndTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute("SET SESSION completion_type = 'CHAIN'");
    }

    protected function tearDown(): void
    {
        $this->db->execute("SET SESSION completion_type = 'NO_CHAIN'");
        parent::tearDown();
    }

    public function testAnUnreadableStateRightAfterTheCommitIsReportedAndSkipsTheCommitListeners(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->armOnceAfter('commit');

        try {
            $this->db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $first = $e->getPrevious();
            $this->assertInstanceOf(TransactionException::class, $first);
            $this->assertSame('Connection state unknown', $first->getMessage());
            $this->assertStringContainsString('right after COMMIT went through', (string) $first->getDebugMessage());
            $this->assertInstanceOf(PDOException::class, $first->getPrevious());
            $this->assertTrue($e->connectionInTransaction, 'may still be in a transaction: fail-closed');
            $this->assertCount(2, $e->failures);
            $this->assertInstanceOf(LogicException::class, $e->failures[1]);
            $this->assertSame('listener skipped: connection left in transaction', $e->failures[1]->getMessage());
        }

        $this->assertSame(['end'], $this->events, 'the commit listener was skipped, the end is told');
        $this->assertSame(DatabaseInterface::TRANSACTION_COMMITTED, $this->ends[0]['outcome'], 'the COMMIT went through');
        $this->assertTrue($this->pdo->reallyInTransaction(), 'the session chained the next transaction');
        $this->pdo->rollBack();
        $this->assertSame([1], array_column($this->rows(), 'id'));
    }

    public function testAnUnreadableStateRightAfterTheRollbackIsThrown(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->armOnceAfter('rollBack');

        try {
            $this->db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Connection state unknown', $e->getMessage());
            $this->assertStringContainsString('right after ROLLBACK went through', (string) $e->getDebugMessage());
        }

        $this->assertSame(['rollback', 'end'], $this->events, 'rolled back: the listeners ran first');
        $this->assertSame(DatabaseInterface::TRANSACTION_ROLLED_BACK, $this->ends[0]['outcome']);
        $this->assertTrue($this->pdo->reallyInTransaction(), 'the session chained the next transaction');
        $this->pdo->rollBack();
        $this->assertSame([], $this->rows());
    }

    /**
     * Inside the next commit() or rollBack(), before anything is sent: the first inTransaction()
     * after it throws, once.
     */
    private function armOnceAfter(string $end): void
    {
        $pdo = $this->pdo;
        $arm = static function () use ($pdo): void {
            $pdo->duringInTransaction = static function (): never {
                throw new PDOException('state unreadable once (scenario)');
            };
        };
        if ($end === 'commit') {
            $pdo->duringCommit = $arm;
        } else {
            $pdo->duringRollBack = $arm;
        }
    }
}
