<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\TransactionEnd;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
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
        });
        $this->db->on('error', function (array $data): void {
            $this->errors[] = $data;
        });
    }

    protected function tearDown(): void
    {
        $this->pdo->failRollBackAlways = false;
        $this->pdo->failCommit = false;
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
            $this->fail('Expected TransactionException: the aborted transaction must not be reported as committed');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertStringContainsString('The transaction is aborted', (string) $e->getDebugMessage());
            $this->assertNotNull($swallowed);
            $this->assertSame($swallowed->getPrevious(), $e->getPrevious(), 'the statement failure that aborted it');
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends);
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
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
        }
        $this->assertSame([], $this->events, 'the transaction is still the caller\'s to end');
        $this->assertTrue($this->db->inTransaction());

        $this->db->rollback();
        $this->assertSame(['rollback', 'end'], $this->events);
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
            $this->fail('Expected TransactionException: nothing left to commit');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
        }

        $this->assertSame(['commit', 'end'], $this->events);
        $this->assertVisible([1], "the callback's own commit stands");
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
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame('commit failed (scenario)', $e->getDebugMessage());
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends, "the commit's exception is the error");
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
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends, 'lost: the data may be committed, fail-closed');
        }
        $this->assertSame(['end'], $this->events, 'no rollback listener');
        $this->assertTrue($this->pdo->reallyInTransaction());
        $this->assertNotVisibleElsewhere(1);

        $this->pdo->failRollBackAlways = false;
        $this->db->rollback();
        $this->assertSame(['end', 'rollback'], $this->events, 'no second end');
        $this->assertVisible([]);
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
