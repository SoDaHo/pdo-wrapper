<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Transaction;

use Closure;
use LogicException;
use PDO;
use PDOException;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;
use Sodaho\PdoWrapper\Tests\Support\UnreadablePdoException;
use Throwable;

/**
 * The 'transaction.end' hook: exactly once per transaction the library ends, after the commit or
 * rollback listeners, with the outcome and the exception that ended the transaction.
 */
class TransactionEndHookTest extends ContractTestCase
{
    /** @var list<string> */
    private array $events = [];

    /** @var list<array{outcome: string, error: ?Throwable}> */
    private array $ends = [];

    /** @var list<array<mixed>> */
    private array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->create('users', ['id' => 'id', 'name' => 'text']);
    }

    /**
     * Many tests register listeners that use their own driver: a reference cycle that keeps the
     * driver, and with it its connection, alive after the test (measured: one connection per such
     * test). The cycle collector frees them here, before the tables are dropped - a transaction
     * left open on such a connection would hold its locks otherwise -, after the recorded errors
     * (whose traces may hold a driver) are let go.
     */
    protected function closeConnections(): void
    {
        $this->events = [];
        $this->ends = [];
        $this->errors = [];
        gc_collect_cycles();
    }

    public function testCommitFiresEndAfterTheCommitListeners(): void
    {
        $db = $this->driver();
        $db->beginTransaction();
        $db->insert('users', ['name' => 'Max']);
        $db->commit();

        $this->assertSame(['commit', 'end'], $this->events);
        $this->assertSame([['outcome' => 'committed', 'error' => null]], $this->ends);
        $this->assertSame(DatabaseInterface::TRANSACTION_COMMITTED, $this->ends[0]['outcome']);
        $this->assertSame(1, $db->table('users')->count());
    }

    public function testExplicitRollbackFiresEndWithoutAnError(): void
    {
        $db = $this->driver();
        $db->beginTransaction();
        $db->insert('users', ['name' => 'Max']);
        $db->rollback();

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_ROLLED_BACK, 'error' => null]], $this->ends);
        $this->assertSame(0, $db->table('users')->count());
    }

    public function testTheAutomaticRollbackReportsTheExceptionThatEndedTheTransaction(): void
    {
        $db = $this->driver();
        $cause = new RuntimeException('domain error');

        try {
            $db->transaction(static function (DatabaseInterface $db) use ($cause): void {
                $db->insert('users', ['name' => 'Max']);
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e, 'the cause reaches the caller unchanged');
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame('rolled_back', $this->ends[0]['outcome']);
        $this->assertSame($cause, $this->ends[0]['error']);
        $this->assertSame(0, $db->table('users')->count());
    }

    public function testEndListenerFailuresAfterACommitFollowTheCommitListenersFailuresInTheSameException(): void
    {
        $db = $this->driver();
        $commitFailure = new RuntimeException('commit listener failed');
        $endFailure = new LogicException('end listener failed');
        $db->on('transaction.commit', static function () use ($commitFailure): void {
            throw $commitFailure;
        });
        $db->on('transaction.end', static function () use ($endFailure): void {
            throw $endFailure;
        });
        $db->on('transaction.end', function (): void {
            $this->events[] = 'end-after-failure';
        });
        $db->beginTransaction();
        $db->insert('users', ['name' => 'Max']);

        try {
            $db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertSame([$commitFailure, $endFailure], $e->failures);
            $this->assertSame($commitFailure, $e->getPrevious());
            $this->assertFalse($e->connectionInTransaction);
        }

        $this->assertSame(['commit', 'end', 'end-after-failure'], $this->events, 'every end listener ran');
        $this->assertSame(1, $db->table('users')->count(), 'committed');
    }

    /**
     * The 1.1.0 path: a commit listener leaves a transaction open that cannot be rolled back, the
     * remaining commit listeners are skipped - 'transaction.end' still reports 'committed'.
     */
    public function testEndFiresCommittedEvenWhenTheRemainingCommitListenersWereSkipped(): void
    {
        [$db, $pdo] = $this->driverOn(ScenarioPdo::class);
        $db->on('transaction.commit', static function () use ($pdo): void {
            $pdo->beginTransaction(); // left open, and the raw cleanup will fail
            $pdo->rollBackReturnsFalse = true;
        });
        $db->on('transaction.commit', function (): void {
            $this->events[] = 'commit-2';
        });
        $db->beginTransaction();
        $db->insert('users', ['name' => 'Max']);

        try {
            $db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertTrue($e->connectionInTransaction);
            $this->assertCount(2, $e->failures, 'the open transaction and the skipped listener');
        }

        $this->assertSame(['commit', 'end'], $this->events, 'the second commit listener was skipped, end still fired');
        $this->assertSame([['outcome' => 'committed', 'error' => null]], $this->ends);
    }

    public function testEndListenerFailuresOnTheAutomaticRollbackReachOnlyTheErrorHook(): void
    {
        $db = $this->driver();
        $cause = new RuntimeException('domain error');
        $boom = new LogicException('end listener failed', 7);
        $db->on('transaction.end', static function () use ($boom): void {
            throw $boom;
        });
        $db->on('transaction.end', function (): void {
            $this->events[] = 'end-after-failure';
        });

        try {
            $db->transaction(static function () use ($cause): void {
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e, 'the cause reaches the caller unchanged');
        }

        $this->assertSame(['rollback', 'end', 'end-after-failure'], $this->events);
        $this->assertCount(1, $this->errors);
        $this->assertSame('', $this->errors[0]['sql']);
        $this->assertSame([], $this->errors[0]['params']);
        $this->assertSame('end listener failed', $this->errors[0]['error']);
        $this->assertSame(7, $this->errors[0]['code']);
        $this->assertSame([null, null], [$this->errors[0]['sqlState'], $this->errors[0]['driverCode']], 'a RuntimeException stands for no database failure');
        $this->assertSame('transaction.end', $this->errors[0]['hook']);
        $this->assertSame('rolled_back', $this->errors[0]['outcome']);
        $this->assertSame($boom, $this->errors[0]['exception']);
    }

    /**
     * An exception of a consumer's that extends DatabaseException without calling the parent
     * constructor has no codes to read: the error hook reports it with none, and the rollback
     * paths keep their outcome - no Error escapes from reading it.
     */
    public function testAnEndListenerExceptionWhoseCodesCannotBeReadIsReportedWithout(): void
    {
        $broken = new class () extends QueryException {
            public function __construct()
            {
                // parent::__construct() is not called: $sqlState and $driverCode stay uninitialized
            }
        };
        $db = $this->driver();
        $db->on('transaction.end', static function () use ($broken): void {
            throw $broken;
        });

        // after the automatic rollback: the hook is told, the cause reaches the caller
        $cause = new RuntimeException('domain error');
        try {
            $db->transaction(static function () use ($cause): void {
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }
        $this->assertCount(1, $this->errors);
        $this->assertSame($broken, $this->errors[0]['exception']);
        $this->assertSame([null, null], [$this->errors[0]['sqlState'], $this->errors[0]['driverCode']]);

        // after an explicit rollback: the end listener's failure arrives as TransactionException, as for any other
        $db->beginTransaction();
        try {
            $db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame($broken, $e->getPrevious());
        }
        $this->assertCount(2, $this->errors);
        $this->assertSame([null, null], [$this->errors[1]['sqlState'], $this->errors[1]['driverCode']]);
    }

    /**
     * Reading the codes of a listener's exception can fail: a PDOException whose errorInfo is
     * gone and whose magic __isset() throws. That costs the hook entry, nothing else - the cause
     * still reaches the caller of transaction(), unmasked.
     */
    public function testAnEndListenerExceptionThatFailsToBeReadCostsOnlyTheHookEntry(): void
    {
        $unreadable = new UnreadablePdoException('unreadable (fixture)');
        $db = $this->driver();
        $db->on('transaction.end', static function () use ($unreadable): void {
            throw $unreadable;
        });
        $db->on('transaction.end', function (): void {
            $this->events[] = 'end-after-failure';
        });

        $cause = new RuntimeException('domain error');
        try {
            $db->transaction(static function () use ($cause): void {
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e, 'the cause reaches the caller unmasked');
        }

        $this->assertSame(['rollback', 'end', 'end-after-failure'], $this->events, 'every end listener ran');
        $this->assertSame([['outcome' => 'rolled_back', 'error' => $cause]], $this->ends);
        $this->assertSame([], $this->errors, 'only the hook entry of the unreadable exception is lost');
        $this->assertFalse($db->getPdo()->inTransaction());

        // after an explicit rollback the caller gets the TransactionException about the listener's
        // failure, as for any other - not what reading the exception threw
        $this->events = [];
        $db->beginTransaction();
        try {
            $db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame($unreadable, $e->getPrevious());
            $this->assertSame([null, null], [$e->sqlState, $e->driverCode], 'a listener\'s failure: no codes, nothing read');
        }
        $this->assertSame(['rollback', 'end', 'end-after-failure'], $this->events);
        $this->assertSame([['outcome' => 'rolled_back', 'error' => $cause], ['outcome' => 'rolled_back', 'error' => null]], $this->ends);
        $this->assertSame([], $this->errors);
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    public function testAThrowingErrorHookDoesNotMaskTheCauseOnTheAutomaticRollback(): void
    {
        $db = $this->driver();
        $cause = new RuntimeException('domain error');
        $db->on('transaction.end', static function (): void {
            throw new LogicException('end listener failed');
        });
        $db->on('error', static function (): void {
            throw new LogicException('error hook failed');
        });

        try {
            $db->transaction(static function () use ($cause): void {
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }
        $this->assertSame(['rollback', 'end'], $this->events);
    }

    public function testEndListenerFailureAfterAnExplicitRollbackIsATransactionException(): void
    {
        $db = $this->driver();
        $boom = new LogicException('end listener failed', 3);
        $db->on('transaction.end', static function () use ($boom): void {
            throw $boom;
        });
        $db->beginTransaction();

        try {
            $db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Transaction rolled back, but a transaction.end listener failed', $e->getMessage());
            $this->assertSame($boom, $e->getPrevious());
            $this->assertSame('end listener failed', $e->getDebugMessage());
            $this->assertSame(0, $e->getCode(), 'the listener\'s own code is not passed on');
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame($boom, $this->errors[0]['exception'], 'the error hook saw it too');
        $this->assertFalse($db->inTransaction());
    }

    public function testAThrowingRollbackListenerStillLetsEndFireOnAnExplicitRollback(): void
    {
        $db = $this->driver();
        $rollbackFailure = new RuntimeException('rollback listener failed');
        $endFailure = new LogicException('end listener failed');
        $db->on('transaction.rollback', static function () use ($rollbackFailure): void {
            throw $rollbackFailure;
        });
        $db->on('transaction.end', static function () use ($endFailure): void {
            throw $endFailure;
        });
        $db->beginTransaction();

        try {
            $db->rollback();
            $this->fail('Expected the rollback listener exception');
        } catch (RuntimeException $e) {
            $this->assertSame($rollbackFailure, $e, 'the first failure reaches the caller, as before');
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame($endFailure, $this->errors[0]['exception'], 'the end failure reached the error hook');
    }

    public function testLostWhenTheRollbackFails(): void
    {
        [$db, $pdo] = $this->driverOn(ScenarioPdo::class);
        $pdo->failRollBackAlways = true; // what a server that has gone away answers
        $cause = new RuntimeException('statement failed');

        try {
            $db->transaction(static function () use ($cause): void {
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }

        $this->assertSame(['end'], $this->events, 'no rollback listener: the rollback failed');
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $cause]], $this->ends);
    }

    public function testLostWhenPdoNoLongerReportsTheTransaction(): void
    {
        [$db, $pdo] = $this->driverOn(ScenarioPdo::class);
        $cause = new RuntimeException('statement failed');

        try {
            $db->transaction(static function () use ($cause, $pdo): void {
                $pdo->hideTransaction = true; // the server ended it and PDO knows
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }

        $this->assertSame(['end'], $this->events);
        $this->assertSame([['outcome' => 'lost', 'error' => $cause]], $this->ends);
    }

    public function testLostWhenTheStateCannotBeRead(): void
    {
        [$db, $pdo] = $this->driverOn(ScenarioPdo::class);
        $cause = new RuntimeException('statement failed');

        try {
            $db->transaction(static function () use ($cause, $pdo): void {
                $pdo->stateUnreadable = true;
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }

        $this->assertSame([['outcome' => 'lost', 'error' => $cause]], $this->ends);
    }

    public function testRolledBackWhenTheCommitFailsButTheRollbackSucceeds(): void
    {
        [$db, $pdo] = $this->driverOn(ScenarioPdo::class);
        $pdo->failCommit = true;

        try {
            $db->transaction(static function (DatabaseInterface $db): void {
                $db->insert('users', ['name' => 'Max']);
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame(['rollback', 'end'], $this->events);
            $this->assertSame('rolled_back', $this->ends[0]['outcome']);
            $this->assertSame($e, $this->ends[0]['error'], "the commit's exception is the error");
        }
        $this->assertSame(0, $db->table('users')->count(), 'nothing was committed');
    }

    public function testLostWhenTheCommitAndTheRollbackAfterItFail(): void
    {
        [$db, $pdo] = $this->driverOn(ScenarioPdo::class);
        $pdo->failCommit = true; // the connection is lost during the COMMIT
        $pdo->failRollBackAlways = true;

        try {
            $db->transaction(static function (DatabaseInterface $db): void {
                $db->insert('users', ['name' => 'Max']);
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame(['end'], $this->events, 'no rollback listener');
            $this->assertSame([['outcome' => 'lost', 'error' => $e]], $this->ends, "the data may be committed; the error is the commit's exception");
        }
    }

    public function testExactlyOnceWhenTheCallbackRolledBackItself(): void
    {
        $db = $this->driver();
        $cause = new RuntimeException('after my own rollback');

        try {
            $db->transaction(static function (DatabaseInterface $db) use ($cause): void {
                $db->insert('users', ['name' => 'Max']);
                $db->rollback();
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }

        $this->assertSame(['rollback', 'end'], $this->events, 'one end for the explicit rollback, no second "lost"');
        $this->assertSame([['outcome' => 'rolled_back', 'error' => null]], $this->ends);
    }

    public function testExactlyOnceWhenTheCallbackCommittedItself(): void
    {
        $db = $this->driver();

        try {
            $db->transaction(static function (DatabaseInterface $db): void {
                $db->insert('users', ['name' => 'Max']);
                $db->commit();
            });
            $this->fail('Expected TransactionException: nothing left to commit');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
        }

        $this->assertSame(['commit', 'end'], $this->events, "one end for the callback's commit, nothing for the failed second one");
        $this->assertSame(1, $db->table('users')->count());
    }

    public function testAFailingExplicitCommitOrRollbackFiresNothing(): void
    {
        [$db, $pdo] = $this->driverOn(ScenarioPdo::class);
        $db->beginTransaction();
        $pdo->failCommit = true; // once: the one commit() below
        $pdo->failRollBackAlways = true;

        try {
            $db->commit();
            $this->fail('Expected TransactionException');
        } catch (TransactionException) {
        }
        try {
            $db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException) {
        }
        $this->assertSame([], $this->events, 'the transaction is still the caller\'s to end');

        $pdo->failRollBackAlways = false;
        $db->rollback();
        $this->assertSame(['rollback', 'end'], $this->events, 'and ends exactly once when that succeeds');
    }

    public function testATransactionBegunOnRawPdoAndEndedThroughTheLibraryFiresEnd(): void
    {
        $db = $this->driver();
        $db->getPdo()->beginTransaction();
        $db->commit();
        $db->getPdo()->beginTransaction();
        $db->rollback();

        $this->assertSame(['commit', 'end', 'rollback', 'end'], $this->events);
        $this->assertSame(['committed', 'rolled_back'], array_column($this->ends, 'outcome'));
    }

    /**
     * The begin of the first transaction was told, so after its listener threw it gets its end:
     * rolled back on raw PDO, without rollback listeners, with the exception the caller gets.
     */
    public function testAThrowingBeginListenerEndsItsTransactionOnceAndTheNextOneEndsExactlyOnce(): void
    {
        $db = $this->driver();
        $failOnce = true;
        $db->on('transaction.begin', static function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('begin listener failed');
            }
        });

        try {
            $db->transaction(static fn (): int => 1);
            $this->fail('Expected the begin listener exception');
        } catch (RuntimeException $e) {
            $this->assertSame('begin listener failed', $e->getMessage());
        }
        $this->assertSame(['end'], $this->events, 'the raw rollback runs no rollback listener, but the end is told');
        $this->assertSame([['outcome' => 'rolled_back', 'error' => $e]], $this->ends);
        $this->assertFalse($db->inTransaction());

        // the next transaction on the same connection, ended by the callback's own rollback, owes exactly one end
        try {
            $db->transaction(static function (DatabaseInterface $db): void {
                $db->rollback();
                throw new RuntimeException('after my own rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(['end', 'rollback', 'end'], $this->events);
    }

    public function testUpdateMultipleFiresEndForItsOwnTransaction(): void
    {
        $db = $this->driver();
        $db->insert('users', ['name' => 'Max']);
        $this->events = [];

        $db->updateMultiple('users', [['id' => 1, 'name' => 'Moritz']]);
        $this->assertSame(['commit', 'end'], $this->events);

        $this->events = [];
        try {
            $db->updateMultiple('users', [['id' => 1, 'name' => 'Anna'], ['name' => 'no key']]);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame(['rollback', 'end'], $this->events);
            $this->assertSame('rolled_back', $this->ends[1]['outcome']);
            $this->assertSame($e, $this->ends[1]['error']);
        }
        $this->assertSame('Moritz', $db->table('users')->where('id', 1)->first()['name'] ?? null);
    }

    public function testThePayloadHasExactlyOutcomeErrorTransactionAndDepth(): void
    {
        $db = $this->driver();
        $db->on('transaction.end', function (array $data): void {
            $this->events[] = implode(',', array_keys($data));
        });
        $db->beginTransaction();
        $db->commit();

        $this->assertSame(['commit', 'end', 'outcome,error,transaction,depth'], $this->events);
        $this->assertSame('committed', DatabaseInterface::TRANSACTION_COMMITTED);
        $this->assertSame('rolled_back', DatabaseInterface::TRANSACTION_ROLLED_BACK);
        $this->assertSame('lost', DatabaseInterface::TRANSACTION_LOST);
    }

    /**
     * After a reported 'lost' the transaction may in fact still be open (the rollback failed once);
     * its later successful rollback() runs the rollback listeners but tells no second end. The next
     * transaction owes its own end again.
     */
    public function testAfterLostTheLaterRollbackOfTheStillOpenTransactionTellsNoSecondEnd(): void
    {
        [$db, $pdo] = $this->driverOn(ScenarioPdo::class);
        $cause = new RuntimeException('statement failed');

        try {
            $db->transaction(static function () use ($cause, $pdo): void {
                $pdo->duringRollBack = self::rollBackFailingOnce();
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }
        $this->assertSame([['outcome' => 'lost', 'error' => $cause]], $this->ends);
        $this->assertTrue($db->inTransaction(), 'the transaction is still open');

        $db->rollback();
        $this->assertSame(['end', 'rollback'], $this->events, 'the rollback listeners ran, no second end');
        $this->assertFalse($db->inTransaction());

        $db->beginTransaction();
        $db->commit();
        $this->assertSame(['lost', 'committed'], array_column($this->ends, 'outcome'), 'the next transaction owes its own end');
    }

    /**
     * The callback ends its transaction itself, then starts one whose begin listener throws: the
     * raw rollback after that listener tells that one's end and leaves nothing behind, so the outer
     * cleanup reports no 'lost'.
     */
    public function testASelfRolledBackCallbackWhoseInnerTransactionFailsToStartReportsNoLost(): void
    {
        $db = $this->driver();
        $state = new class () {
            public bool $armed = false;
        };
        $db->on('transaction.begin', static function () use ($state): void {
            if ($state->armed) {
                $state->armed = false;
                throw new RuntimeException('begin listener failed');
            }
        });

        try {
            $db->transaction(static function (DatabaseInterface $db) use ($state): void {
                $db->rollback();
                $state->armed = true;
                $db->transaction(static fn (): int => 1);
            });
            $this->fail('Expected the begin listener exception');
        } catch (RuntimeException $e) {
            $this->assertSame('begin listener failed', $e->getMessage());
        }

        $this->assertSame(['rollback', 'end', 'end'], $this->events);
        $this->assertSame(
            [['outcome' => 'rolled_back', 'error' => null], ['outcome' => 'rolled_back', 'error' => $e]],
            $this->ends,
            'one end for the explicit rollback, one for the transaction whose begin failed, no stray lost'
        );
        $this->assertFalse($db->inTransaction());
    }

    /**
     * After a 'lost' for a transaction PDO no longer reported, a transaction begun on raw PDO and
     * ended through the library owes its end: the "no second end" rule applies only to a lost
     * transaction that may still be open.
     */
    public function testAfterLostWithNoTransactionLeftARawBegunTransactionEndedByTheLibraryTellsItsEnd(): void
    {
        [$db, $pdo] = $this->driverOn(ScenarioPdo::class);
        $cause = new RuntimeException('statement failed');

        try {
            $db->transaction(static function () use ($cause, $pdo): void {
                $pdo->hideTransaction = true;
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }
        $this->assertSame([['outcome' => 'lost', 'error' => $cause]], $this->ends);

        $pdo->hideTransaction = false;
        $pdo->rollBack(); // the application discards the lost transaction raw
        $pdo->beginTransaction();
        $db->commit();

        $this->assertSame(['end', 'commit', 'end'], $this->events);
        $this->assertSame(['lost', 'committed'], array_column($this->ends, 'outcome'), 'the raw-begun transaction told its end');
    }

    /**
     * A rollback listener that begins a transaction (on raw PDO: through the driver it is refused in
     * a listener) and throws after the automatic rollback went through: the outer transaction has
     * ended ('rolled_back'), no 'lost' is reported for it.
     */
    public function testAThrowingRollbackListenerThatBeganATransactionCausesNoLost(): void
    {
        $db = $this->driver();
        $cause = new RuntimeException('domain error');
        $db->on('transaction.rollback', static function () use ($db): void {
            $db->getPdo()->beginTransaction(); // left open, not checked
            throw new LogicException('rollback listener failed');
        });

        try {
            $db->transaction(static function () use ($cause): void {
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame([['outcome' => 'rolled_back', 'error' => $cause]], $this->ends, 'no stray lost');
        $this->assertTrue($db->inTransaction(), "the listener's transaction stays open");
        $db->getPdo()->rollBack();
    }

    /**
     * The test's driver ($this->db, on the users table) with the recording listeners (see recording()).
     */
    private function driver(): DatabaseInterface
    {
        return $this->recording($this->db);
    }

    /**
     * A driver whose PDO object is of the given class (pdoClass), with the recording listeners, and
     * that PDO object: the test makes its calls fail or lie.
     *
     * @template T of PDO
     *
     * @param class-string<T> $pdoClass
     *
     * @return array{DatabaseInterface, T}
     */
    private function driverOn(string $pdoClass): array
    {
        $db = $this->connect(['pdoClass' => $pdoClass]);
        $pdo = $db->getPdo();
        $this->assertInstanceOf($pdoClass, $pdo);

        return [$this->recording($db), $pdo];
    }

    /**
     * For ScenarioPdo::$duringRollBack: the next rollBack() throws, before anything is sent - once,
     * the one after it goes through.
     */
    private static function rollBackFailingOnce(): Closure
    {
        return static fn () => throw new PDOException('rollback failed this once');
    }

    /**
     * The driver with listeners that record 'commit', 'rollback' and 'end' in order, the end
     * payloads, and 'error' hook contexts.
     */
    private function recording(DatabaseInterface $db): DatabaseInterface
    {
        $this->events = [];
        $this->ends = [];
        $this->errors = [];
        $db->on('transaction.commit', function (): void {
            $this->events[] = 'commit';
        });
        $db->on('transaction.rollback', function (): void {
            $this->events[] = 'rollback';
        });
        $db->on('transaction.end', function (array $data): void {
            $this->events[] = 'end';
            $this->ends[] = ['outcome' => (string) $data['outcome'], 'error' => $data['error'] instanceof Throwable ? $data['error'] : null];
        });
        $db->on('error', function (array $data): void {
            $this->errors[] = $data;
        });

        return $db;
    }
}
