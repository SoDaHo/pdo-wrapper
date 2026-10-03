<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Throwable;

/**
 * The 'transaction.end' hook: exactly once per transaction the library ends, after the commit or
 * rollback listeners, with the outcome and the exception that ended the transaction.
 */
class TransactionEndHookTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    /** @var list<array{outcome: string, error: ?Throwable}> */
    private array $ends = [];

    /** @var list<array<string, mixed>> */
    private array $errors = [];

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
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $failRollBack = false;

            public function rollBack(): bool
            {
                return $this->failRollBack ? false : parent::rollBack();
            }
        };
        $db = $this->driver($pdo);
        $db->on('transaction.commit', static function () use ($pdo): void {
            $pdo->beginTransaction(); // left open, and the raw cleanup will fail
            $pdo->failRollBack = true;
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
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public function rollBack(): bool
            {
                throw new PDOException('server has gone away');
            }
        };
        $db = $this->driver($pdo);
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
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $hideTransaction = false;

            public function inTransaction(): bool
            {
                return $this->hideTransaction ? false : parent::inTransaction();
            }
        };
        $db = $this->driver($pdo);
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
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $stateUnreadable = false;

            public function inTransaction(): bool
            {
                if ($this->stateUnreadable) {
                    throw new PDOException('state unreadable');
                }

                return parent::inTransaction();
            }
        };
        $db = $this->driver($pdo);
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
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public function commit(): bool
            {
                throw new PDOException('commit failed');
            }
        };
        $db = $this->driver($pdo);

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
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public function commit(): bool
            {
                throw new PDOException('connection lost during COMMIT');
            }

            public function rollBack(): bool
            {
                throw new PDOException('server has gone away');
            }
        };
        $db = $this->driver($pdo);

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
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $fail = false;

            public function commit(): bool
            {
                if ($this->fail) {
                    throw new PDOException('commit failed');
                }

                return parent::commit();
            }

            public function rollBack(): bool
            {
                if ($this->fail) {
                    throw new PDOException('rollback failed');
                }

                return parent::rollBack();
            }
        };
        $db = $this->driver($pdo);
        $db->beginTransaction();
        $pdo->fail = true;

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

        $pdo->fail = false;
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

    public function testAThrowingBeginListenerFiresNoEndAndTheNextTransactionEndsExactlyOnce(): void
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
        $this->assertSame([], $this->events, 'the raw rollback after a throwing begin listener fires nothing');
        $this->assertFalse($db->inTransaction());

        // the next transaction on the same connection, ended by the callback's own rollback, owes exactly one end
        try {
            $db->transaction(static function (DatabaseInterface $db): void {
                $db->rollback();
                throw new RuntimeException('after my own rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(['rollback', 'end'], $this->events);
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

    /**
     * A transaction started inside a 'transaction.commit' listener ends, with its own end event,
     * before the outer transaction's end is dispatched.
     */
    public function testATransactionStartedInACommitListenerEndsBeforeTheOuterEnd(): void
    {
        $db = $this->driver();
        $started = false;
        $db->on('transaction.commit', static function () use ($db, &$started): void {
            if ($started) {
                return;
            }
            $started = true;
            $db->transaction(static function (DatabaseInterface $db): void {
                $db->insert('users', ['name' => 'inner']);
            });
        });
        $db->on('transaction.end', function (): void {
            $this->events[] = 'end#' . count($this->ends);
        });

        $db->transaction(static function (DatabaseInterface $db): void {
            $db->insert('users', ['name' => 'outer']);
        });

        $this->assertSame(['commit', 'commit', 'end', 'end#1', 'end', 'end#2'], $this->events, 'inner commit and inner end before the outer end');
        $this->assertSame(['committed', 'committed'], array_column($this->ends, 'outcome'));
        $this->assertSame(2, $db->table('users')->count());
    }

    /**
     * A transaction started inside a 'transaction.end' listener ends inside that listener, before
     * the remaining outer end listeners run.
     */
    public function testATransactionStartedInAnEndListenerEndsInsideThatListener(): void
    {
        $db = $this->driver();
        $depth = 0;
        $started = false;
        $db->on('transaction.end', function () use ($db, &$depth, &$started): void {
            $this->events[] = 'L1@' . $depth;
            if ($started) {
                return;
            }
            $started = true;
            $depth++;
            $db->transaction(static function (DatabaseInterface $db): void {
                $db->insert('users', ['name' => 'inner']);
            });
            $depth--;
            $this->events[] = 'L1-done@' . $depth;
        });
        $db->on('transaction.end', function () use (&$depth): void {
            $this->events[] = 'L2@' . $depth;
        });

        $db->transaction(static function (DatabaseInterface $db): void {
            $db->insert('users', ['name' => 'outer']);
        });

        $this->assertSame(
            ['commit', 'end', 'L1@0', 'commit', 'end', 'L1@1', 'L2@1', 'L1-done@0', 'L2@0'],
            $this->events
        );
        $this->assertSame(2, $db->table('users')->count());
    }

    public function testThePayloadHasExactlyOutcomeAndError(): void
    {
        $db = $this->driver();
        $db->on('transaction.end', function (array $data): void {
            $this->events[] = implode(',', array_keys($data));
        });
        $db->beginTransaction();
        $db->commit();

        $this->assertSame(['commit', 'end', 'outcome,error'], $this->events);
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
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $failOnce = false;

            public function rollBack(): bool
            {
                if ($this->failOnce) {
                    $this->failOnce = false;
                    throw new PDOException('rollback failed this once');
                }

                return parent::rollBack();
            }
        };
        $db = $this->driver($pdo);
        $cause = new RuntimeException('statement failed');

        try {
            $db->transaction(static function () use ($cause, $pdo): void {
                $pdo->failOnce = true;
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
     * raw rollback after that listener leaves nothing behind, so the outer cleanup reports no 'lost'.
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

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertSame([['outcome' => 'rolled_back', 'error' => null]], $this->ends, 'one end for the explicit rollback, no stray lost');
        $this->assertFalse($db->inTransaction());
    }

    /**
     * During the automatic rollback an end listener starts a transaction and rolls it back itself:
     * that rollback() is explicit (error null) and reports its end listeners' failures as
     * TransactionException to the listener, not swallowed like the outer automatic one.
     */
    public function testAnExplicitRollbackInsideAnEndListenerDuringTheAutomaticRollbackIsExplicit(): void
    {
        $db = $this->driver();
        $cause = new RuntimeException('domain error');
        $innerFailure = new LogicException('inner end listener failed');
        $state = new class () {
            public int $depth = 0;
        };
        $started = false;
        $innerException = null;
        $db->on('transaction.end', static function () use ($db, $state, &$started, &$innerException): void {
            if ($started) {
                return;
            }
            $started = true;
            $state->depth = 1;
            $db->beginTransaction();
            try {
                $db->rollback();
            } catch (TransactionException $e) {
                $innerException = $e;
            }
            $state->depth = 0;
        });
        $db->on('transaction.end', static function () use ($state, $innerFailure): void {
            if ($state->depth === 1) {
                throw $innerFailure;
            }
        });

        try {
            $db->transaction(static function () use ($cause): void {
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e, 'the outer cause reaches the caller unchanged');
        }

        $this->assertSame([['outcome' => 'rolled_back', 'error' => $cause], ['outcome' => 'rolled_back', 'error' => null]], $this->ends, 'outer automatic, inner explicit');
        $this->assertInstanceOf(TransactionException::class, $innerException);
        $this->assertSame($innerFailure, $innerException->getPrevious(), 'the inner end failure reached the listener as TransactionException');
        $this->assertSame([$innerFailure], array_column($this->errors, 'exception'), 'and the error hook');
    }

    /**
     * A transaction a commit listener begins through the driver and leaves open is rolled back
     * (without rollback hooks) and gets its own 'transaction.end' before the outer one.
     */
    public function testATransactionACommitListenerLeavesOpenGetsItsOwnEndBeforeTheOuterOne(): void
    {
        $db = $this->driver();
        $once = false;
        $db->on('transaction.commit', static function () use ($db, &$once): void {
            if (!$once) {
                $once = true;
                $db->beginTransaction(); // left open
            }
        });
        $innerEndFailure = new RuntimeException('inner end listener failed');
        $db->on('transaction.end', static function (array $data) use ($innerEndFailure): void {
            if ($data['error'] instanceof LogicException) {
                throw $innerEndFailure;
            }
        });
        $db->beginTransaction();
        $db->insert('users', ['name' => 'Max']);

        try {
            $db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(2, $e->failures, "the open transaction, then its end listener's failure");
            $this->assertInstanceOf(LogicException::class, $e->failures[0]);
            $this->assertSame('listener left a transaction open', $e->failures[0]->getMessage());
            $this->assertSame($innerEndFailure, $e->failures[1], 'the inner end listeners\' failures join the exception');
            $this->assertFalse($e->connectionInTransaction);
        }

        $this->assertSame(['commit', 'end', 'end'], $this->events, 'no rollback listener for the raw cleanup');
        $this->assertSame(['rolled_back', 'committed'], array_column($this->ends, 'outcome'), 'inner end first');
        $this->assertSame($e->failures[0], $this->ends[0]['error']);
        $this->assertSame([], $this->errors, 'not via the error hook on the commit path');
        $this->assertNull($this->ends[1]['error']);
        $this->assertFalse($db->inTransaction());
        $this->assertSame(1, $db->table('users')->count(), 'the outer transaction was committed');
    }

    /**
     * During a 'lost' dispatch an end listener clears the connection raw, starts a transaction and
     * rolls it back itself: that rollback() is explicit (error null) and reports its end listeners'
     * failures to the listener as TransactionException.
     */
    public function testAnExplicitRollbackInsideAnEndListenerDuringLostIsExplicit(): void
    {
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $stateUnreadable = false;

            public function inTransaction(): bool
            {
                if ($this->stateUnreadable) {
                    throw new PDOException('state unreadable');
                }

                return parent::inTransaction();
            }
        };
        $db = $this->driver($pdo);
        $cause = new RuntimeException('statement failed');
        $innerFailure = new LogicException('inner end listener failed');
        $state = new class () {
            public int $depth = 0;
        };
        $started = false;
        $innerException = null;
        $db->on('transaction.end', static function () use ($db, $pdo, $state, &$started, &$innerException): void {
            if ($started) {
                return;
            }
            $started = true;
            $pdo->stateUnreadable = false;
            $pdo->rollBack(); // clear the lost transaction raw, as an application discarding it would
            $state->depth = 1;
            $db->beginTransaction();
            try {
                $db->rollback();
            } catch (TransactionException $e) {
                $innerException = $e;
            }
            $state->depth = 0;
        });
        $db->on('transaction.end', static function () use ($state, $innerFailure): void {
            if ($state->depth === 1) {
                throw $innerFailure;
            }
        });

        try {
            $db->transaction(static function () use ($cause, $pdo): void {
                $pdo->stateUnreadable = true;
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }

        $this->assertSame([['outcome' => 'lost', 'error' => $cause], ['outcome' => 'rolled_back', 'error' => null]], $this->ends, 'outer lost, inner explicit');
        $this->assertInstanceOf(TransactionException::class, $innerException);
        $this->assertSame($innerFailure, $innerException->getPrevious());
    }

    /**
     * After a 'lost' for a transaction PDO no longer reported, a transaction begun on raw PDO and
     * ended through the library owes its end: the "no second end" rule applies only to a lost
     * transaction that may still be open.
     */
    public function testAfterLostWithNoTransactionLeftARawBegunTransactionEndedByTheLibraryTellsItsEnd(): void
    {
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $hideTransaction = false;

            public function inTransaction(): bool
            {
                return $this->hideTransaction ? false : parent::inTransaction();
            }
        };
        $db = $this->driver($pdo);
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
     * A commit listener's transaction whose raw cleanup fails ends as 'lost' and may still be open;
     * its later rollback() runs the rollback listeners but tells no second end.
     */
    public function testALostCleanupOfAListenerTransactionTellsNoSecondEndLater(): void
    {
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $failOnce = false;

            public function rollBack(): bool
            {
                if ($this->failOnce) {
                    $this->failOnce = false;

                    return false;
                }

                return parent::rollBack();
            }
        };
        $db = $this->driver($pdo);
        $once = false;
        $db->on('transaction.commit', static function () use ($db, $pdo, &$once): void {
            if (!$once) {
                $once = true;
                $db->beginTransaction(); // left open, and its raw cleanup will fail
                $pdo->failOnce = true;
            }
        });
        $db->on('transaction.commit', function (): void {
            $this->events[] = 'commit-2';
        });
        $innerLostFailure = new RuntimeException('end listener failed on the inner lost');
        $db->on('transaction.end', static function (array $data) use ($innerLostFailure): void {
            if ($data['outcome'] === 'lost') {
                throw $innerLostFailure;
            }
        });
        $db->beginTransaction();
        $db->insert('users', ['name' => 'Max']);

        try {
            $db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertTrue($e->connectionInTransaction);
            $this->assertCount(3, $e->failures, "the open transaction, the skipped listener, then the inner end listener's failure");
            $this->assertSame($innerLostFailure, $e->failures[2], 'a buffered inner lost joins the exception, not the error hook');
        }
        $this->assertSame([], $this->errors);
        $this->assertSame(['commit', 'end', 'end'], $this->events, 'commit-2 was skipped; inner lost, outer committed');
        $this->assertSame(['lost', 'committed'], array_column($this->ends, 'outcome'));
        $this->assertTrue($db->inTransaction(), "the listener's transaction is still open");

        $db->rollback();
        $this->assertSame(['commit', 'end', 'end', 'rollback'], $this->events, 'rollback listeners ran, no second end for the lost transaction');
        $this->assertFalse($db->inTransaction());
    }

    /**
     * The end of a transaction a commit listener left open is dispatched after the commit listener
     * loop: the remaining commit listeners run outside any transaction an inner end listener opens.
     */
    public function testCommitListenersRunOutsideAnOpenTransactionLeftByAnInnerEndListener(): void
    {
        $db = $this->driver();
        $once = false;
        $db->on('transaction.commit', static function () use ($db, &$once): void {
            if (!$once) {
                $once = true;
                $db->beginTransaction(); // left open
            }
        });
        $db->on('transaction.commit', function () use ($db): void {
            $this->events[] = 'commit-2 in transaction: ' . var_export($db->inTransaction(), true);
        });
        $db->on('transaction.end', static function (array $data) use ($db): void {
            if ($data['error'] instanceof LogicException) {
                $db->getPdo()->beginTransaction(); // the inner end listener leaves one open: not checked
            }
        });
        $db->beginTransaction();
        $db->insert('users', ['name' => 'Max']);

        try {
            $db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(1, $e->failures, "only the first listener's open transaction");
            $this->assertFalse($e->connectionInTransaction);
        }

        $this->assertSame(['commit', 'commit-2 in transaction: false', 'end', 'end'], $this->events);
        $this->assertSame(['rolled_back', 'committed'], array_column($this->ends, 'outcome'));
        $this->assertTrue($db->inTransaction(), 'what the end listener left open stays open');
        $db->getPdo()->rollBack();
    }

    /**
     * A rollback listener that begins a transaction and throws after the automatic rollback went
     * through: the outer transaction has ended ('rolled_back'), no 'lost' is reported for it.
     */
    public function testAThrowingRollbackListenerThatBeganATransactionCausesNoLost(): void
    {
        $db = $this->driver();
        $cause = new RuntimeException('domain error');
        $db->on('transaction.rollback', static function () use ($db): void {
            $db->beginTransaction(); // left open, not checked
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
     * A commit listener's transaction after which the connection state cannot be read: the
     * remaining commit listeners are skipped and the listener's transaction ends as 'lost' (it may
     * still be open: its later rollback() tells no second end).
     */
    public function testAListenerTransactionWhoseStateCannotBeReadEndsAsLost(): void
    {
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $stateUnreadable = false;

            public function inTransaction(): bool
            {
                if ($this->stateUnreadable) {
                    throw new PDOException('state unreadable');
                }

                return parent::inTransaction();
            }
        };
        $db = $this->driver($pdo);
        $once = false;
        $db->on('transaction.commit', static function () use ($db, $pdo, &$once): void {
            if (!$once) {
                $once = true;
                $db->beginTransaction(); // left open
                $pdo->stateUnreadable = true;
            }
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
            $this->assertCount(2, $e->failures, 'the unknown state and the skipped listener');
            $this->assertSame('connection state unknown after listener', $e->failures[0]->getMessage());
        }
        $this->assertSame(['commit', 'end', 'end'], $this->events, 'commit-2 skipped; inner lost, outer committed');
        $this->assertSame(['lost', 'committed'], array_column($this->ends, 'outcome'));
        $this->assertSame($e->failures[0], $this->ends[0]['error']);

        $pdo->stateUnreadable = false;
        $db->rollback();
        $this->assertSame(['commit', 'end', 'end', 'rollback'], $this->events, 'no second end for the lost transaction');
        $this->assertFalse($db->inTransaction());
    }

    /**
     * A transaction() inside a commit listener reports 'lost' because its rollback fails, and leaves
     * the connection in that transaction; the raw cleanup after the listener succeeds, so the
     * connection is clean again and a later transaction ended through the library owes its end.
     */
    public function testASuccessfulRawCleanupClearsTheLostMarkOfAListenersTransaction(): void
    {
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $failOnce = false;

            public function rollBack(): bool
            {
                if ($this->failOnce) {
                    $this->failOnce = false;
                    throw new PDOException('rollback failed this once');
                }

                return parent::rollBack();
            }
        };
        $db = $this->driver($pdo);
        $once = false;
        $db->on('transaction.commit', static function () use ($db, $pdo, &$once): void {
            if ($once) {
                return;
            }
            $once = true;
            $pdo->failOnce = true;
            try {
                $db->transaction(static function (): void {
                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
                // the inner transaction reported lost and is still open
            }
        });
        $db->beginTransaction();
        $db->insert('users', ['name' => 'Max']);

        try {
            $db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(1, $e->failures, "the listener's open transaction, cleaned up raw");
            $this->assertFalse($e->connectionInTransaction);
        }
        $this->assertSame(['lost', 'committed'], array_column($this->ends, 'outcome'), 'the inner transaction told its end as lost, the raw cleanup adds none');
        $this->assertFalse($db->inTransaction());

        $pdo->beginTransaction();
        $db->commit();
        $this->assertSame(['lost', 'committed', 'committed'], array_column($this->ends, 'outcome'), 'the next transaction owes its end: the connection was clean');
    }

    /**
     * Two commit listeners leave transactions open; the second one's cleanup fails. The buffered
     * ends are delivered after the loop: the first end's listener rolls the still open second
     * transaction back itself - that tells no end, because its 'lost' is already buffered and marked.
     */
    public function testTwoBufferedInnerEndsTellEachTransactionExactlyOnce(): void
    {
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $failOnce = false;

            public function rollBack(): bool
            {
                if ($this->failOnce) {
                    $this->failOnce = false;

                    return false;
                }

                return parent::rollBack();
            }
        };
        $db = $this->driver($pdo);
        $commits = 0;
        $db->on('transaction.commit', static function () use ($db, &$commits): void {
            $commits++;
            if ($commits === 1) {
                $db->beginTransaction(); // left open, cleaned up raw
            }
        });
        $db->on('transaction.commit', static function () use ($db, $pdo, &$commits): void {
            if ($commits === 1) {
                $db->beginTransaction(); // left open, and its raw cleanup will fail
                $pdo->failOnce = true;
            }
        });
        $firstInnerEndSeen = false;
        $db->on('transaction.end', static function (array $data) use ($db, &$firstInnerEndSeen): void {
            if (!$firstInnerEndSeen && $data['outcome'] === 'rolled_back' && $data['error'] instanceof LogicException) {
                $firstInnerEndSeen = true;
                $db->rollback(); // ends the second listener's still open transaction before its buffered 'lost' is delivered
            }
        });
        $db->beginTransaction();
        $db->insert('users', ['name' => 'Max']);

        try {
            $db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(2, $e->failures, 'both listeners left a transaction open');
            $this->assertTrue($e->connectionInTransaction, 'computed before the end listeners: the second cleanup had failed');
        }

        $this->assertSame(['commit', 'end', 'rollback', 'end', 'end'], $this->events, 'the explicit rollback in between tells no end of its own');
        $this->assertSame(['rolled_back', 'lost', 'committed'], array_column($this->ends, 'outcome'), 'each transaction exactly once');
        $this->assertFalse($db->inTransaction());
        $this->assertSame(1, $db->table('users')->count());
    }

    /**
     * A commit listener's transaction() reports 'lost' (its rollback failed once) and the listener
     * discards it raw; after the listener PDO confirms no transaction, so the lost mark is cleared and
     * a later transaction ended through the library owes its end.
     */
    public function testALostMarkIsClearedWhenPdoConfirmsNoTransactionAfterACommitListener(): void
    {
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public bool $failOnce = false;

            public function rollBack(): bool
            {
                if ($this->failOnce) {
                    $this->failOnce = false;
                    throw new PDOException('rollback failed this once');
                }

                return parent::rollBack();
            }
        };
        $db = $this->driver($pdo);
        $once = false;
        $db->on('transaction.commit', static function () use ($db, $pdo, &$once): void {
            if ($once) {
                return;
            }
            $once = true;
            $pdo->failOnce = true;
            try {
                $db->transaction(static function (): void {
                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
            }
            $pdo->rollBack(); // the listener discards the lost transaction raw
        });
        $db->beginTransaction();
        $db->commit();

        $this->assertSame(['lost', 'committed'], array_column($this->ends, 'outcome'));
        $this->assertFalse($db->inTransaction());

        $pdo->beginTransaction();
        $db->commit();
        $this->assertSame(['lost', 'committed', 'committed'], array_column($this->ends, 'outcome'), 'PDO confirmed a clean connection after the listener: the mark is gone');
    }

    /**
     * A commit listener begins a transaction through the library and ends it raw; a later commit
     * listener leaves a transaction begun on raw PDO open. The raw one gets no end of its own: the
     * library's mark from the first listener's transaction was cleared when PDO confirmed no
     * transaction after it.
     */
    public function testAListenerTransactionEndedRawLeavesNoMarkForALaterRawTransaction(): void
    {
        $db = $this->driver();
        $pdo = $db->getPdo();
        $commits = 0;
        $db->on('transaction.commit', static function () use ($db, $pdo, &$commits): void {
            if (++$commits === 1) {
                $db->beginTransaction();
                $pdo->rollBack(); // ended raw: gone without an event
            }
        });
        $db->on('transaction.commit', static function () use ($pdo, &$commits): void {
            if ($commits === 1) {
                $pdo->beginTransaction(); // raw, left open: cleaned up raw, no end of its own
            }
        });
        $db->beginTransaction();
        $db->insert('users', ['name' => 'Max']);

        try {
            $db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(1, $e->failures, 'the raw transaction the second listener left open');
            $this->assertFalse($e->connectionInTransaction);
        }

        $this->assertSame(['commit', 'end'], $this->events);
        $this->assertSame([['outcome' => 'committed', 'error' => null]], $this->ends, 'only the committed transaction tells its end');
        $this->assertFalse($db->inTransaction());
    }

    /**
     * In-memory SQLite driver (optionally on a prepared PDO) with a users table and listeners that
     * record 'commit', 'rollback' and 'end' in order, the end payloads, and 'error' hook contexts.
     */
    private function driver(?PDO $pdo = null): SqliteDriver
    {
        $db = $pdo === null ? new SqliteDriver(':memory:') : new class ($pdo) extends SqliteDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
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
            $this->ends[] = ['outcome' => $data['outcome'], 'error' => $data['error']];
        });
        $db->on('error', function (array $data): void {
            $this->errors[] = $data;
        });

        return $db;
    }
}
