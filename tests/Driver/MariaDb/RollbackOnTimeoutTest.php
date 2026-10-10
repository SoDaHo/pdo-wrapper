<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\TransactionEnd\TransactionEndTestCase;
use Sodaho\PdoWrapper\Tests\Support\ReadsPdoErrorInfo;

/**
 * A server started with innodb_rollback_on_timeout: a lock wait timeout (error 1205) rolls back the
 * whole transaction, and the server's error does not tell the client. Without that option - the
 * default - the test is skipped; the option cannot be set at runtime, CI runs it in a job of its own
 * against a server started with --innodb-rollback-on-timeout=ON.
 */
class RollbackOnTimeoutTest extends TransactionEndTestCase
{
    use ReadsPdoErrorInfo;

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->observer->query('SELECT @@innodb_rollback_on_timeout')->fetchColumn() !== 1) {
            $this->markTestSkipped('the server runs without innodb_rollback_on_timeout');
        }
    }

    /**
     * The callback swallows the timeout. The driver asks right after it and finds the transaction
     * gone: the locking read and the insert that follow are not sent - in autocommit the lock would
     * end with its own statement and the row be committed on its own. The end is 'lost'; the row
     * written before the timeout was rolled back by the server.
     */
    public function testNothingIsSentAfterALockWaitTimeoutThatEndedTheTransaction(): void
    {
        $this->runIntoTheTimeout();
    }

    /**
     * The same with autocommit switched off: the statement after the end would open the next
     * transaction, and the commit would commit it as if nothing had happened. The question right
     * after the timeout opens none (measured): the transaction is found gone first.
     */
    public function testWithAutocommitOffNothingIsSentAfterTheTimeoutEither(): void
    {
        $this->db->execute('SET autocommit = 0');
        $this->runIntoTheTimeout();
    }

    /**
     * A manual transaction with a named lock, the README pattern around it: the timeout reaches the
     * catch, inTransaction() is false already (the driver asked right after it), currentTransaction()
     * still names the transaction. rollback() tells 'lost', the lock release in finally goes through
     * and the connection works again.
     */
    public function testTheReadmePatternEndsAManualTransactionTheTimeoutEnded(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'locked by the observer']);
        $this->db->execute('SET SESSION innodb_lock_wait_timeout = 1');
        $this->assertTrue($this->db->namedLock('timeout-way-out'));
        $this->events = [];
        $code = null;

        $this->observer->beginTransaction();
        try {
            $this->observer->update(self::TABLE, ['name' => 'held'], ['id' => 1]);
            try {
                $this->db->beginTransaction();
                $this->db->insert(self::TABLE, ['id' => 10, 'name' => 'before the timeout']);
                $this->db->update(self::TABLE, ['name' => 'waits'], ['id' => 1]);
                $this->fail('Expected QueryException: lock wait timeout');
            } catch (QueryException $e) {
                $code = $this->errorInfoBehind($e, 1);
                $pdoReported = $this->db->inTransaction();
                if ($this->db->currentTransaction() !== null || $this->db->inTransaction()) {
                    $this->db->rollback();
                }
                $this->assertFalse($pdoReported, 'the driver asked right after the timeout: inTransaction() alone would skip the rollback');
            } finally {
                $this->assertTrue($this->db->releaseNamedLock('timeout-way-out'), 'the release in finally goes through');
            }
        } finally {
            $this->observer->rollback();
        }

        $this->assertSame(1205, $code);
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST], array_column($this->ends, 'outcome'));
        $this->assertSame(['end'], $this->events, 'no rollback listener');
        $this->assertNull($this->db->currentTransaction());
        $this->assertSame([], $this->db->heldNamedLocks());
        $this->assertSame(1, $this->db->table(self::TABLE)->count(), 'a statement goes through');
        $this->assertVisible([1], 'row 10 was rolled back by the server');
    }

    private function runIntoTheTimeout(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'locked by the observer']);
        if ($this->db->inTransaction()) {
            $this->db->getPdo()->commit(); // autocommit off: the insert opened a transaction; the row must be there for the observer
        }
        $this->db->execute('SET SESSION innodb_lock_wait_timeout = 1');
        $this->events = [];
        $code = null;
        $refused = [];

        $this->observer->beginTransaction();
        try {
            $this->observer->update(self::TABLE, ['name' => 'held'], ['id' => 1]);
            $this->db->transaction(function (DatabaseInterface $db) use (&$code, &$refused): void {
                $db->insert(self::TABLE, ['id' => 10, 'name' => 'before the timeout']);
                try {
                    $db->update(self::TABLE, ['name' => 'waits'], ['id' => 1]);
                } catch (QueryException $e) {
                    $code = $this->errorInfoBehind($e, 1); // swallowed: the callback goes on
                }
                foreach ([
                    static fn (): ?array => $db->table(self::TABLE)->where('id', 1)->lockForUpdate()->first(),
                    static fn (): int => $db->insert(self::TABLE, ['id' => 11, 'name' => 'after the timeout']),
                ] as $statement) {
                    try {
                        $statement();
                        $this->fail('Expected QueryException: not sent');
                    } catch (QueryException $e) {
                        $refused[] = $e;
                    }
                }
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome);
            $this->assertSame(1205, $this->errorInfoBehind($e, 1));
        } finally {
            $this->observer->rollback();
        }

        $this->assertSame(1205, $code);
        $this->assertCount(2, $refused);
        foreach ($refused as $e) {
            $this->assertStringStartsWith('Not sent: the server ended the transaction this library began', (string) $e->getDebugMessage());
        }
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST], array_column($this->ends, 'outcome'));
        $this->assertSame(['end'], $this->events, 'no commit and no rollback listener');
        $this->assertVisible([1], 'row 10 was rolled back by the server, row 11 never sent');
    }
}
