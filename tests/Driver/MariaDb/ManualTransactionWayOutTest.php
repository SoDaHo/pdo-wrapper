<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Contract\TransactionEnd\TransactionEndTestCase;
use Throwable;

/**
 * A manual transaction (beginTransaction()) that the server ended behind the library's back - a DDL
 * statement committed it implicitly, raw PDO ended it: PDO reports no transaction any more, while
 * the library still holds it (currentTransaction() is its number) and refuses every statement. The
 * README's pattern - roll back when currentTransaction() or inTransaction() says so, release the
 * named lock in finally - is the way out: rollback() tells the end as 'lost', and the connection
 * works again.
 */
class ManualTransactionWayOutTest extends TransactionEndTestCase
{
    private const LOCK = 'manual-way-out';

    /**
     * A DDL statement that succeeded committed the transaction, and the work after it fails. rollback()
     * sends nothing - a ROLLBACK would fail for want of a transaction - and tells 'lost'; the
     * statement and the lock release in finally go through, another connection gets the lock.
     */
    public function testTheReadmePatternEndsATransactionASuccessfulDdlStatementCommitted(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS manual_way_out_ddl');
        $failure = new RuntimeException('the work after the DDL statement failed');

        $this->runTheReadmePattern(function () use ($failure): void {
            $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
            $this->pdo->exec('CREATE TABLE manual_way_out_ddl (id INT PRIMARY KEY)'); // implicit COMMIT, PDO knows it
            $this->assertFalse($this->db->inTransaction());
            $this->assertNotNull($this->db->currentTransaction(), 'the library still holds the transaction');
            throw $failure;
        }, $failure);

        $this->assertSame([self::LOST], array_column($this->ends, 'outcome'));
        $error = $this->ends[0]['error'];
        $this->assertInstanceOf(TransactionException::class, $error);
        $this->assertSame('Transaction ended outside this library', $error->getMessage());
        $this->assertSame(['end'], $this->events, 'no rollback listener: nothing was rolled back');
        $this->assertWorksAgain([1]);
        $this->db->execute('DROP TABLE manual_way_out_ddl');
    }

    /**
     * The same state reached on raw PDO: a COMMIT there ended what the library began. rollback()
     * ends it as 'lost' as well; before it failed with "There is no active transaction" and left the
     * library refusing every statement.
     */
    public function testRollbackEndsATransactionRawPdoEnded(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'committed on raw PDO']);
        $this->pdo->commit();
        $rolledBackBefore = $this->pdo->rollBackCalls;

        $this->db->rollback();

        $this->assertSame($rolledBackBefore, $this->pdo->rollBackCalls, 'no ROLLBACK was sent');
        $this->assertSame([self::LOST], array_column($this->ends, 'outcome'));
        $this->assertNull($this->db->currentTransaction());
        $this->assertSame(1, $this->db->table(self::TABLE)->count(), 'the connection works again');
        $this->assertVisible([1]);
    }

    /**
     * A failing DDL statement commits the transaction as well; the driver finds it gone right after
     * the failure. inTransaction() alone would skip the rollback - the pattern asks
     * currentTransaction() too.
     */
    public function testTheReadmePatternEndsATransactionAFailingDdlStatementCommitted(): void
    {
        $this->runTheReadmePattern(function (): void {
            $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
            $this->db->execute('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY)'); // exists: fails, and commits
        }, null);

        $this->assertSame([self::LOST], array_column($this->ends, 'outcome'));
        $this->assertSame(['end'], $this->events);
        $this->assertWorksAgain([1]);
    }

    /**
     * The README pattern around a manual transaction with a named lock: the work, then commit();
     * on any failure a rollback when the library or PDO still reports a transaction; the lock
     * released in finally.
     *
     * @param \Closure(): void $work
     */
    private function runTheReadmePattern(\Closure $work, ?Throwable $expected): void
    {
        $this->assertTrue($this->db->namedLock(self::LOCK));
        $this->events = [];
        try {
            $this->db->beginTransaction();
            $work();
            $this->db->commit();
            $this->fail('Expected the work to fail');
        } catch (Throwable $e) {
            if ($this->db->currentTransaction() !== null || $this->db->inTransaction()) {
                $this->db->rollback();
            }
            if ($expected !== null) {
                $this->assertSame($expected, $e);
            } else {
                $this->assertInstanceOf(QueryException::class, $e);
            }
        } finally {
            $this->assertTrue($this->db->releaseNamedLock(self::LOCK), 'the release in finally goes through');
        }
    }

    /**
     * Nothing is held any more: no transaction, a statement goes through, another connection gets
     * the lock - and these rows are committed.
     *
     * @param list<int> $ids
     */
    private function assertWorksAgain(array $ids): void
    {
        $this->assertNull($this->db->currentTransaction());
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame([], $this->db->heldNamedLocks());
        $this->assertSame(count($ids), $this->db->table(self::TABLE)->count(), 'a statement goes through');
        $this->assertTrue($this->observer->namedLock(self::LOCK), 'another connection gets the lock');
        $this->assertTrue($this->observer->releaseNamedLock(self::LOCK));
        $this->assertVisible($ids);
        $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $this->ends[0]['outcome']);
    }
}
