<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\TransactionEnd;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Support\Recorder;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;

/**
 * The extension points behind a refused commit and an unconfirmed rollback, on a custom driver
 * (HookDriver): what the base class does with the answers a driver gives.
 */
class DriverHookScenariosTest extends TransactionEndTestCase
{
    // ---- the extension point behind the refused commit (an aborted transaction, a deadlock) -------

    /**
     * A driver may flag statement failures that can end a transaction on the server and confirm
     * at commit time. Flagging alone refuses nothing: the default answer is "still committable".
     */
    public function testADriverThatOnlyFlagsStatementFailuresStillCommits(): void
    {
        $db = new class ($this->pdo) extends HookDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            protected function failureToRemember(?PDOException $remembered, PDOException $failure): PDOException
            {
                return $failure;
            }
        };

        $db->transaction(static function (DatabaseInterface $db): void {
            $db->insert(self::TABLE, ['id' => 1, 'name' => 'kept']);
            try {
                $db->query('SELECT * FROM no_such_table');
            } catch (QueryException) {
                // swallowed
            }
        });

        $this->assertSame(1, $db->table(self::TABLE)->count());
    }

    /**
     * The driver is asked about the latest flagged failure of the transaction; failures outside a
     * transaction and of an earlier, rolled-back one are not kept. When the state cannot be read
     * at the time of the failure, the driver is asked all the same (fail-closed).
     */
    public function testADriverConfirmsAtCommitForTheLatestFlaggedFailureOfTheTransaction(): void
    {
        $db = $this->makeFlaggingDriver();
        $fail = $this->failingQuery($db);

        // Outside a transaction: not kept. The next transaction is begun on raw PDO each time,
        // so that beginTransaction() is not what clears an earlier failure.
        $fail('fatal_table');
        $this->pdo->beginTransaction();
        $db->commit();
        $this->assertSame([], $db->asked);

        // In a transaction that is rolled back: the rollback clears it
        $db->beginTransaction();
        $fail('fatal_table');
        $db->rollback();
        $this->pdo->beginTransaction();
        $db->commit();
        $this->assertSame([], $db->asked);

        // In a transaction that was ended on raw PDO: the next beginTransaction() clears it
        $db->beginTransaction();
        $fail('fatal_table');
        $this->pdo->rollBack();
        $db->beginTransaction();
        $db->commit();
        $this->assertSame([], $db->asked);

        // The same for a transaction begun on raw PDO and ended there
        $this->pdo->beginTransaction();
        $fail('fatal_table');
        $this->pdo->rollBack();
        $db->beginTransaction();
        $db->commit();
        $this->assertSame([], $db->asked);

        // Inside a transaction this library began the failure is kept whatever PDO reports at that moment
        $db->beginTransaction();
        $this->pdo->hideTransaction = true;
        $fail('harmless_table');
        $this->pdo->hideTransaction = false;
        $db->commit();
        $this->assertCount(1, $db->asked);
        $db->asked = [];

        // Asked once, about the latest flagged failure; a "still committable" answer settles it
        $db->beginTransaction();
        $fail('fatal_table');
        $fail('harmless_table');
        $fail('ignored_table'); // not flagged: does not replace the one before
        $db->commit();
        $this->assertCount(1, $db->asked);
        $this->assertStringContainsString('harmless_table', $db->asked[0]);
        $this->pdo->beginTransaction();
        $db->commit();
        $this->assertCount(1, $db->asked, 'not asked again for a later transaction');

        $db->beginTransaction();
        $this->pdo->stateUnreadable = true;
        $fail('fatal_table');
        $this->pdo->stateUnreadable = false;
        try {
            $db->commit();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame('ended by the server (scenario)', $e->getDebugMessage());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
        }
        $this->assertTrue($this->pdo->reallyInTransaction(), 'a refused commit leaves the transaction to the caller');
        $db->rollback();
    }

    /**
     * A flagged failure goes with its transaction on the paths that end it without rollback():
     * reported as lost with no transaction left, ended raw inside a commit listener, or cleaned up
     * after one. A transaction begun on raw PDO afterwards is not asked about it.
     */
    public function testAFlaggedFailureIsDroppedWhereverItsTransactionEnds(): void
    {
        $db = $this->makeFlaggingDriver();
        $fail = $this->failingQuery($db);

        // lost, and PDO reports no transaction any more (simulated: the scenario connection hides it,
        // the way a server-side end looks to PDO)
        try {
            $db->transaction(function () use ($fail): void {
                $fail('fatal_table');
                $this->pdo->hideTransaction = true;
                throw new \RuntimeException('callback failed');
            });
            $this->fail('Expected the callback exception');
        } catch (\RuntimeException) {
            $this->pdo->hideTransaction = false;
        }
        $this->pdo->rollBack(); // clean up what the simulation left open
        $this->pdo->beginTransaction();
        $db->commit();
        $this->assertSame([], $db->asked);

        // a commit listener's transaction, ended raw by the listener itself
        $listener = function () use ($db, $fail): void {
            $db->beginTransaction();
            $fail('fatal_table');
            $this->pdo->rollBack();
        };
        $db->on('transaction.commit', static function () use (&$listener): void {
            $listener();
        });
        $db->beginTransaction();
        $db->commit();
        $this->pdo->beginTransaction();
        $db->commit();
        $this->assertSame([], $db->asked);

        // a commit listener's transaction, left open and cleaned up by the library
        $listener = static function () use ($db, $fail): void {
            $db->beginTransaction();
            $fail('fatal_table');
        };
        $db->beginTransaction();
        try {
            $db->commit();
            $this->fail('Expected CommitHookException: the listener left a transaction open');
        } catch (CommitHookException) {
            // cleaned up
        }
        $listener = static function (): void {
        };
        $this->pdo->beginTransaction();
        $db->commit();
        $this->assertSame([], $db->asked);
    }

    /**
     * A refused commit leaves the transaction to the caller's rollback() - unless PDO reports none
     * any more: then nothing could end it, so the refusal itself tells its end as 'lost', once.
     */
    public function testARefusedCommitOfATransactionThatIsGoneTellsItsEndAsLost(): void
    {
        $db = $this->makeFlaggingDriver();
        $fail = $this->failingQuery($db);
        $ends = new Recorder(static fn (array $data): array => $data);
        $db->on('transaction.end', $ends);

        // still reported by PDO: nothing is told, the caller's rollback ends it
        $db->beginTransaction();
        $fail('fatal_table');
        try {
            $db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame([], $ends->all());
            $this->assertNull($e->outcome);
        }
        $db->rollback();
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_ROLLED_BACK, 'error' => null, 'transaction' => 1, 'depth' => 1]], $ends->all());

        // no longer reported by PDO: the refusal tells 'lost', with itself as the error
        $ends->clear();
        $db->beginTransaction();
        $fail('fatal_table');
        $this->pdo->hideTransaction = true;
        try {
            $db->commit();
            $this->fail('Expected TransactionException');
        } catch (CommitFailedException $e) {
            $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $e, 'transaction' => 2, 'depth' => 1]], $ends->all());
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome);
        } finally {
            $this->pdo->hideTransaction = false;
        }
        $this->pdo->rollBack();

        // and transaction() tells that end exactly once
        $ends->clear();
        try {
            $db->transaction(function () use ($fail): void {
                $fail('fatal_table');
                $this->pdo->hideTransaction = true;
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $e, 'transaction' => 3, 'depth' => 1]], $ends->all());
        } finally {
            $this->pdo->hideTransaction = false;
        }
        $this->pdo->rollBack();

        // a transaction this library did not begin (raw PDO) has no end to tell
        $ends->clear();
        $this->pdo->beginTransaction();
        $fail('fatal_table');
        $this->pdo->hideTransaction = true;
        try {
            $db->commit();
            $this->fail('Expected TransactionException');
        } catch (TransactionException) {
            $this->assertSame([], $ends->all());
        } finally {
            $this->pdo->hideTransaction = false;
        }
        $this->pdo->rollBack();

        // a state that cannot be read is not "gone": nothing is told
        $ends->clear();
        $db->beginTransaction();
        $fail('fatal_table');
        $this->pdo->stateUnreadable = true;
        try {
            $db->commit();
            $this->fail('Expected TransactionException');
        } catch (TransactionException) {
            $this->assertSame([], $ends->all());
        } finally {
            $this->pdo->stateUnreadable = false;
        }
        $db->rollback();
    }

    /**
     * A driver that knows a failure ended the transaction for certain (a deadlock, for instance)
     * has the library accept nothing but the end of that transaction: no statement, no new
     * transaction. For a transaction this library began that holds whatever PDO reports; one begun
     * on raw PDO is only held for as long as PDO reports it.
     */
    public function testAfterACertainEndNothingIsSentUntilTheRollback(): void
    {
        $db = new class ($this->pdo) extends HookDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            protected function failureToRemember(?PDOException $remembered, PDOException $failure): PDOException
            {
                return $failure;
            }

            protected function transactionIsOver(PDOException $failure): bool
            {
                return str_contains($failure->getMessage(), 'fatal_table');
            }

            protected function transactionEndedBy(PDOException $failure): ?string
            {
                return str_contains($failure->getMessage(), 'fatal_table') ? 'ended by the server (scenario)' : null;
            }
        };
        $fail = $this->failingQuery($db);
        $told = [];
        $db->on('query', static function (array $data) use (&$told): void {
            $told[] = $data['sql'];
        });
        /** @var Recorder<mixed> $before */
        $before = new Recorder(static fn (array $data): mixed => $data['sql']);
        $db->on('query.before', $before);
        $assertRefused = function () use ($db, $before): void {
            $toldBefore = count($before->all());
            try {
                $db->insert(self::TABLE, ['id' => 9, 'name' => 'outside']);
                $this->fail('Expected QueryException: nothing is sent');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Not sent: the server rolled the open transaction back', (string) $e->getDebugMessage());
                $this->assertStringContainsString('fatal_table', (string) $e->getPrevious()?->getMessage());
            }
            $this->assertCount($toldBefore + 1, $before->all(), "'query.before' fires before the library's own refusal");
            $this->assertStringStartsWith('INSERT', (string) $before->all()[$toldBefore]);
        };

        // a failure that leaves the transaction alive holds nothing back
        $db->beginTransaction();
        $fail('harmless_table');
        $db->insert(self::TABLE, ['id' => 1, 'name' => 'inside']);
        $this->assertCount(1, $told);

        // one that ended it does, also when the state cannot be read - until the rollback
        $fail('fatal_table');
        $assertRefused();
        $this->pdo->stateUnreadable = true;
        $assertRefused();
        $this->pdo->stateUnreadable = false;
        $this->assertCount(1, $told, 'no hook for a statement that was not sent');
        $db->rollback();
        $db->insert(self::TABLE, ['id' => 2, 'name' => 'after the rollback']);

        // begun through the library, ended on raw PDO: PDO reports none, but the library has not ended it.
        // Still nothing is sent and no new transaction begins; rollback() is the way out and tells 'lost'.
        $ends = [];
        $db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data;
        });
        $db->beginTransaction();
        $failure = null;
        try {
            $db->query('SELECT * FROM fatal_table');
        } catch (QueryException $e) {
            $failure = $e->getPrevious();
        }
        $this->pdo->rollBack();
        $assertRefused();
        try {
            $db->beginTransaction();
            $this->fail('Expected TransactionException: the dead transaction has not been ended');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to begin transaction', $e->getMessage());
            $this->assertStringContainsString('has not been ended yet. Call rollback() first.', (string) $e->getDebugMessage());
            $this->assertSame($failure, $e->getPrevious());
        }
        $this->assertSame([], $ends);
        $db->rollback();
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $failure, 'transaction' => 2, 'depth' => 1]], $ends, 'nothing to roll back: the end is told as lost, with the failure');
        $db->insert(self::TABLE, ['id' => 3, 'name' => 'after rollback()']);

        // with an unreadable state rollback() sends the ROLLBACK as usual
        $ends = [];
        $db->beginTransaction();
        $fail('fatal_table');
        $this->pdo->stateUnreadable = true;
        $db->rollback();
        $this->pdo->stateUnreadable = false;
        $this->assertSame([DatabaseInterface::TRANSACTION_ROLLED_BACK], array_column($ends, 'outcome'));

        // begun on raw PDO and ended there: commit() and rollback() forget the failure as well - they
        // fail for want of a transaction, not for the old failure, and nothing stays refused
        foreach (['commit', 'rollback'] as $method) {
            $this->pdo->beginTransaction();
            $fail('fatal_table');
            $this->pdo->rollBack();
            try {
                $db->{$method}();
                $this->fail("Expected TransactionException: no transaction to {$method}");
            } catch (TransactionException $e) {
                $this->assertStringContainsString('no active transaction', strtolower((string) $e->getDebugMessage()));
            }
            // forgotten by that call already: a transaction begun on raw PDO now is not held back
            $this->pdo->beginTransaction();
            $this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE id IN (2, 3)')->fetchColumn(), 'statements are sent again');
            $this->pdo->rollBack();
        }

        // begun on raw PDO, the state unreadable when the statement fails: remembered all the same
        $this->pdo->beginTransaction();
        $this->pdo->stateUnreadable = true;
        $fail('fatal_table');
        $this->pdo->stateUnreadable = false;
        $assertRefused();
        $this->pdo->rollBack();

        // begun on raw PDO: held while PDO reports it, forgotten once it was ended there
        $this->pdo->beginTransaction();
        $fail('fatal_table');
        $assertRefused();
        $this->pdo->stateUnreadable = true;
        $assertRefused(); // an unreadable state counts as "still reported"
        $this->pdo->stateUnreadable = false;
        $this->pdo->rollBack();
        $db->insert(self::TABLE, ['id' => 4, 'name' => 'after a raw rollback of a raw transaction']);
        $this->assertSame([2, 3, 4], array_column($db->table(self::TABLE)->orderBy('id')->get(), 'id'));

        // an 'error' listener that queries on the same connection gets the refusal in its turn
        $db->on('error', static function () use ($db): void {
            $db->query('SELECT 1');
        });
        $db->beginTransaction();
        try {
            $db->query('SELECT * FROM fatal_table');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Not sent', (string) $e->getDebugMessage(), "the listener's statement was refused, and its exception replaces the statement's");
            $this->assertStringContainsString('fatal_table', (string) $e->getPrevious()?->getMessage(), 'the cause is still the failed statement');
        }
        $db->rollback();
    }

    /**
     * Not everything that leaves commit() is the failure of the commit it ran: what is thrown
     * inside PDO::commit() without being PDO's own (an error handler's exception) passes through -
     * here the exception of an earlier transaction, whose second commit attempt went through.
     * transaction() rolls back, but that exception is not the failure of its commit: it is not
     * written to.
     */
    public function testAnOlderFailedCommitThrownInsideTheCommitDoesNotGetItSettled(): void
    {
        $db = $this->db;
        $ends = new Recorder(static fn (array $data): array => $data);
        $db->on('transaction.end', $ends);

        $db->beginTransaction();
        $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->pdo->failCommit = true;
        try {
            $db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $old) {
            $this->assertNull($old->outcome);
        }
        $this->pdo->commit(); // the second attempt, on raw PDO, goes through: row 1 is committed

        $ends->clear();
        $this->pdo->throwFromCommit = $old;
        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->insert(self::TABLE, ['id' => 2, 'name' => 'b']));
            $this->fail('Expected the exception thrown inside the commit');
        } catch (CommitFailedException $e) {
            $this->assertSame($old, $e);
        }
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST, DatabaseInterface::TRANSACTION_ROLLED_BACK], array_column($ends->all(), 'outcome'), 'the first transaction as ended behind the library, then this one');
        $this->assertSame($old, $ends->all()[1]['error']);
        $this->assertNull($old->outcome, 'its own transaction was committed on the second attempt');
        $this->assertSame([1], array_column($db->table(self::TABLE)->get(), 'id'));
    }

    /**
     * Before a ROLLBACK after a failed statement, a driver may be asked to make PDO know whether
     * the transaction still exists (refreshTransactionState()). Gone: the end is 'lost', nothing
     * is sent. Still there: the ROLLBACK is sent as before. The driver could not find out: the
     * ROLLBACK is sent, but the end is 'lost' and no rollback listener runs. Not asked at all:
     * without a failed statement, after a failure that settles the matter by itself, and when PDO
     * reports no transaction anyway.
     */
    public function testRollbackAsksTheDriverAfterAFailedStatementBeforeItTrustsTheRollback(): void
    {
        $db = new AskingDriver($this->pdo);
        $fail = $this->failingQuery($db);
        $ends = new Recorder(static fn (array $data): array => [$data['outcome'], $data['error']]);
        $db->on('transaction.end', $ends);
        $rollbacks = 0;
        $db->on('transaction.rollback', static function () use (&$rollbacks): void {
            $rollbacks++;
        });

        // no statement failed: nothing is asked
        $db->beginTransaction();
        $db->rollback();
        $this->assertSame(0, $db->asked);
        $this->assertSame([DatabaseInterface::TRANSACTION_ROLLED_BACK, null], $ends->pop());

        // a statement failed and the transaction is still there: asked, then rolled back
        $db->beginTransaction();
        $fail('harmless_table');
        $db->rollback();
        $this->assertSame(1, $db->asked);
        $this->assertSame(2, $rollbacks);
        $this->assertSame([DatabaseInterface::TRANSACTION_ROLLED_BACK, null], $ends->pop());
        $this->assertFalse($this->pdo->reallyInTransaction());

        // asked, and the transaction is gone: 'lost' with the remembered failure, no ROLLBACK, no rollback listener
        $db->answer = 'gone';
        $db->beginTransaction();
        $fail('harmless_table');
        $db->rollback();
        $this->assertSame(2, $db->asked);
        $this->assertSame(2, $rollbacks);
        $last = $ends->pop();
        $this->assertNotNull($last);
        [$outcome, $error] = $last;
        $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $outcome);
        $this->assertInstanceOf(PDOException::class, $error);
        $this->assertStringContainsString('harmless_table', $error->getMessage());
        $this->assertTrue($this->pdo->reallyInTransaction(), 'nothing was sent');
        $this->pdo->hideTransaction = false;
        $this->pdo->rollBack();

        // gone for certain: no mark of a transaction that "may still be open" stays behind
        $this->pdo->beginTransaction();
        $db->commit();
        $this->assertSame([DatabaseInterface::TRANSACTION_COMMITTED, null], $ends->pop());

        // in transaction(): the callback's exception is the error of that end
        $cause = new \RuntimeException('callback failed');
        try {
            $db->transaction(static function () use ($fail, $cause): void {
                $fail('harmless_table');
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (\RuntimeException $e) {
            $this->assertSame($cause, $e);
        }
        $this->assertSame(3, $db->asked);
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST, $cause], $ends->pop());
        $this->pdo->hideTransaction = false;
        $this->pdo->rollBack();

        // the driver could not find out: the ROLLBACK is sent, and confirms nothing
        $db->answer = 'unknown';
        $db->beginTransaction();
        $db->insert(self::TABLE, ['id' => 7, 'name' => 'unknown']);
        $fail('harmless_table');
        $before = $rollbacks;
        $db->rollback();
        $this->assertSame(4, $db->asked, 'asked once');
        $this->assertSame($before, $rollbacks, 'no rollback listener');
        $last = $ends->pop();
        $this->assertNotNull($last);
        [$outcome, $error] = $last;
        $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $outcome);
        $this->assertStringContainsString('harmless_table', $error instanceof PDOException ? $error->getMessage() : '');
        $this->assertFalse($this->pdo->reallyInTransaction(), 'the ROLLBACK was sent');
        $this->assertSame([], $db->table(self::TABLE)->get());
        $this->assertSame([], $ends->all(), 'told once');

        // a failure that settles the matter by itself is not asked about
        $db->answer = 'alive';
        $db->beginTransaction();
        $fail('fatal_table');
        $db->rollback();
        $this->assertSame(4, $db->asked);
        $this->assertSame([DatabaseInterface::TRANSACTION_ROLLED_BACK, null], $ends->pop());

        // PDO reports no transaction already (a later statement told it): nothing is asked, and the
        // ROLLBACK is sent as before - here it goes through, because the scenario only hides the transaction
        $db->answer = 'gone';
        $db->beginTransaction();
        $fail('harmless_table');
        $this->pdo->hideTransaction = true;
        $db->rollback();
        $this->pdo->hideTransaction = false;
        $this->assertSame(4, $db->asked);
        $this->assertSame([DatabaseInterface::TRANSACTION_ROLLED_BACK, null], $ends->pop());
        $this->assertFalse($this->pdo->reallyInTransaction());

        // a transaction begun on raw PDO: asked while PDO reports it, not when the state cannot be read
        $db->answer = 'gone';
        $this->pdo->beginTransaction();
        $fail('harmless_table');
        $db->rollback();
        $this->assertSame(5, $db->asked);
        $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $ends->pop()[0] ?? null);
        $this->pdo->hideTransaction = false;
        $fail('harmless_table');
        $this->pdo->stateUnreadable = true;
        try {
            $db->rollback();
        } finally {
            $this->pdo->stateUnreadable = false;
        }
        $this->assertSame(5, $db->asked, 'an unreadable state is no reason to ask');
        $this->assertFalse($this->pdo->reallyInTransaction(), 'the ROLLBACK was sent');
        $ends->pop();

        // a 'lost' was told for a transaction that may still be open: its rollback tells no second end, asked or not
        $this->pdo->failRollBackAlways = true;
        try {
            $db->transaction(static function (): void {
                throw new \RuntimeException('callback failed');
            });
        } catch (\RuntimeException) {
            $this->pdo->failRollBackAlways = false;
        }
        $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $ends->pop()[0] ?? null);
        $fail('harmless_table');
        $db->rollback();
        $this->assertSame(5, $db->asked);
        $this->assertSame([], $ends->all(), 'no second end');
        $this->assertFalse($this->pdo->reallyInTransaction());
    }

    /**
     * The driver cannot find out, and the ROLLBACK that is sent to clean up fails as well: nothing
     * is told on a manual rollback() and the transaction stays the caller's - a second rollback()
     * asks again. In transaction() the end is 'lost' for a transaction that may still be open, and
     * its later rollback tells no second end.
     */
    public function testARollbackThatConfirmsNothingAndFailsLeavesTheTransactionToTheCaller(): void
    {
        $db = new class ($this->pdo) extends HookDriver {
            public int $asked = 0;

            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            protected function failureToRemember(?PDOException $remembered, PDOException $failure): PDOException
            {
                return $failure;
            }

            protected function refreshTransactionState(): bool
            {
                $this->asked++;

                return false;
            }
        };
        $fail = $this->failingQuery($db);
        $ends = new Recorder(static fn (array $data): mixed => $data['outcome']);
        $db->on('transaction.end', $ends);

        $db->beginTransaction();
        $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $fail('harmless_table');
        $this->pdo->failRollBackAlways = true;
        try {
            $db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to rollback transaction', $e->getMessage());
        } finally {
            $this->pdo->failRollBackAlways = false;
        }
        $this->assertSame([], $ends->all(), 'nothing is told: the transaction is still the caller\'s');
        $this->assertSame(1, $db->asked);

        $db->rollback();
        $this->assertSame(2, $db->asked, 'asked again: the failure is still remembered');
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST], $ends->all());
        $this->assertFalse($this->pdo->reallyInTransaction());
        $this->assertSame([], $db->table(self::TABLE)->get());

        $ends->clear();
        $this->pdo->failRollBackAlways = true;
        try {
            $db->transaction(static function (DatabaseInterface $db) use ($fail): void {
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'b']);
                $fail('harmless_table');
                throw new \RuntimeException('callback failed');
            });
            $this->fail('Expected the callback exception');
        } catch (\RuntimeException) {
            $this->pdo->failRollBackAlways = false;
        }
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST], $ends->all());
        $this->assertTrue($this->pdo->reallyInTransaction(), 'may still be open: it is');
        $db->rollback();
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST], $ends->all(), 'no second end');
        $this->assertFalse($this->pdo->reallyInTransaction());
        $this->assertSame(3, $db->asked, 'not asked for a transaction whose end was told');
    }

    /**
     * transaction() swallowed a failed statement, and commit() is refused because the driver cannot
     * say whether the transaction still exists. The rollback that follows asks again: gone. The
     * refused commit leaves with 'lost' - the outcome the end listeners were told with it.
     */
    public function testARefusedCommitWhoseRollbackFindsTheTransactionGoneIsLost(): void
    {
        $db = new class ($this->pdo) extends HookDriver {
            public bool $gone = false;

            public bool $unknown = false;

            public int $asked = 0;

            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            protected function failureToRemember(?PDOException $remembered, PDOException $failure): PDOException
            {
                return $failure;
            }

            protected function transactionEndedBy(PDOException $failure): string
            {
                return 'could not be asked (scenario)';
            }

            protected function refreshTransactionState(): bool
            {
                $this->asked++;
                if ($this->gone && $this->pdo instanceof ScenarioPdo) {
                    $this->pdo->hideTransaction = true;
                }

                return !$this->unknown;
            }
        };
        $fail = $this->failingQuery($db);
        $ends = [];
        $db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = [$data['outcome'], $data['error'], $data['error'] instanceof CommitFailedException ? $data['error']->outcome : null];
        });

        $db->gone = true;
        try {
            $db->transaction(static function () use ($fail): void {
                $fail('harmless_table');
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $refusal) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $refusal->outcome);
            $this->assertSame([[DatabaseInterface::TRANSACTION_LOST, $refusal, DatabaseInterface::TRANSACTION_LOST]], $ends, 'told once, with the refused commit, which says lost at that moment already');
        } finally {
            $this->pdo->hideTransaction = false;
        }
        $this->assertTrue($this->pdo->reallyInTransaction(), 'nothing was sent');
        $this->pdo->rollBack();

        // the transaction is still there: the ROLLBACK is sent and confirms that nothing is committed
        $db->gone = false;
        $ends = [];
        try {
            $db->transaction(static function () use ($fail): void {
                $fail('harmless_table');
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $refusal) {
            $this->assertSame(DatabaseInterface::TRANSACTION_ROLLED_BACK, $refusal->outcome);
            $this->assertSame([[DatabaseInterface::TRANSACTION_ROLLED_BACK, $refusal, DatabaseInterface::TRANSACTION_ROLLED_BACK]], $ends);
        }
        $this->assertFalse($this->pdo->reallyInTransaction());

        // the driver cannot find out: the ROLLBACK is sent, and the refused commit says 'lost'
        $db->unknown = true;
        $ends = [];
        try {
            $db->transaction(static function () use ($fail): void {
                $fail('harmless_table');
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $refusal) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $refusal->outcome);
            $this->assertSame([[DatabaseInterface::TRANSACTION_LOST, $refusal, DatabaseInterface::TRANSACTION_LOST]], $ends);
        }
        $this->assertFalse($this->pdo->reallyInTransaction());

        // ... and the ROLLBACK fails as well: 'lost' for a transaction that may still be open
        $ends = [];
        $asked = $db->asked;
        $this->pdo->failRollBackAlways = true;
        try {
            $db->transaction(static function () use ($fail): void {
                $fail('harmless_table');
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $refusal) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $refusal->outcome);
            $this->assertSame([[DatabaseInterface::TRANSACTION_LOST, $refusal, DatabaseInterface::TRANSACTION_LOST]], $ends);
        } finally {
            $this->pdo->failRollBackAlways = false;
        }
        $this->assertSame($asked + 1, $db->asked, 'asked before the ROLLBACK that failed');
        $this->assertTrue($this->pdo->reallyInTransaction(), 'still open');
        $db->rollback();
        $this->assertCount(1, $ends, 'no second end');
        $this->assertFalse($this->pdo->reallyInTransaction());
    }

    /**
     * The end listeners of a rollback that confirmed nothing: one that throws is told to the
     * 'error' hook and rollback() returns; one that begins a transaction keeps it.
     */
    public function testEndListenersOfARollbackThatConfirmsNothing(): void
    {
        $db = new class ($this->pdo) extends HookDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            protected function failureToRemember(?PDOException $remembered, PDOException $failure): PDOException
            {
                return $failure;
            }

            protected function refreshTransactionState(): bool
            {
                return false;
            }
        };
        $fail = $this->failingQuery($db);
        $reported = [];
        $db->on('error', static function (array $data) use (&$reported): void {
            if (($data['hook'] ?? null) === 'transaction.end') {
                $reported[] = [$data['outcome'] ?? null, $data['error']];
            }
        });
        $state = new class () {
            public string $mode = 'throw';
        };
        $outcomes = [];
        $db->on('transaction.end', static function (array $data) use ($db, $state, &$outcomes): void {
            $outcomes[] = $data['outcome'];
            if ($state->mode === 'throw') {
                throw new \RuntimeException('end listener failed');
            }
            if ($state->mode === 'begin') {
                $state->mode = 'none';
                $db->beginTransaction();
                $db->insert(self::TABLE, ['id' => 5, 'name' => 'listener']);
            }
        });

        $db->beginTransaction();
        $fail('harmless_table');
        $db->rollback();
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST], $outcomes);
        $this->assertSame([[DatabaseInterface::TRANSACTION_LOST, 'end listener failed']], $reported, 'not thrown: told to the error hook');
        $this->assertFalse($this->pdo->reallyInTransaction());

        $state->mode = 'begin';
        $db->beginTransaction();
        $fail('harmless_table');
        $db->rollback();
        $this->assertTrue($this->pdo->reallyInTransaction(), "the listener's transaction is the listener's");
        $db->commit();
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST, DatabaseInterface::TRANSACTION_LOST, DatabaseInterface::TRANSACTION_COMMITTED], $outcomes);
        $this->assertSame([5], array_column($db->table(self::TABLE)->get(), 'id'));
    }

    private function makeFlaggingDriver(): FlaggingDriver
    {
        return new FlaggingDriver($this->pdo);
    }

    /**
     * The cleanup after a begin listener whose failed statement left the state unknown: an error
     * handler inside the failing ROLLBACK commits the transaction through the driver, and a commit
     * listener runs a transaction of its own before that end is told. The cleanup still sees that
     * the transaction it was for has ended: no 'lost' after its 'committed'.
     */
    public function testAListenersOwnTransactionDuringTheUnconfirmedCleanupDoesNotHideThatTheTransactionEnded(): void
    {
        $db = new AskingDriver($this->pdo);
        $db->answer = 'unknown';
        $pdo = $this->pdo;
        $ends = new Recorder(static fn (array $data): mixed => $data['outcome']);
        $db->on('transaction.end', $ends);
        $once = new class () {
            public bool $fail = true;

            public bool $audit = true;
        };
        $db->on('transaction.begin', static function () use ($db, $once): void {
            if ($once->fail) {
                $once->fail = false;
                $db->query('SELECT * FROM harmless_table'); // fails, and leaves the listener
            }
        });
        $db->on('transaction.commit', static function () use ($db, $once): void {
            if ($once->audit) {
                $once->audit = false;
                $db->transaction(static fn (DatabaseInterface $db) => $db->insert(self::TABLE, ['id' => 2, 'name' => 'audit']));
            }
        });
        $pdo->duringRollBack = static function () use ($db): void {
            $db->commit();
        };
        $pdo->rollBackReturnsFalse = true;

        try {
            $db->beginTransaction();
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            $pdo->rollBackReturnsFalse = false;
        }

        $this->assertSame(
            [DatabaseInterface::TRANSACTION_COMMITTED, DatabaseInterface::TRANSACTION_COMMITTED],
            $ends->all(),
            'the listener\'s transaction, then the one the handler committed - and no "lost" after it'
        );
        $this->assertFalse($this->pdo->reallyInTransaction());
    }

    /**
     * The same for an earlier failed commit of the very transaction transaction() began: the
     * callback tried to commit, caught the failure, and that exception is thrown again inside the
     * commit transaction() runs. It is still not the failure of that commit - it was thrown by
     * an earlier call of commit() - and keeps no outcome.
     */
    public function testAnEarlierFailedCommitOfTheSameTransactionThrownInsideTheCommitIsNotSettled(): void
    {
        $pdo = $this->pdo;
        $ends = new Recorder(static fn (array $data): array => [$data['outcome'], $data['error']]);
        $this->db->on('transaction.end', $ends);
        $seen = new class () {
            public ?CommitFailedException $earlier = null;
        };

        try {
            $this->db->transaction(static function (DatabaseInterface $db) use ($pdo, $seen): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $pdo->failCommit = true;
                try {
                    $db->commit();
                } catch (CommitFailedException $e) {
                    $seen->earlier = $e;
                    $pdo->throwFromCommit = $e;
                }
            });
            $this->fail('Expected the exception thrown inside the commit');
        } catch (CommitFailedException $e) {
            $this->assertSame($seen->earlier, $e);
            $this->assertNull($e->outcome, 'a commit() the callback issued itself');
        }

        $this->assertSame([[DatabaseInterface::TRANSACTION_ROLLED_BACK, $seen->earlier]], $ends->all());
        $this->assertFalse($this->pdo->reallyInTransaction());
    }

    /**
     * A statement that fails inside a 'transaction.begin' listener may have ended the transaction
     * that was just begun without PDO knowing (where a DDL statement commits, a failing one does too).
     * The driver is asked before PDO's report is trusted - after each listener, and before the
     * cleanup after a listener that threw. Gone: the begin fails and the end is 'lost'. Not to
     * be found out: the begin fails as well (the caller must not go on in what may be
     * autocommit), a ROLLBACK cleans up and the end is 'lost'. Still there: nothing changes.
     */
    public function testAFailedStatementInABeginListenerMakesTheDriverAsk(): void
    {
        $db = new AskingDriver($this->pdo);
        $fail = $this->failingQuery($db);
        $ends = new Recorder(static fn (array $data): array => [$data['outcome'], $data['error']]);
        $db->on('transaction.end', $ends);
        $listener = new class () {
            /** The table of the statement that fails in the listener; '' for none */
            public string $table = '';

            public bool $swallow = true;

            /** After the swallowed failure the listener ends the transaction on raw PDO */
            public bool $endRaw = false;
        };
        $pdo = $this->pdo;
        $db->on('transaction.begin', static function () use ($db, $pdo, $fail, $listener): void {
            if ($listener->table === '') {
                return;
            }
            if ($listener->swallow) {
                $fail($listener->table);
                if ($listener->endRaw) {
                    $pdo->rollBack();
                }

                return;
            }
            $db->query('SELECT * FROM ' . $listener->table);
        });

        // no statement failed: nothing is asked
        $db->beginTransaction();
        $this->assertSame(0, $db->asked);
        $db->rollback();
        $ends->clear();

        // swallowed, and the transaction is still there: asked once, the transaction is the caller's
        $listener->table = 'harmless_table';
        $db->beginTransaction();
        $this->assertSame(1, $db->asked);
        $this->assertTrue($this->pdo->reallyInTransaction());
        $db->rollback();
        $this->assertSame(2, $db->asked, 'the rollback asks about the same failure once more');
        $this->assertSame([[DatabaseInterface::TRANSACTION_ROLLED_BACK, null]], $ends->all());
        $ends->clear();

        // a failure that settles the matter by itself is not asked about: the transaction is over on
        // the server, the begin fails and is undone - its begin was told, so its end is: rolled back
        $listener->table = 'fatal_table';
        $sent = $this->pdo->rollBackCalls;
        try {
            $db->beginTransaction();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertStringContainsString('ended the transaction that was just begun on the server', $e->getDebugMessage() ?? '');
            $cause = $e->getPrevious();
            $this->assertInstanceOf(PDOException::class, $cause);
            $this->assertNotNull($e->sqlState);
            $this->assertSame($cause->errorInfo[0] ?? null, $e->sqlState, 'the begin failed: the codes of the cause are the caller\'s');
        }
        $this->assertSame(2, $db->asked);
        $this->assertSame($sent + 1, $this->pdo->rollBackCalls);
        $this->assertFalse($this->pdo->reallyInTransaction());
        $this->assertSame([[DatabaseInterface::TRANSACTION_ROLLED_BACK, $e]], $ends->all(), 'with the exception the caller gets');
        $ends->clear();

        // swallowed, and the transaction is gone: the begin fails, the end is 'lost'
        $listener->table = 'harmless_table';
        $db->answer = 'gone';
        $sent = $this->pdo->rollBackCalls;
        try {
            $db->beginTransaction();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertStringStartsWith('A transaction.begin listener ended the transaction that was just begun outside this driver', $e->getDebugMessage() ?? '');
            $this->assertSame([[DatabaseInterface::TRANSACTION_LOST, $e]], $ends->all());
        }
        $this->assertSame($sent, $this->pdo->rollBackCalls, 'nothing was sent for it');
        $ends->clear();
        $this->pdo->hideTransaction = false;
        $this->pdo->rollBack();

        // PDO reports no transaction anyway (ended on raw PDO after the failed statement): nothing to ask
        $asked = $db->asked;
        $listener->endRaw = true;
        try {
            $db->beginTransaction();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame($asked, $db->asked, 'not asked');
            $this->assertSame([[DatabaseInterface::TRANSACTION_LOST, $e]], $ends->all());
        }
        $listener->endRaw = false;
        $ends->clear();

        // thrown, and the transaction is gone: the listener's exception, the end is 'lost' with it
        $listener->swallow = false;
        try {
            $db->beginTransaction();
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame([[DatabaseInterface::TRANSACTION_LOST, $e]], $ends->all());
        }
        $this->assertTrue($this->pdo->reallyInTransaction(), 'nothing was sent for it');
        $ends->clear();
        $this->pdo->hideTransaction = false;
        $this->pdo->rollBack();

        // thrown, and the driver could not find out: the ROLLBACK is sent to clean up, the end is 'lost'
        $db->answer = 'unknown';
        $sent = $this->pdo->rollBackCalls;
        try {
            $db->beginTransaction();
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame([[DatabaseInterface::TRANSACTION_LOST, $e]], $ends->all());
        }
        $this->assertSame($sent + 1, $this->pdo->rollBackCalls);
        $this->assertFalse($this->pdo->reallyInTransaction());
        $ends->clear();

        // gone for certain after that ROLLBACK: no mark of a transaction that "may still be open" stays behind
        $this->pdo->beginTransaction();
        $db->commit();
        $this->assertSame([[DatabaseInterface::TRANSACTION_COMMITTED, null]], $ends->all());
        $ends->clear();

        // ... and that ROLLBACK fails as well, by throwing or by returning false: the transaction may
        // still be open, and its later rollback tells no second end
        foreach (['failRollBackAlways', 'rollBackReturnsFalse'] as $how) {
            $this->pdo->{$how} = true;
            try {
                $db->beginTransaction();
                $this->fail('Expected QueryException: ' . $how);
            } catch (QueryException $e) {
                $this->assertSame([[DatabaseInterface::TRANSACTION_LOST, $e]], $ends->all(), $how);
            }
            $this->assertTrue($this->pdo->reallyInTransaction(), $how);
            $this->pdo->{$how} = false;
            $ends->clear();
            $db->rollback();
            $this->assertFalse($this->pdo->reallyInTransaction(), $how);
            $this->assertSame([], $ends->all(), 'told already: ' . $how);
        }

        // ... and an error handler inside that failing ROLLBACK commits the transaction through the
        // driver: that commit tells the end, with the number of the begin; the cleanup tells nothing more
        $pdo->duringRollBack = static function () use ($db): void {
            $db->commit();
        };
        $pdo->rollBackReturnsFalse = true;
        $numbers = new Recorder(static fn (array $data): mixed => $data['transaction']);
        $db->on('transaction.end', $numbers);
        try {
            $db->beginTransaction();
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            $this->assertSame([[DatabaseInterface::TRANSACTION_COMMITTED, null]], $ends->all());
            $this->assertCount(1, $numbers->all());
            $this->assertIsInt($numbers->all()[0], 'the end the handler told carries the number of the begin, not the null of a transaction begun on raw PDO');
        }
        $pdo->rollBackReturnsFalse = false;
        $this->assertFalse($this->pdo->reallyInTransaction());
        $ends->clear();

        // swallowed, and the driver could not find out: the begin fails, cleaned up, 'lost' - on that
        // one answer: asked again, the driver might say "still there", and no end would be told
        $listener->swallow = true;
        $sent = $this->pdo->rollBackCalls;
        $asked = $db->asked;
        $db->answer = 'alive';
        $db->answers = ['unknown'];
        try {
            $db->beginTransaction();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to begin transaction', $e->getMessage());
            $this->assertStringContainsString('could not be asked whether the transaction that was just begun still exists', $e->getDebugMessage() ?? '');
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
            $this->assertNull($e->sqlState, 'nothing is certain about that transaction: no codes to act on');
            $this->assertSame([[DatabaseInterface::TRANSACTION_LOST, $e]], $ends->all());
            $this->assertIsInt($numbers->pop(), 'told with the number of its begin, read before the ROLLBACK cleared the mark');
        }
        $this->assertSame($asked + 1, $db->asked, 'asked once');
        $this->assertSame($sent + 1, $this->pdo->rollBackCalls);
        $this->assertFalse($this->pdo->reallyInTransaction());

        // nothing of it stays behind: the next transaction is an ordinary one
        $listener->table = '';
        $ends->clear();
        $before = $db->asked;
        $db->transaction(static fn (DatabaseInterface $db) => $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']));
        $this->assertSame(0, $db->asked - $before, 'nothing to ask about');
        $this->assertSame([[DatabaseInterface::TRANSACTION_COMMITTED, null]], $ends->all());
    }

    /**
     * @return \Closure(string): void Runs a query on a table that does not exist and swallows the failure
     */
    private function failingQuery(HookDriver $db): \Closure
    {
        return static function (string $table) use ($db): void {
            try {
                $db->query('SELECT * FROM ' . $table);
            } catch (QueryException) {
                // swallowed
            }
        };
    }
}
