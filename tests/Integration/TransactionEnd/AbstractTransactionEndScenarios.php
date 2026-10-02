<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\TransactionEnd;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Throwable;

/**
 * The everyday transaction scenarios and what can go wrong in them, run unchanged on every engine:
 * for each, the outcome 'transaction.end' reports, the exception the caller gets, that no
 * transaction is left open, and the data as a second connection sees it afterwards (SQLite in
 * memory has no second connection: the same one is read, once no transaction is open).
 *
 * The connection-trouble scenarios simulate what the driver reports (ScenarioPdo); the real engine
 * behaviour is measured elsewhere: deadlock, lock wait timeout, killed connection and CHAIN in
 * MySqlDriverIntegrationTest, terminated backend and a COMMIT rejected by a deferred constraint in
 * PostgresDriverIntegrationTest.
 */
abstract class AbstractTransactionEndScenarios extends TestCase
{
    protected const TABLE = 'end_scenarios';

    private const COMMITTED = DatabaseInterface::TRANSACTION_COMMITTED;
    private const ROLLED_BACK = DatabaseInterface::TRANSACTION_ROLLED_BACK;
    private const LOST = DatabaseInterface::TRANSACTION_LOST;

    protected DatabaseInterface $db;

    protected ScenarioPdo $pdo;

    protected ?DatabaseInterface $observer = null;

    /** @var list<string> */
    protected array $events = [];

    /** @var list<array{outcome: string, error: ?Throwable}> */
    protected array $ends = [];

    /** @var list<?string> What a CommitFailedException handed to the end listener said at that moment; null for every other error */
    protected array $outcomesSeen = [];

    /** @var list<array<string, mixed>> */
    protected array $errors = [];

    abstract protected function makeScenarioPdo(): ScenarioPdo;

    abstract protected function makeDriver(ScenarioPdo $pdo): DatabaseInterface;

    /** A second, independent connection; null when the engine cannot offer one (SQLite in memory). */
    abstract protected function makeObserver(): ?DatabaseInterface;

    abstract protected function createTableSql(): string;

    /**
     * Whether a failed statement aborts the whole transaction (PostgreSQL) or only itself (MySQL,
     * SQLite). A callback that swallows the failure and returns normally commits the earlier rows
     * on the latter; on PostgreSQL the commit is refused: the server would turn COMMIT into a
     * ROLLBACK and report success.
     */
    abstract protected function aFailedStatementAbortsTheTransaction(): bool;

    protected function setUp(): void
    {
        $this->pdo = $this->makeScenarioPdo();
        $this->db = $this->makeDriver($this->pdo);
        $this->db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->execute($this->createTableSql());
        $this->observer = $this->makeObserver();
        $this->events = [];
        $this->ends = [];
        $this->outcomesSeen = [];
        $this->errors = [];
        $this->db->on('transaction.commit', function (): void {
            $this->events[] = 'commit';
        });
        $this->db->on('transaction.rollback', function (): void {
            $this->events[] = 'rollback';
        });
        $this->db->on('transaction.end', function (array $data): void {
            $this->events[] = 'end';
            $this->ends[] = ['outcome' => (string) $data['outcome'], 'error' => $data['error'] instanceof Throwable ? $data['error'] : null];
            $this->outcomesSeen[] = $data['error'] instanceof CommitFailedException ? $data['error']->outcome : null;
        });
        $this->db->on('error', function (array $data): void {
            $this->errors[] = $data;
        });
    }

    protected function tearDown(): void
    {
        $this->pdo->failRollBackAlways = false;
        $this->pdo->failCommit = false;
        $this->pdo->commitReturnsFalse = false;
        $this->pdo->vanishOnFailedCommit = false;
        $this->pdo->hideTransaction = false;
        $this->pdo->stateUnreadable = false;
        try {
            if ($this->pdo->reallyInTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable) {
            // the server rolls back whatever is left when the connection closes below
        }
        // Closing the scenario connection first: a DROP through the observer must not wait on its locks
        unset($this->db, $this->pdo);
        if ($this->observer !== null) {
            $this->observer->execute('DROP TABLE IF EXISTS ' . self::TABLE);
            $this->observer = null;
        }
    }

    // ---- everyday cases --------------------------------------------------------------------------

    public function testCommitFiresCommittedAfterTheCommitListenersAndTheDataIsVisible(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->db->commit();

        $this->assertSame(['commit', 'end'], $this->events);
        $this->assertSame([['outcome' => self::COMMITTED, 'error' => null]], $this->ends);
        $this->assertVisible([1]);
    }

    public function testExplicitRollbackFiresRolledBackAndNothingIsVisible(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->db->rollback();

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => null]], $this->ends);
        $this->assertVisible([]);
    }

    public function testTransactionSuccessCommitsAndReturnsTheCallbacksValue(): void
    {
        $result = $this->db->transaction(static function (DatabaseInterface $db): string {
            $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
            $db->insert(self::TABLE, ['id' => 2, 'name' => 'b']);

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(['commit', 'end'], $this->events);
        $this->assertSame(self::COMMITTED, $this->ends[0]['outcome']);
        $this->assertVisible([1, 2]);
    }

    public function testACallbackExceptionRollsBackAndReachesTheCallerUnchanged(): void
    {
        $cause = new RuntimeException('domain rule violated');

        try {
            $this->db->transaction(static function (DatabaseInterface $db) use ($cause): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e, 'the very same exception instance');
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $cause]], $this->ends);
        $this->assertVisible([]);
    }

    public function testAFailingStatementInsideTheCallbackRollsBackWithThatQueryException(): void
    {
        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $db->insert('no_such_table_' . self::TABLE, ['id' => 1]);
            });
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
            $this->assertStringContainsString('no_such_table_', $e->getDebugMessage() ?? '', 'the driver error names the table');
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends, "the statement's exception is the error");
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertCount(1, $this->errors, 'the error hook saw the query error itself');
        $this->assertArrayNotHasKey('hook', $this->errors[0], 'and no end listener failure');
        $this->assertVisible([]);
    }

    public function testAConstraintViolationInsideTheCallbackRollsBackEverything(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'existing']);
        $this->events = [];
        $this->ends = [];

        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'new']);
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'duplicate']); // primary key violation
            });
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
            $this->assertStringStartsWith('23', (string) $e->getPrevious()?->getCode(), 'SQLSTATE class 23: integrity constraint violation');
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends);
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertVisible([1], 'the row inserted before the violation is gone too');
    }

    public function testASwallowedStatementErrorCommitsTheEarlierRowsOrRefusesTheCommit(): void
    {
        $swallowed = null;
        $callback = static function (DatabaseInterface $db) use (&$swallowed): void {
            $db->insert(self::TABLE, ['id' => 1, 'name' => 'before the error']);
            try {
                $db->insert('no_such_table_' . self::TABLE, ['id' => 1]);
            } catch (QueryException $e) {
                $swallowed = $e; // swallowed: the callback returns normally
            }
        };

        if (!$this->aFailedStatementAbortsTheTransaction()) {
            $this->db->transaction($callback);

            $this->assertSame(['commit', 'end'], $this->events);
            $this->assertSame(self::COMMITTED, $this->ends[0]['outcome']);
            $this->assertVisible([1], 'MySQL and SQLite roll back only the failed statement');

            return;
        }

        try {
            $this->db->transaction($callback);
            $this->fail('Expected CommitFailedException: the aborted transaction must not be reported as committed');
        } catch (CommitFailedException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertStringContainsString('The transaction is aborted', (string) $e->getDebugMessage());
            $this->assertNotNull($swallowed);
            $this->assertSame($swallowed->getPrevious(), $e->getPrevious(), 'the statement failure that aborted it');
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends);
            $this->assertSame(self::ROLLED_BACK, $e->outcome, 'refused before it was sent, rollback confirmed: nothing is committed');
            $this->assertSame([self::ROLLED_BACK], $this->outcomesSeen);
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertFalse($this->pdo->reallyInTransaction());
        $this->assertVisible([]);
    }

    public function testNestedTransactionInsideTheCallbackFailsToBeginAndTheOuterRollsBack(): void
    {
        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'outer']);
                $db->transaction(static function (DatabaseInterface $db): void {
                    $db->insert(self::TABLE, ['id' => 2, 'name' => 'inner']);
                });
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to begin transaction', $e->getMessage(), 'PDO allows no nested transaction');
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends, 'one end, for the outer transaction');
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertVisible([]);
    }

    public function testTheManualPatternRollsBackAFailedStatementWithOneEnd(): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
            $this->db->insert('no_such_table_' . self::TABLE, ['id' => 1]);
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            $this->db->rollback();
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => null]], $this->ends, 'an explicit rollback carries no error');
        $this->assertVisible([]);
    }

    public function testAFailingExplicitCommitFiresNothingAndTheLaterRollbackTellsOneEnd(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->pdo->failCommit = true;

        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertNull($e->outcome, 'not ended by the library');
        }
        $this->assertSame([], $this->events, 'the transaction is still the caller\'s to end');
        $this->assertTrue($this->db->inTransaction());

        $this->db->rollback();
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => null]], $this->ends);
        $this->assertNull($e->outcome, "the caller's own rollback does not write into the exception");
        $this->assertVisible([]);
    }

    public function testUpdateMultipleFiresEndForItsOwnTransaction(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'b']);
        $this->events = [];
        $this->ends = [];

        $this->assertSame(2, $this->db->updateMultiple(self::TABLE, [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]));
        $this->assertSame(['commit', 'end'], $this->events);

        try {
            $this->db->updateMultiple(self::TABLE, [['id' => 1, 'name' => 'x'], ['name' => 'no key']]);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Update failed', $e->getMessage());
            $this->assertSame([self::COMMITTED, self::ROLLED_BACK], array_column($this->ends, 'outcome'));
            $this->assertSame($e, $this->ends[1]['error']);
        }
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(['A', 'B'], array_column($this->rows(), 'name'), 'the first batch stayed, the second was rolled back');
    }

    public function testUpdateMultipleInsideATransactionTellsNoEndOfItsOwn(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->events = [];
        $this->ends = [];

        $this->db->transaction(static function (DatabaseInterface $db): void {
            $db->updateMultiple(self::TABLE, [['id' => 1, 'name' => 'A']]);
            $db->insert(self::TABLE, ['id' => 2, 'name' => 'b']);
        });

        $this->assertSame(['commit', 'end'], $this->events, 'one end: the outer transaction owns the batch');
        $this->assertSame(['A', 'b'], array_column($this->rows(), 'name'));
        $this->assertVisible([1, 2]);
    }

    // ---- listener failures ------------------------------------------------------------------------

    public function testACommitListenerFailureIsReportedAndTheDataStaysCommitted(): void
    {
        $failure = new RuntimeException('commit listener failed');
        $failOnce = true;
        $this->db->on('transaction.commit', static function () use ($failure, &$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw $failure;
            }
        });

        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
            });
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertSame($failure, $e->getPrevious());
            $this->assertSame([$failure], $e->failures);
            $this->assertFalse($e->connectionInTransaction);
        }
        $this->assertSame(['commit', 'end'], $this->events, 'the end still fired');
        $this->assertSame(self::COMMITTED, $this->ends[0]['outcome']);
        $this->assertVisible([1]);

        // the connection is reused, as in a worker: the next transaction ends normally
        $this->db->transaction(static function (DatabaseInterface $db): void {
            $db->insert(self::TABLE, ['id' => 2, 'name' => 'b']);
        });
        $this->assertSame(['commit', 'end', 'commit', 'end'], $this->events);
        $this->assertVisible([1, 2]);
    }

    public function testAnEndListenerFailureAfterTheCommitIsReportedAndTheDataStaysCommitted(): void
    {
        $failure = new LogicException('end listener failed');
        $this->db->on('transaction.end', static function () use ($failure): void {
            throw $failure;
        });

        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
            });
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertSame([$failure], $e->failures);
        }

        $this->assertSame([], $this->errors, 'not via the error hook on the commit path');
        $this->assertVisible([1]);
    }

    public function testAnEndListenerFailureOnTheAutomaticRollbackReachesOnlyTheErrorHook(): void
    {
        $cause = new RuntimeException('domain rule violated');
        $failure = new LogicException('end listener failed');
        $this->db->on('transaction.end', static function () use ($failure): void {
            throw $failure;
        });

        try {
            $this->db->transaction(static function (DatabaseInterface $db) use ($cause): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e, 'the end listener failure does not replace the cause');
        }

        $this->assertCount(1, $this->errors);
        $this->assertSame($failure, $this->errors[0]['exception']);
        $this->assertSame('transaction.end', $this->errors[0]['hook']);
        $this->assertSame(self::ROLLED_BACK, $this->errors[0]['outcome']);
        $this->assertVisible([]);
    }

    public function testAnEndListenerFailureAfterAnExplicitRollbackIsATransactionException(): void
    {
        $failure = new LogicException('end listener failed');
        $this->db->on('transaction.end', static function () use ($failure): void {
            throw $failure;
        });
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);

        try {
            $this->db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame($failure, $e->getPrevious());
            $this->assertStringContainsString('transaction.end listener failed', $e->getMessage());
        }

        $this->assertVisible([], 'the rollback itself went through');
    }

    public function testARollbackListenerFailureOnAnExplicitRollbackWinsAndTheEndStillFires(): void
    {
        $failure = new RuntimeException('rollback listener failed');
        $this->db->on('transaction.rollback', static function () use ($failure): void {
            throw $failure;
        });
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);

        try {
            $this->db->rollback();
            $this->fail('Expected the rollback listener exception');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertVisible([]);
    }

    // ---- transactions inside listeners -----------------------------------------------------------

    public function testATransactionStartedInACommitListenerEndsBeforeTheOuterEnd(): void
    {
        $started = false;
        $this->db->on('transaction.commit', function () use (&$started): void {
            if ($started) {
                return;
            }
            $started = true;
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'inner']);
            });
        });

        $this->db->transaction(static function (DatabaseInterface $db): void {
            $db->insert(self::TABLE, ['id' => 1, 'name' => 'outer']);
        });

        $this->assertSame(['commit', 'commit', 'end', 'end'], $this->events, 'inner commit and inner end before the outer end');
        $this->assertSame([self::COMMITTED, self::COMMITTED], array_column($this->ends, 'outcome'));
        $this->assertVisible([1, 2]);
    }

    public function testATransactionStartedInAnEndListenerEndsInsideThatListener(): void
    {
        $depth = 0;
        $started = false;
        $this->db->on('transaction.end', function () use (&$depth, &$started): void {
            $this->events[] = 'L1@' . $depth;
            if ($started) {
                return;
            }
            $started = true;
            $depth = 1;
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'inner']);
            });
            $depth = 0;
            $this->events[] = 'L1-done';
        });
        $this->db->on('transaction.end', function () use (&$depth): void {
            $this->events[] = 'L2@' . $depth;
        });

        $this->db->transaction(static function (DatabaseInterface $db): void {
            $db->insert(self::TABLE, ['id' => 1, 'name' => 'outer']);
        });

        $this->assertSame(['commit', 'end', 'L1@0', 'commit', 'end', 'L1@1', 'L2@1', 'L1-done', 'L2@0'], $this->events);
        $this->assertVisible([1, 2]);
    }

    public function testACommitListenerLeavingATransactionOpenGetsItsOwnEndAndItsDataIsRolledBack(): void
    {
        $once = false;
        $this->db->on('transaction.commit', function () use (&$once): void {
            if (!$once) {
                $once = true;
                $this->db->beginTransaction();
                $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'left open']);
            }
        });

        $leftOpen = null;
        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'outer']);
            });
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(1, $e->failures);
            $this->assertSame('listener left a transaction open', $e->failures[0]->getMessage());
            $this->assertFalse($e->connectionInTransaction, 'the raw cleanup succeeded');
            $leftOpen = $e->failures[0];
        }

        $this->assertSame(['commit', 'end', 'end'], $this->events, 'no rollback listener for the raw cleanup');
        $this->assertSame([self::ROLLED_BACK, self::COMMITTED], array_column($this->ends, 'outcome'), 'inner end first');
        $this->assertSame($leftOpen, $this->ends[0]['error']);
        $this->assertVisible([1], "the listener's row was rolled back, the outer row committed");
    }

    // ---- the callback ends the transaction itself ------------------------------------------------

    public function testACallbackThatRollsBackItselfTellsOneEnd(): void
    {
        $cause = new RuntimeException('after my own rollback');

        try {
            $this->db->transaction(static function (DatabaseInterface $db) use ($cause): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $db->rollback();
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }

        $this->assertSame(['rollback', 'end'], $this->events, 'no second end, no lost');
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => null]], $this->ends);
        $this->assertVisible([]);
    }

    public function testACallbackThatCommitsItselfTellsOneEndAndTheSecondCommitFailsLoudly(): void
    {
        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $db->commit();
            });
            $this->fail('Expected CommitFailedException: nothing left to commit');
        } catch (CommitFailedException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame(self::LOST, $e->outcome, 'no rollback confirmed: unclear for the caller, and here the data is in fact committed');
        }

        $this->assertSame(['commit', 'end'], $this->events);
        $this->assertSame(self::COMMITTED, $this->ends[0]['outcome'], 'the one end, told by the callback\'s commit');
        $this->assertVisible([1], "the callback's own commit stands");
    }

    /**
     * transaction() found nothing left to end after its failed commit and said 'lost'. Thrown
     * again inside a later transaction that is rolled back with it, the exception keeps that.
     */
    public function testAFailedCommitWithNothingLeftToEndIsNotRewrittenByALaterTransaction(): void
    {
        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $db->commit();
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $first) {
            $this->assertSame(self::LOST, $first->outcome);
        }

        $this->ends = [];
        try {
            $this->db->transaction(static function () use ($first): void {
                throw $first;
            });
            $this->fail('Expected the callback exception');
        } catch (CommitFailedException $e) {
            $this->assertSame($first, $e);
        }
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $first]], $this->ends);
        $this->assertSame(self::LOST, $first->outcome);
        $this->assertVisible([1]);
    }

    public function testACallbackThatRollsBackItselfAndReturnsFailsAtTheCommitAsLost(): void
    {
        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $db->rollback();
            });
            $this->fail('Expected CommitFailedException: nothing left to commit');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome, 'transaction() cannot know how the callback ended it: never null, fail-closed');
        }

        $this->assertSame(['rollback', 'end'], $this->events, 'the one end, told by the callback\'s rollback');
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => null]], $this->ends);
        $this->assertVisible([]);
    }

    /**
     * A commit() the callback calls itself is a direct commit: its exception says null, and stays
     * that way when it leaves the callback - it is the callback's exception then, like any other.
     * transaction() rolls back and tells that end with it as error; the outcome is in the event.
     */
    public function testAFailedCommitTheCallbackCalledItselfKeepsNoOutcomeWhenItEscapes(): void
    {
        $second = null;

        try {
            $this->db->transaction(function (DatabaseInterface $db) use (&$second): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
                try {
                    $db->commit(); // fails, the transaction stays open
                } catch (CommitFailedException $first) {
                    $this->pdo->failCommit = true;
                    try {
                        $db->commit(); // and once more
                    } catch (CommitFailedException $second) {
                        throw $first;
                    }
                }
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertNotSame($second, $e);
            $this->assertNull($e->outcome);
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends);
            $this->assertSame([null], $this->outcomesSeen);
        }
        $this->assertInstanceOf(CommitFailedException::class, $second);
        $this->assertNull($second->outcome);

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertVisible([]);
    }

    public function testATransactionBegunOnRawPdoAndEndedThroughTheLibraryFiresEnd(): void
    {
        $this->pdo->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->db->commit();

        $this->assertSame(['commit', 'end'], $this->events);
        $this->assertSame(self::COMMITTED, $this->ends[0]['outcome']);
        $this->assertVisible([1]);
    }

    // ---- what can go wrong with the connection (simulated through ScenarioPdo) -------------------

    public function testLostWhenTheRollbackFailsThenTheExplicitRollbackEndsItQuietly(): void
    {
        $cause = new RuntimeException('statement failed');

        try {
            $this->db->transaction(function (DatabaseInterface $db) use ($cause): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'uncommitted']);
                $this->pdo->failRollBackAlways = true;
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e, 'the cause reaches the caller, the failed rollback does not');
        }
        $this->assertSame(['end'], $this->events, 'no rollback listener: the rollback failed');
        $this->assertSame([['outcome' => self::LOST, 'error' => $cause]], $this->ends);
        $this->assertTrue($this->pdo->reallyInTransaction(), 'the transaction is in fact still open');
        $this->assertNotVisibleElsewhere(1);

        // the contract: end it with rollback() - its listeners run, no second end
        $this->pdo->failRollBackAlways = false;
        $this->db->rollback();
        $this->assertSame(['end', 'rollback'], $this->events);
        $this->assertFalse($this->pdo->reallyInTransaction());
        $this->assertVisible([]);

        // and the connection is usable again, with exactly one end per transaction
        $this->db->transaction(static function (DatabaseInterface $db): void {
            $db->insert(self::TABLE, ['id' => 2, 'name' => 'next']);
        });
        $this->assertSame([self::LOST, self::COMMITTED], array_column($this->ends, 'outcome'));
        $this->assertVisible([2]);
    }

    /**
     * The other way to end a transaction that was reported as lost but is in fact still open: a
     * commit(). The commit listeners run, the data is committed, and no second end is told.
     */
    public function testAfterLostALaterCommitOfTheStillOpenTransactionTellsNoSecondEnd(): void
    {
        $cause = new RuntimeException('statement failed');

        try {
            $this->db->transaction(function (DatabaseInterface $db) use ($cause): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'still open']);
                $this->pdo->failRollBackAlways = true;
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }
        $this->assertSame([['outcome' => self::LOST, 'error' => $cause]], $this->ends);
        $this->assertTrue($this->pdo->reallyInTransaction());

        $this->pdo->failRollBackAlways = false;
        $this->db->commit();

        $this->assertSame(['end', 'commit'], $this->events, 'the commit listeners run, no second end');
        $this->assertFalse($this->pdo->reallyInTransaction());
        $this->assertVisible([1]);
    }

    /**
     * updateMultiple() inside the caller's transaction does not manage it: a failing row reaches
     * the caller as QueryException, nothing is rolled back or told by the library, and the caller's
     * rollback ends the transaction with one end.
     */
    public function testUpdateMultipleFailingInsideTheCallersTransactionLeavesItToTheCaller(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'one']);
        $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'two']);
        $this->events = [];

        $this->db->beginTransaction();
        try {
            $this->db->updateMultiple(self::TABLE, [
                ['id' => 1, 'name' => 'changed'],
                ['id' => 2, 'no_such_column' => 'x'],
            ]);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
        }

        $this->assertSame([], $this->events, 'not the library\'s transaction: no rollback, no end');
        $this->assertTrue($this->pdo->reallyInTransaction());

        $this->db->rollback();
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => null]], $this->ends);
        $this->assertSame('one', $this->db->findOne(self::TABLE, ['id' => 1])['name'] ?? null, 'the first row\'s update is rolled back with the rest');
    }

    /**
     * A statement failure belongs to the transaction it happened in. When that one was ended on raw
     * PDO, a transaction begun on raw PDO afterwards commits through the library: where commit()
     * asks about an earlier failure, it asks the server, and the server has a transaction.
     */
    public function testAFailureOfATransactionEndedOnRawPdoDoesNotRefuseTheNextCommit(): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->insert('no_such_table_' . self::TABLE, ['id' => 1]);
        } catch (QueryException) {
            // swallowed
        }
        $this->pdo->rollBack();

        $this->pdo->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'next transaction']);
        $this->db->commit();

        $this->assertVisible([1]);
    }

    public function testLostWhenTheConnectionStateCannotBeRead(): void
    {
        $cause = new RuntimeException('statement failed');

        try {
            $this->db->transaction(function (DatabaseInterface $db) use ($cause): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'uncommitted']);
                $this->pdo->stateUnreadable = true;
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }
        $this->assertSame([['outcome' => self::LOST, 'error' => $cause]], $this->ends);
        $this->assertTrue($this->pdo->reallyInTransaction());

        $this->pdo->stateUnreadable = false;
        $this->db->rollback();
        $this->assertSame(['end', 'rollback'], $this->events, 'no second end');
        $this->assertVisible([]);
    }

    /**
     * The check for a chained transaction (MySQL completion_type=CHAIN) right after COMMIT and
     * ROLLBACK reads the connection state. A state that cannot be read is not taken for a chained
     * transaction: after a rollback nothing is reported, after a commit the commit listeners'
     * own state check reports it as before.
     */
    public function testAnUnreadableStateRightAfterCommitOrRollbackIsNotTakenForAChainedTransaction(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'rolled back']);
        $this->pdo->stateUnreadable = true;
        $this->db->rollback();
        $this->pdo->stateUnreadable = false;

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertFalse($this->pdo->reallyInTransaction());

        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'committed']);
        $this->pdo->stateUnreadable = true;
        try {
            $this->db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertSame('connection state unknown after listener', $e->failures[0]->getMessage());
        } finally {
            $this->pdo->stateUnreadable = false;
        }

        $this->assertSame(self::COMMITTED, $this->ends[1]['outcome']);
        $this->assertVisible([2]);
    }

    /**
     * Simulation only: the driver reports no transaction while one is in fact open. The real causes of
     * this path - a raw COMMIT or a MySQL DDL statement inside the callback - leave the data committed,
     * see the MySQL-only scenario; a COMMIT rejected by PostgreSQL is measured in
     * PostgresDriverIntegrationTest.
     */
    public function testLostWhenPdoNoLongerReportsTheTransaction(): void
    {
        $cause = new RuntimeException('statement failed');

        try {
            $this->db->transaction(function (DatabaseInterface $db) use ($cause): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'uncommitted']);
                $this->pdo->hideTransaction = true;
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }

        $this->assertSame(['end'], $this->events, 'no rollback was sent: nothing to roll back as far as the driver says');
        $this->assertSame([['outcome' => self::LOST, 'error' => $cause]], $this->ends);
        $this->assertTrue($this->pdo->reallyInTransaction(), 'the simulation leaves it open');
    }

    public function testAFailedCommitWithASuccessfulRollbackIsRolledBackAndNothingIsVisible(): void
    {
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
            });
            $this->fail('Expected TransactionException');
        } catch (CommitFailedException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame('commit failed (scenario)', $e->getDebugMessage());
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends, "the commit's exception is the error");
            $this->assertSame(self::ROLLED_BACK, $e->outcome);
            $this->assertSame([self::ROLLED_BACK], $this->outcomesSeen, 'the end listener reads the same value from the exception it is handed');
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertVisible([]);
    }

    public function testAFailedCommitAndAFailedRollbackIsLostAndTheCommitExceptionReachesTheCaller(): void
    {
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
                $this->pdo->failRollBackAlways = true;
            });
            $this->fail('Expected TransactionException');
        } catch (CommitFailedException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends, 'lost: the data may be committed, fail-closed');
            $this->assertSame(self::LOST, $e->outcome);
            $this->assertSame([self::LOST], $this->outcomesSeen);
        }
        $this->assertSame(['end'], $this->events, 'no rollback listener');
        $this->assertTrue($this->pdo->reallyInTransaction());
        $this->assertNotVisibleElsewhere(1);

        $this->pdo->failRollBackAlways = false;
        $this->db->rollback();
        $this->assertSame(['end', 'rollback'], $this->events, 'no second end');
        $this->assertSame(self::LOST, $e->outcome, 'the later explicit rollback does not turn it into rolled_back');
        $this->assertVisible([]);
    }

    /**
     * Simulation of what PostgreSQL leaves behind when COMMIT fails on a deferred constraint, and
     * of a commit after a raw COMMIT: the commit fails and PDO reports no transaction. Nothing
     * could end that transaction any more, so the failed commit itself tells its end - at once,
     * on a direct commit() as well, not only when the next transaction begins.
     */
    public function testAFailedCommitOfATransactionThatIsGoneTellsItsEndAsLostAtOnce(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->pdo->failCommit = true;
        $this->pdo->hideTransaction = true;

        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
            $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends);
            $this->assertSame([self::LOST], $this->outcomesSeen);
        }
        $this->assertSame(['end'], $this->events);

        // Told as gone for certain: what PDO reports from now on is another transaction (here the
        // simulation's still open one), and ending it through the library tells its own end
        $this->pdo->hideTransaction = false;
        $this->db->rollback();
        $this->assertSame(['end', 'rollback', 'end'], $this->events);
        $this->assertSame([self::LOST, self::ROLLED_BACK], array_column($this->ends, 'outcome'));
        $this->assertSame(self::LOST, $e->outcome);

        $this->db->beginTransaction();
        $this->db->commit();
        $this->assertSame(['end', 'rollback', 'end', 'commit', 'end'], $this->events, 'the next transaction finds no end owed');
    }

    public function testACommitThatReturnsFalseIsTreatedLikeOneThatThrows(): void
    {
        // still open: rolled back by transaction()
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->commitReturnsFalse = true;
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame('PDO::commit() returned false', $e->getDebugMessage());
            $this->assertSame(self::ROLLED_BACK, $e->outcome);
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends);
        }
        $this->assertVisible([]);

        // gone: a direct commit() tells its end at once
        $this->events = [];
        $this->ends = [];
        $this->db->beginTransaction();
        $this->pdo->commitReturnsFalse = true;
        $this->pdo->hideTransaction = true;
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
            $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends);
        }
        $this->assertSame(['end'], $this->events);
    }

    public function testTransactionTellsTheEndOfAFailedCommitThatIsGoneExactlyOnce(): void
    {
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
                $this->pdo->hideTransaction = true;
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
            $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends);
        }
        $this->assertSame(['end'], $this->events, 'one end, no rollback sent');
        $this->assertTrue($this->pdo->reallyInTransaction(), 'the simulation leaves it open');
    }

    /**
     * The end of the vanished transaction is told inside commit(); a transaction PDO reports
     * afterwards is one an end listener began. transaction() must not roll that one back as if
     * it were its own, and the exception keeps the 'lost' it was told with.
     */
    public function testATransactionAnEndListenerBeginsAfterAFailedCommitIsNotEndedByTransaction(): void
    {
        $this->db->on('transaction.end', function (array $data): void {
            if ($data['outcome'] === self::LOST) {
                $this->pdo->hideTransaction = false; // PDO reports a transaction again: as if this listener had begun one
            }
        });

        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
                $this->pdo->hideTransaction = true;
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
        }
        $this->assertSame(['end'], $this->events, 'no rollback: that transaction is not this call\'s to end');
        $this->assertTrue($this->pdo->reallyInTransaction());
    }

    /**
     * The same through a commit the callback called itself: its exception leaves the callback, and
     * the transaction PDO reports then is not the one transaction() began - that one's end was told
     * as 'lost'. transaction() leaves it alone, and the exception keeps its 'lost'.
     */
    public function testATransactionAnEndListenerBeginsAfterTheCallbacksOwnFailedCommitIsLeftAlone(): void
    {
        $this->db->on('transaction.end', function (array $data): void {
            if ($data['outcome'] === self::LOST) {
                $this->pdo->hideTransaction = false; // PDO reports a transaction again: as if this listener had begun one
            }
        });

        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
                $this->pdo->hideTransaction = true;
                $db->commit();
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
        }
        $this->assertSame(['end'], $this->events, 'no rollback: that transaction is not this call\'s to end');
        $this->assertSame([self::LOST], array_column($this->ends, 'outcome'));
        $this->assertSame([self::LOST], $this->outcomesSeen);
        $this->assertTrue($this->pdo->reallyInTransaction());
    }

    /**
     * The callback committed itself, and a commit listener left a transaction of its own behind
     * whose state could not be read: told as 'lost', it may still be open. It is not the one
     * transaction() began. Nothing is sent for it - no COMMIT, no ROLLBACK -, and the call fails
     * for its own transaction, which is over.
     */
    public function testATransactionACommitListenerLeftBehindAfterTheCallbacksOwnCommitIsLeftAlone(): void
    {
        $once = false;
        $this->db->on('transaction.commit', function () use (&$once): void {
            if (!$once) {
                $once = true;
                $this->db->beginTransaction(); // left open
                $this->pdo->stateUnreadable = true;
            }
        });
        $this->db->on('transaction.end', function (): void {
            $this->pdo->stateUnreadable = false;
        });

        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                try {
                    $db->commit();
                } catch (CommitHookException) {
                    // committed; the listener's transaction is still open
                }
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
            $this->assertNull($e->getPrevious(), 'refused: no COMMIT was sent');
        }

        $this->assertSame(['commit', 'end', 'end'], $this->events, "the listener's transaction as lost, the callback's as committed; nothing after that");
        $this->assertSame([self::LOST, self::COMMITTED], array_column($this->ends, 'outcome'));
        $this->assertTrue($this->pdo->reallyInTransaction(), "the listener's transaction is the listener's to end");
        $this->pdo->rollBack();
        $this->assertVisible([1], "the callback's own commit stands");
    }

    /**
     * The outcome belongs to the transaction whose commit failed: the first end told with the
     * exception. A caller that throws the same exception again inside a later transaction does
     * not get it rewritten by that transaction's end.
     */
    public function testAnOutcomeOnceToldIsNotRewrittenByALaterTransactionCarryingTheSameException(): void
    {
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $first) {
            $this->assertSame(self::ROLLED_BACK, $first->outcome);
        }

        try {
            $this->db->transaction(function () use ($first): void {
                $this->pdo->failRollBackAlways = true;
                throw $first;
            });
            $this->fail('Expected the callback exception');
        } catch (CommitFailedException $e) {
            $this->assertSame($first, $e);
        }
        $this->assertSame([self::ROLLED_BACK, self::LOST], array_column($this->ends, 'outcome'));
        $this->assertSame($first, $this->ends[1]['error']);
        $this->assertSame(self::ROLLED_BACK, $first->outcome);

        $this->pdo->failRollBackAlways = false;
        $this->db->rollback();
        $this->assertVisible([]);
    }

    /**
     * The outcome is the business of the connection whose commit failed. A callback that lets
     * the failed commit of another connection escape gets this connection rolled back - told with
     * that exception as error - but the exception still says what its own connection said: null,
     * its transaction is open there.
     */
    public function testAFailedCommitOfAnotherConnectionIsNotGivenThisConnectionsOutcome(): void
    {
        $otherPdo = $this->makeScenarioPdo();
        $other = $this->makeDriver($otherPdo);

        try {
            $this->db->transaction(function (DatabaseInterface $db) use ($other, $otherPdo): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $other->beginTransaction();
                $otherPdo->failCommit = true;
                $other->commit();
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertNull($e->outcome, 'not this connection\'s to say');
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends);
            $this->assertTrue($other->inTransaction(), 'still open on the other connection');
        }
        $this->assertVisible([]);

        // the same when this connection's end is 'lost'
        $this->ends = [];
        try {
            $this->db->transaction(function () use ($e): void {
                $this->pdo->failRollBackAlways = true;
                throw $e;
            });
            $this->fail('Expected the callback exception');
        } catch (CommitFailedException $again) {
            $this->assertSame($e, $again);
            $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends);
            $this->assertNull($e->outcome);
        } finally {
            $this->pdo->failRollBackAlways = false;
            $this->db->rollback();
            $otherPdo->rollBack();
        }
    }

    /**
     * A commit() the callback called itself fails, the callback rolls back and lets the exception
     * escape: transaction() finds nothing left to end. The callback ended the transaction, with
     * an explicit rollback that tells its end without an error - the exception keeps no outcome.
     */
    public function testAFailedCommitTheCallbackCleanedUpItselfKeepsNoOutcome(): void
    {
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
                try {
                    $db->commit();
                } catch (CommitFailedException $e) {
                    $db->rollback();
                    throw $e;
                }
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertNull($e->outcome, 'not ended with this exception');
        }
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => null]], $this->ends, "the callback's own rollback told the end, without an error");
        $this->assertVisible([]);
    }

    /**
     * A transaction begun on raw PDO and committed through the library tells an end when the
     * commit goes through - so it does when the commit fails and takes the transaction with it
     * (PostgreSQL after a COMMIT rejected by a deferred constraint): 'lost', at once.
     */
    public function testAFailedCommitThatEndsARawBegunTransactionTellsItsEndAsLost(): void
    {
        $this->pdo->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->pdo->failCommit = true;
        $this->pdo->vanishOnFailedCommit = true;

        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
            $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends);
        }
        $this->assertSame(['end'], $this->events);

        // no transaction was open when the commit was sent: nothing to tell
        $this->pdo->hideTransaction = false;
        $this->pdo->rollBack();
        $this->events = [];
        $this->ends = [];
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertNull($e->outcome);
        }
        $this->assertSame([], $this->events);

        // whether one was open could not be read when the commit was sent: no "yes", nothing to tell
        $this->pdo->beginTransaction();
        $this->pdo->stateUnreadable = true;
        $this->pdo->failCommit = true;
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertNull($e->outcome);
        }
        $this->assertSame([], $this->events);
        $this->assertFalse($this->pdo->stateUnreadable, 'readable again, and reporting none');
    }

    /**
     * The end of a transaction that may still be open was told as 'lost' (its rollback failed).
     * A commit sent then fails and the transaction is gone: its end is told already, no second one.
     */
    public function testAFailedCommitAfterALostTransactionTellsNoSecondEnd(): void
    {
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failRollBackAlways = true;
                throw new RuntimeException('callback failed');
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException) {
            $this->assertSame([self::LOST], array_column($this->ends, 'outcome'));
        }
        $this->assertTrue($this->pdo->reallyInTransaction());

        $this->pdo->failRollBackAlways = false;
        $this->pdo->failCommit = true;
        $this->pdo->vanishOnFailedCommit = true;
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertNull($e->outcome, 'no end was told with it');
        }
        $this->assertSame(['end'], $this->events, 'still the one end');
    }

    /**
     * The rollback after a failed commit goes through and a rollback listener throws: the
     * transaction has ended and told its end once, as 'rolled_back'. The listener's exception is
     * swallowed (the commit's reaches the caller) and must not be taken for a failed rollback.
     */
    public function testAThrowingRollbackListenerAfterAFailedCommitTellsNoSecondEnd(): void
    {
        $this->db->on('transaction.rollback', static function (): void {
            throw new RuntimeException('rollback listener failed');
        });

        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::ROLLED_BACK, $e->outcome);
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends, 'one end');
            $this->assertSame([self::ROLLED_BACK], $this->outcomesSeen);
        }
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertVisible([]);
    }

    /**
     * A commit fails, the caller commits again and succeeds - and later throws the old exception
     * once more, inside another transaction (a retry wrapper that reports "the last error").
     * That transaction is rolled back with it as error, but the exception is about the earlier
     * commit, whose data is in the database: it gets no 'rolled_back'.
     */
    public function testAnOlderFailedCommitThrownAgainLaterGetsNoOutcomeOfTheLaterTransaction(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->pdo->failCommit = true;
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $old) {
            $this->assertNull($old->outcome);
        }
        $this->db->commit(); // the second attempt goes through
        $this->assertVisible([1]);

        $this->ends = [];
        try {
            $this->db->transaction(static function (DatabaseInterface $db) use ($old): void {
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'b']);
                throw $old;
            });
            $this->fail('Expected the callback exception');
        } catch (CommitFailedException $e) {
            $this->assertSame($old, $e);
        }
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $old]], $this->ends);
        $this->assertNull($old->outcome, 'the commit it reports was committed on the second attempt');
        $this->assertVisible([1]);
    }

    /**
     * The same exception thrown again after its transaction was ended on raw PDO, where the
     * library saw no end: the next transaction begins with a clean slate.
     */
    public function testAFailedCommitOfATransactionEndedOnRawPdoGetsNoOutcomeLater(): void
    {
        $this->pdo->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->pdo->failCommit = true;
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $old) {
            $this->assertNull($old->outcome);
        }
        $this->pdo->rollBack();

        try {
            $this->db->transaction(static function () use ($old): void {
                throw $old;
            });
            $this->fail('Expected the callback exception');
        } catch (CommitFailedException $e) {
            $this->assertSame($old, $e);
        }
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $old]], $this->ends);
        $this->assertNull($old->outcome);
    }

    /**
     * A commit listener runs a transaction of its own, whose commit fails; the listener catches
     * that. What is left of the listener's transaction is cleaned up by the library - raw, and
     * told with another error. Thrown later by the callback, that old exception is nobody's
     * failed commit any more: it keeps no outcome, however the listener left its transaction.
     */
    public function testAFailedCommitInsideACommitListenerGetsNoOutcomeFromALaterEnd(): void
    {
        $leftovers = [
            'left open' => function (): void {
            },
            'rolled back on raw PDO' => function (): void {
                $this->pdo->rollBack();
            },
            'state unreadable afterwards' => function (): void {
                $this->pdo->stateUnreadable = true;
            },
        ];
        $this->db->on('transaction.end', function (): void {
            $this->pdo->stateUnreadable = false;
        });
        $leftover = null;
        $caught = null;
        $armed = false;
        $this->db->on('transaction.commit', function () use (&$leftover, &$caught, &$armed): void {
            if (!$armed) {
                return;
            }
            $armed = false;
            $this->db->beginTransaction();
            $this->pdo->failCommit = true;
            try {
                $this->db->commit();
            } catch (CommitFailedException $e) {
                $caught = $e;
            }
            $leftover();
        });

        foreach ($leftovers as $how => $leftover) {
            $caught = null;
            $armed = true;
            try {
                $this->db->transaction(function (DatabaseInterface $db) use (&$caught): void {
                    try {
                        $db->commit();
                    } catch (CommitHookException) {
                        // the callback's transaction is committed; the listener's leftover was dealt with
                    }
                    $this->assertInstanceOf(CommitFailedException::class, $caught);
                    throw $caught;
                });
                $this->fail('Expected CommitFailedException: ' . $how);
            } catch (CommitFailedException $e) {
                $this->assertSame($caught, $e, $how);
                $this->assertNull($e->outcome, $how);
            }
            if ($this->pdo->reallyInTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    /**
     * The same for the listeners that run when a transaction ends: an end or rollback listener
     * runs a transaction on raw PDO, its commit through the library fails, the listener catches
     * that and rolls back raw. Thrown later by the callback, that exception is no failed commit
     * of the callback's transaction: no outcome - whichever way the transaction before it ended.
     */
    public function testAFailedCommitInsideAnEndOrRollbackListenerGetsNoOutcomeFromALaterEnd(): void
    {
        $armed = null;
        $caught = null;
        $rawTransactionWithAFailedCommit = function (string $event) use (&$armed, &$caught): void {
            if ($armed !== $event) {
                return;
            }
            $armed = null;
            $this->pdo->hideTransaction = false;
            if ($this->pdo->reallyInTransaction()) {
                $this->pdo->rollBack(); // what the 'lost' simulation left open
            }
            $this->pdo->beginTransaction();
            $this->pdo->failCommit = true;
            $this->pdo->vanishOnFailedCommit = false; // this one stays open: the failed commit tells nothing
            try {
                $this->db->commit();
            } catch (CommitFailedException $e) {
                $caught = $e;
            }
            $this->pdo->rollBack();
        };
        $this->db->on('transaction.end', static fn () => $rawTransactionWithAFailedCommit('transaction.end'));
        $this->db->on('transaction.rollback', static fn () => $rawTransactionWithAFailedCommit('transaction.rollback'));

        $endings = [
            'an end listener after a commit' => function (DatabaseInterface $db) use (&$armed): void {
                $armed = 'transaction.end';
                $db->commit();
            },
            'a rollback listener' => function (DatabaseInterface $db) use (&$armed): void {
                $armed = 'transaction.rollback';
                $db->rollback();
            },
            'an end listener after a rollback' => function (DatabaseInterface $db) use (&$armed): void {
                $armed = 'transaction.end';
                $db->rollback();
            },
            'an end listener after a lost end' => function (DatabaseInterface $db) use (&$armed): void {
                $armed = 'transaction.end';
                $this->pdo->failCommit = true;
                $this->pdo->vanishOnFailedCommit = true;
                try {
                    $db->commit();
                } catch (CommitFailedException) {
                    // told as lost; the listener ran
                }
            },
        ];

        foreach ($endings as $how => $end) {
            $caught = null;
            try {
                $this->db->transaction(function (DatabaseInterface $db) use ($end, &$caught): void {
                    $end($db);
                    $this->assertInstanceOf(CommitFailedException::class, $caught);
                    throw $caught;
                });
                $this->fail('Expected CommitFailedException: ' . $how);
            } catch (CommitFailedException $e) {
                $this->assertSame($caught, $e, $how);
                $this->assertNull($e->outcome, $how);
            }
            $this->assertFalse($this->pdo->reallyInTransaction(), $how);
        }
    }

    /**
     * While transaction() ends the transaction of its failed commit, an end listener is handed
     * that exception - and throws it again inside a transaction of its own. Whatever becomes of
     * the listener's transaction, the outcome told for the failed commit's transaction stays.
     */
    public function testAnEndListenerThatThrowsTheFailedCommitAgainDoesNotRewriteItsOutcome(): void
    {
        $inner = null;
        $this->db->on('transaction.end', function (array $data) use (&$inner): void {
            if ($inner === null || !$data['error'] instanceof CommitFailedException) {
                return;
            }
            $prepare = $inner;
            $inner = null;
            $prepare();
            try {
                $this->db->transaction(static function () use ($data): void {
                    throw $data['error'];
                });
            } catch (CommitFailedException) {
                // the listener's own business
            }
        });

        // rolled back - then the listener's transaction is lost with the same exception
        $inner = function (): void {
            $this->pdo->failRollBackAlways = true;
        };
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::ROLLED_BACK, $e->outcome);
            $this->assertSame([self::ROLLED_BACK, self::LOST], array_column($this->ends, 'outcome'));
            $this->assertSame($e, $this->ends[1]['error']);
        }
        $this->pdo->failRollBackAlways = false;
        $this->db->rollback();

        // lost - then the listener's transaction is rolled back with the same exception
        $this->ends = [];
        $inner = function (): void {
            $this->pdo->failRollBackAlways = false;
            $this->pdo->rollBack(); // what the failed rollback left open
        };
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'b']);
                $this->pdo->failCommit = true;
                $this->pdo->failRollBackAlways = true;
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
            $this->assertSame([self::LOST, self::ROLLED_BACK], array_column($this->ends, 'outcome'));
            $this->assertSame($e, $this->ends[1]['error']);
        }
        $this->assertVisible([]);
    }

    public function testUpdateMultipleReportsTheOutcomeOfItsFailedCommit(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->events = [];
        $this->ends = [];
        $this->outcomesSeen = [];
        $this->pdo->failCommit = true;

        try {
            $this->db->updateMultiple(self::TABLE, [['id' => 1, 'name' => 'A']]);
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::ROLLED_BACK, $e->outcome);
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends);
        }
        $this->assertSame(['a'], array_column($this->rows(), 'name'));
    }

    /**
     * A commit() with nothing to commit fails, and tells no end: none is owed. (transaction() no
     * longer gets there after a callback that committed itself; a caller's second commit() does.)
     */
    public function testASecondCommitAfterTheCommitFailsAndTellsNoEnd(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->db->commit();

        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException: nothing to commit');
        } catch (CommitFailedException $e) {
            $this->assertNull($e->outcome, 'no end was told with it');
        }
        $this->assertSame(['commit', 'end'], $this->events, 'the one end of the one transaction');
        $this->assertVisible([1]);
    }

    // ---- whose transaction is it ---------------------------------------------------------------

    /**
     * The callback rolled its transaction back itself, and an end listener began one of its own in
     * response. That one is not transaction()'s: it is not committed when the callback returns.
     */
    public function testATransactionAnEndListenerBeganIsNotCommittedForTheCallback(): void
    {
        $this->beginInAnEndListenerOnce();

        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $db->rollback();
            });
            $this->fail('Expected CommitFailedException: the transaction this call began is over');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
            $this->assertNull($e->getPrevious(), 'refused: no COMMIT was sent');
            $this->assertStringStartsWith('Not committed: the transaction this call began has already been ended through this driver', (string) $e->getDebugMessage());
        }

        $this->assertSame(['rollback', 'end'], $this->events, "the listener's transaction was neither committed nor rolled back");
        $this->assertTrue($this->pdo->reallyInTransaction(), 'still open: the listener\'s to end');
        $this->assertNotVisibleElsewhere(2);
        $this->db->rollback();
        $this->assertSame(['rollback', 'end', 'rollback', 'end'], $this->events);
        $this->assertVisible([]);
    }

    /**
     * The same when the callback throws after its own rollback: the listener's transaction is not
     * rolled back in the callback's name.
     */
    public function testATransactionAnEndListenerBeganIsNotRolledBackForTheCallback(): void
    {
        $this->beginInAnEndListenerOnce();
        $cause = new RuntimeException('after my own rollback');

        try {
            $this->db->transaction(static function (DatabaseInterface $db) use ($cause): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $db->rollback();
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertTrue($this->pdo->reallyInTransaction());
        $this->db->commit();
        $this->assertSame(['rollback', 'end', 'commit', 'end'], $this->events);
        $this->assertVisible([2], "the listener's row, committed by whoever owns that transaction");
    }

    /**
     * updateMultiple() ends only the transaction it began: an 'error' listener that rolls it back
     * and begins another keeps that other one, and so does a 'query' listener when the batch
     * goes through.
     */
    public function testUpdateMultipleLeavesATransactionAListenerBeganAlone(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->events = [];
        $takeOver = function (): void {
            $this->db->rollback();
            $this->db->beginTransaction();
            $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'listener']);
        };
        $armed = 'error';
        $this->db->on('error', static function () use (&$armed, $takeOver): void {
            if ($armed === 'error') {
                $armed = null;
                $takeOver();
            }
        });
        $this->db->on('query', static function (array $data) use (&$armed, $takeOver): void {
            if ($armed === 'query' && str_starts_with((string) $data['sql'], 'UPDATE')) {
                $armed = null;
                $takeOver();
            }
        });

        try {
            $this->db->updateMultiple(self::TABLE, [['id' => 1, 'name' => 'x'], ['id' => 1, 'no_such_column' => 'y']]);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame(['rollback', 'end'], $this->events, "the listener's rollback; nothing for the transaction it began");
        }
        $this->assertTrue($this->pdo->reallyInTransaction());
        $this->db->commit();
        $this->assertVisible([1, 2]);
        $this->assertSame(['a', 'listener'], array_column($this->rows(), 'name'));

        $this->db->delete(self::TABLE, ['id' => 2]);
        $this->events = [];
        $armed = 'query';
        try {
            $this->db->updateMultiple(self::TABLE, [['id' => 1, 'name' => 'x']]);
            $this->fail('Expected CommitFailedException: the batch\'s transaction is over');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertTrue($this->pdo->reallyInTransaction());
        $this->db->rollback();
        $this->assertVisible([1]);
        $this->assertSame(['a'], array_column($this->rows(), 'name'), 'the update was rolled back by the listener');
    }

    /**
     * A callback that commits and begins a second transaction: the second one is the callback's.
     * transaction() neither commits it on return nor rolls it back on a throw - 'rolled_back' at
     * its exception would speak of data that is committed.
     */
    public function testASecondTransactionTheCallbackBeginsIsTheCallbacksToEnd(): void
    {
        $restart = static function (DatabaseInterface $db): void {
            $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
            $db->commit();
            $db->beginTransaction();
            $db->insert(self::TABLE, ['id' => 2, 'name' => 'b']);
        };

        try {
            $this->db->transaction($restart);
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome, 'not rolled_back: the first transaction is committed');
        }
        $this->assertSame(['commit', 'end'], $this->events);
        $this->assertTrue($this->pdo->reallyInTransaction());
        $this->assertNotVisibleElsewhere(2);
        $this->db->rollback();
        $this->assertVisible([1]);

        $this->db->delete(self::TABLE, ['id' => 1]);
        $this->events = [];
        $cause = new RuntimeException('in the second transaction');
        try {
            $this->db->transaction(static function (DatabaseInterface $db) use ($restart, $cause): void {
                $restart($db);
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }
        $this->assertSame(['commit', 'end'], $this->events, 'no rollback in the name of a transaction that is committed');
        $this->assertTrue($this->pdo->reallyInTransaction());
        $this->db->commit();
        $this->assertVisible([1, 2]);
    }

    /**
     * The same when what is open afterwards was begun on raw PDO: the transaction this call began
     * was ended through the driver, so the raw one is not it.
     */
    public function testATransactionBegunOnRawPdoAfterTheCallbacksOwnRollbackIsLeftAlone(): void
    {
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $db->rollback();
                $this->pdo->beginTransaction();
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'raw']);
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
            $this->assertNull($e->getPrevious(), 'refused: no COMMIT was sent');
        }
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertTrue($this->pdo->reallyInTransaction());
        $this->assertNotVisibleElsewhere(2);
        $this->pdo->rollBack();
        $this->assertVisible([]);
    }

    /**
     * beginTransaction() tells the end of a transaction that ended behind the library's back. An
     * end listener that answers with a transaction of its own which ends the same way gets that
     * end told too, before the new transaction begins - it used to be buried.
     */
    public function testAnEndListenersTransactionThatEndsOutsideTheLibraryIsToldBeforeTheNextBegins(): void
    {
        $this->endOnRawPdoInAnEndListener(times: 1);

        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->pdo->commit(); // behind the library's back

        $this->db->beginTransaction();
        $this->assertSame(['end', 'end'], $this->events, 'the first transaction, then the listener\'s');
        $this->assertSame([self::LOST, self::LOST], array_column($this->ends, 'outcome'));
        $this->assertTrue($this->pdo->reallyInTransaction());

        $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'b']);
        $this->db->commit();
        $this->assertSame(['end', 'end', 'commit', 'end'], $this->events);
        $this->assertVisible([1, 2]);
    }

    /**
     * Listeners that answer every such end with another transaction of that kind do not keep
     * beginTransaction() in a loop: after two ends it throws, begins nothing, and the next call
     * tells the end that is still owed.
     */
    public function testEndListenersThatKeepLeavingSuchATransactionBehindStopTheBegin(): void
    {
        $left = $this->endOnRawPdoInAnEndListener(times: 5);

        $this->db->beginTransaction();
        $this->pdo->commit();

        try {
            $this->db->beginTransaction();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to begin transaction', $e->getMessage());
            $this->assertStringStartsWith('Not begun: the transaction.end listeners keep leaving behind', (string) $e->getDebugMessage());
        }
        $this->assertSame([self::LOST, self::LOST], array_column($this->ends, 'outcome'), 'two ends told, the third transaction still owes its');
        $this->assertFalse($this->pdo->reallyInTransaction(), 'nothing was begun');

        $left->times = 0;
        $this->db->beginTransaction();
        $this->assertSame([self::LOST, self::LOST, self::LOST], array_column($this->ends, 'outcome'));
        $this->db->rollback();
        $this->assertSame(['end', 'end', 'end', 'rollback', 'end'], $this->events);
        $this->assertVisible([]);
    }

    /**
     * A 'transaction.begin' listener that ends the transaction it was told about leaves the
     * caller without one: beginTransaction() fails, and transaction() does not run the callback
     * outside of a transaction - or inside one an end listener began in response.
     */
    public function testABeginListenerThatEndsTheTransactionMakesTheBeginFail(): void
    {
        $armed = true;
        $this->db->on('transaction.begin', function () use (&$armed): void {
            if ($armed) {
                $armed = false;
                $this->db->rollback();
            }
        });
        $ran = false;

        try {
            $this->db->transaction(static function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertNotInstanceOf(CommitFailedException::class, $e);
            $this->assertSame('Failed to begin transaction', $e->getMessage());
            $this->assertStringStartsWith('A transaction.begin listener ended the transaction that was just begun', (string) $e->getDebugMessage());
        }
        $this->assertFalse($ran, 'the callback did not run outside of a transaction');
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertFalse($this->pdo->reallyInTransaction());

        // updateMultiple() does not send its updates in autocommit either
        $this->db->insert(self::TABLE, ['id' => 9, 'name' => 'before']);
        $armed = true;
        try {
            $this->db->updateMultiple(self::TABLE, [['id' => 9, 'name' => 'after']]);
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertStringStartsWith('A transaction.begin listener ended the transaction that was just begun', (string) $e->getDebugMessage());
        }
        $this->assertSame(['before'], array_column($this->rows(), 'name'));
        $this->db->delete(self::TABLE, ['id' => 9]);

        // the same with a transaction an end listener begins in response: not the caller's
        $this->events = [];
        $armed = true;
        $this->beginInAnEndListenerOnce();
        try {
            $this->db->transaction(static function (DatabaseInterface $db) use (&$ran): void {
                $ran = true;
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertNotInstanceOf(CommitFailedException::class, $e);
            $this->assertStringStartsWith('A transaction.begin listener ended the transaction that was just begun', (string) $e->getDebugMessage());
        }
        $this->assertFalse($ran);
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertTrue($this->pdo->reallyInTransaction(), "the listener's transaction, left to the listener");
        $this->assertNotVisibleElsewhere(2);
        $this->db->rollback();
        $this->assertVisible([]);
    }

    /**
     * Once a 'transaction.begin' listener has ended the transaction, no further begin listener
     * runs: it would write outside of any transaction.
     */
    public function testNoBeginListenerRunsAfterOneThatEndedTheTransaction(): void
    {
        $armed = true;
        $this->db->on('transaction.begin', function () use (&$armed): void {
            if ($armed) {
                $this->db->rollback();
            }
        });
        $this->db->on('transaction.begin', function (): void {
            $this->db->insert(self::TABLE, ['id' => 3, 'name' => 'second begin listener']);
        });

        try {
            $this->db->beginTransaction();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertStringStartsWith('A transaction.begin listener ended the transaction that was just begun', (string) $e->getDebugMessage());
        }
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertVisible([], 'the second listener did not write in autocommit');

        $armed = false;
        $this->db->transaction(static fn (): null => null);
        $this->assertVisible([3], 'both listeners run for a transaction that stays open');
    }

    /**
     * A 'transaction.begin' listener that ends the transaction behind the library's back (here: on
     * raw PDO; on MySQL/MariaDB a DDL statement does the same): PDO reports none any more. Its end
     * is told as 'lost', the begin fails, and the callback does not run in autocommit.
     */
    public function testABeginListenerThatEndsTheTransactionOnRawPdoMakesTheBeginFailAsLost(): void
    {
        $written = false;
        $this->db->on('transaction.begin', function () use (&$written): void {
            if (!$written) {
                $written = true;
                $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'listener']);
            }
            $this->pdo->commit();
        });
        $ran = false;

        try {
            $this->db->transaction(static function (DatabaseInterface $db) use (&$ran): void {
                $ran = true;
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'callback']);
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertNotInstanceOf(CommitFailedException::class, $e);
            $this->assertSame('Failed to begin transaction', $e->getMessage());
            $this->assertStringStartsWith('A transaction.begin listener ended the transaction that was just begun outside this driver', (string) $e->getDebugMessage());
            $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends, 'told once, with the exception the caller gets');
        }
        $this->assertFalse($ran);
        $this->assertSame(['end'], $this->events);
        $this->assertVisible([1], 'what the listener committed itself; nothing of the callback');

        // gone for certain: no mark of a transaction that "may still be open" stays behind, so a
        // transaction begun on raw PDO and committed through the library tells its end as usual
        $this->pdo->beginTransaction();
        $this->db->commit();
        $this->assertSame(['end', 'commit', 'end'], $this->events);

        try {
            $this->db->updateMultiple(self::TABLE, [['id' => 1, 'name' => 'batch']]);
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertStringStartsWith('A transaction.begin listener ended the transaction that was just begun outside this driver', (string) $e->getDebugMessage());
        }
        $this->assertSame(['listener'], array_column($this->db->findAll(self::TABLE, ['id' => 1]), 'name'), 'the batch did not run in autocommit');
    }

    /**
     * The same when the listener throws after it ended the transaction behind the library's back:
     * nothing is left to roll back, what it wrote is committed, and that end is told as 'lost'
     * with the exception the caller gets - the listener's own, a PDOException as
     * TransactionException. A throwing listener whose transaction is still open has it rolled
     * back without an event, as before.
     */
    public function testAThrowingBeginListenerThatEndedTheTransactionOnRawPdoTellsItAsLost(): void
    {
        $failure = new RuntimeException('begin listener failed');
        $mode = 'commit and throw';
        $this->db->on('transaction.begin', function () use (&$mode, $failure): void {
            if ($mode === 'commit and throw') {
                $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'listener']);
                $this->pdo->commit();
                throw $failure;
            }
            if ($mode === 'commit and throw a PDOException') {
                $this->pdo->commit();
                throw new \PDOException('begin listener failed (PDO)');
            }
            if ($mode === 'throw') {
                $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'rolled back']);
                throw $failure;
            }
        });

        try {
            $this->db->beginTransaction();
            $this->fail('Expected the listener exception');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        }
        $this->assertSame([['outcome' => self::LOST, 'error' => $failure]], $this->ends);
        $this->assertVisible([1], 'what the listener committed itself');

        $mode = 'commit and throw a PDOException';
        $this->ends = [];
        try {
            $this->db->beginTransaction();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to begin transaction', $e->getMessage());
            $this->assertSame('begin listener failed (PDO)', $e->getPrevious()?->getMessage());
            $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends, 'the exception the caller gets');
        }

        $mode = 'throw';
        $this->ends = [];
        $this->events = [];
        try {
            $this->db->beginTransaction();
            $this->fail('Expected the listener exception');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        }
        $this->assertSame([], $this->events, 'still open when the listener threw: rolled back raw, no event');
        $this->assertVisible([1]);

        $mode = 'none';
        $this->db->transaction(static fn (): null => null);
        $this->assertSame(['commit', 'end'], $this->events, 'no end of an earlier transaction is owed any more');
    }

    /**
     * After a throwing 'transaction.begin' listener the transaction just begun is rolled back on
     * raw PDO - but not one the listener began itself after ending the first: that one keeps its
     * mark and its end.
     */
    public function testAThrowingBeginListenerDoesNotHaveItsOwnTransactionRolledBack(): void
    {
        $armed = true;
        $failure = new RuntimeException('begin listener failed');
        $this->db->on('transaction.begin', function () use (&$armed, $failure): void {
            if ($armed) {
                $armed = false;
                $this->db->rollback();
                $this->db->beginTransaction();
                $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'listener']);
                throw $failure;
            }
        });

        try {
            $this->db->beginTransaction();
            $this->fail('Expected the listener exception');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        }
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertTrue($this->pdo->reallyInTransaction(), "the listener's own transaction is still open");

        $this->db->commit();
        $this->assertSame(['rollback', 'end', 'commit', 'end'], $this->events, 'and its end is told when it is ended');
        $this->assertVisible([2]);
    }

    /** An end listener that, once, answers a 'rolled_back' with a transaction of its own and a row in it. */
    private function beginInAnEndListenerOnce(): void
    {
        $begun = false;
        $this->db->on('transaction.end', function (array $data) use (&$begun): void {
            if (!$begun && $data['outcome'] === self::ROLLED_BACK) {
                $begun = true;
                $this->db->beginTransaction();
                $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'listener']);
            }
        });
    }

    /**
     * An end listener that answers a 'lost' with a transaction of its own and ends it on raw PDO,
     * as often as the returned object's $times says.
     */
    private function endOnRawPdoInAnEndListener(int $times): \stdClass
    {
        $left = new \stdClass();
        $left->times = $times;
        $this->db->on('transaction.end', function (array $data) use ($left): void {
            if ($data['outcome'] === self::LOST && $left->times > 0) {
                $left->times--;
                $this->db->beginTransaction();
                $this->pdo->commit();
            }
        });

        return $left;
    }

    public function testThePayloadHasExactlyOutcomeAndError(): void
    {
        $this->db->on('transaction.end', function (array $data): void {
            $this->events[] = implode(',', array_keys($data));
        });
        $this->db->beginTransaction();
        $this->db->commit();

        $this->assertSame(['commit', 'end', 'outcome,error'], $this->events);
    }

    // ---- helpers -----------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    protected function rows(): array
    {
        return ($this->observer ?? $this->db)->table(self::TABLE)->orderBy('id')->get();
    }

    /**
     * No transaction may be left open, and these ids are what a second connection sees afterwards
     * (SQLite in memory: this connection).
     *
     * @param list<int> $ids
     */
    protected function assertVisible(array $ids, string $message = ''): void
    {
        $this->assertFalse($this->pdo->reallyInTransaction(), 'no transaction may be left open');
        $this->assertSame($ids, array_map(static fn (array $row): int => (int) $row['id'], $this->rows()), $message);
    }

    /**
     * A row written inside a transaction that never committed must not be visible to another
     * connection. SQLite in memory has no other connection; nothing to check there.
     */
    protected function assertNotVisibleElsewhere(int $id): void
    {
        if ($this->observer === null) {
            return;
        }
        $this->assertSame(0, $this->observer->table(self::TABLE)->where('id', $id)->count(), 'nothing was committed');
    }
}
