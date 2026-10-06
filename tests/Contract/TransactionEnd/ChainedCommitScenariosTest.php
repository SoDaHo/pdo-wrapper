<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\TransactionEnd;

use LogicException;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Throwable;

/**
 * A failed COMMIT on a session that may chain transactions: after it the driver asks the server
 * for completion_type (ScenarioPdo::$completionType gives the answer), and unless the answer is
 * NO_CHAIN, whatever ends the transaction as rolled back confirms nothing. The session itself does
 * not chain here; the COMMIT fails before it is sent (ScenarioPdo::$failCommit), so that nothing is
 * committed and every 'lost' below is the fail-closed answer.
 */
class ChainedCommitScenariosTest extends TransactionEndTestCase
{
    private RememberingDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new RememberingDriver($this->pdo);
        $this->driver->on('transaction.rollback', function (): void {
            $this->events[] = 'rollback';
        });
        $this->driver->on('transaction.end', function (array $data): void {
            $this->events[] = 'end';
            $this->ends[] = ['outcome' => (string) $data['outcome'], 'error' => $data['error'] instanceof Throwable ? $data['error'] : null];
            $this->outcomesSeen[] = $data['error'] instanceof CommitFailedException ? $data['error']->outcome : null;
        });
        $this->pdo->completionType = 'CHAIN';
    }

    /**
     * transaction(): the commit failed, PDO still reports the transaction, and the session may
     * chain. The ROLLBACK is sent to clean up, but confirms nothing: no rollback listener, the end
     * and the exception say 'lost'. Every answer but NO_CHAIN counts; on NO_CHAIN it stays
     * 'rolled_back'.
     */
    public function testAFailedCommitThatMayHaveChainedEndsAsLostInTransaction(): void
    {
        foreach (['CHAIN' => self::LOST, 'RELEASE' => self::LOST, 'NO_CHAIN' => self::ROLLED_BACK] as $answer => $outcome) {
            $this->pdo->completionType = $answer;
            $this->events = [];
            $this->ends = [];
            $this->outcomesSeen = [];
            $sent = $this->pdo->rollBackCalls;
            $asked = $this->pdo->queryCalls;
            try {
                $this->driver->transaction(function (DatabaseInterface $db): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                    $this->pdo->failCommit = true;
                });
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $e) {
                $this->assertSame($outcome, $e->outcome, $answer);
                $this->assertSame([['outcome' => $outcome, 'error' => $e]], $this->ends, $answer);
                $this->assertSame([$outcome], $this->outcomesSeen, 'written before the end listeners ran');
            }
            $this->assertSame($outcome === self::LOST ? ['end'] : ['rollback', 'end'], $this->events, $answer . ': a rollback listener only for a confirmed rollback');
            $this->assertSame($sent + 1, $this->pdo->rollBackCalls, 'the ROLLBACK is sent either way');
            $this->assertSame($asked + 1, $this->pdo->queryCalls, 'asked once');
            $this->assertVisible([]);
        }
    }

    /**
     * The same for a commit() and rollback() the caller issues: the failed commit's exception is
     * not written to (its outcome stays null), the rollback tells 'lost' with it as error.
     * PDO::commit() returning false (a non-exception error mode) is a failed COMMIT as well.
     */
    public function testAManualRollbackAfterAFailedCommitThatMayHaveChainedTellsLost(): void
    {
        foreach (['throws', 'returns false'] as $how) {
            $this->events = [];
            $this->ends = [];
            $this->driver->beginTransaction();
            $this->driver->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
            if ($how === 'throws') {
                $this->pdo->failCommit = true;
            } else {
                $this->pdo->commitReturnsFalse = true;
            }
            try {
                $this->driver->commit();
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $failure) {
                $this->assertNull($failure->outcome);
            }
            $this->assertSame([], $this->ends, 'the transaction is still the caller\'s to end');

            $this->driver->rollback();
            $this->assertNull($failure->outcome, 'a commit the caller issued is not written to');
            $this->assertSame([['outcome' => self::LOST, 'error' => $failure]], $this->ends, $how);
            $this->assertSame(['end'], $this->events, $how);
            $this->assertVisible([]);
        }
    }

    /**
     * What a PDO class of the caller's or an error handler throws from commit() passes unchanged,
     * and the transaction is the caller's to end - but the COMMIT may have taken effect all the
     * same, so the server is asked as after PDO's own failure.
     */
    public function testAForeignExceptionFromCommitPassesUnchangedAndIsAskedAbout(): void
    {
        foreach (['CHAIN' => self::LOST, 'NO_CHAIN' => self::ROLLED_BACK] as $answer => $outcome) {
            $this->pdo->completionType = $answer;
            $this->events = [];
            $this->ends = [];
            $thrown = new RuntimeException('thrown by the PDO class (scenario)');
            try {
                $this->driver->transaction(function (DatabaseInterface $db) use ($thrown): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                    $this->pdo->throwFromCommit = $thrown;
                });
                $this->fail('Expected RuntimeException');
            } catch (RuntimeException $e) {
                $this->assertSame($thrown, $e, 'unchanged');
            }
            $this->assertSame([['outcome' => $outcome, 'error' => $thrown]], $this->ends, $answer);
            $this->assertSame($outcome === self::LOST ? ['end'] : ['rollback', 'end'], $this->events, $answer);
            $this->assertVisible([]);
        }

        // A manual commit(): the caller's rollback() tells 'lost' with that exception
        $this->pdo->completionType = 'CHAIN';
        $this->ends = [];
        $this->driver->beginTransaction();
        $thrown = new RuntimeException('thrown by the PDO class (scenario)');
        $this->pdo->throwFromCommit = $thrown;
        try {
            $this->driver->commit();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame($thrown, $e);
        }
        $this->driver->rollback();
        $this->assertSame([['outcome' => self::LOST, 'error' => $thrown]], $this->ends);

        // The same for a transaction begun on raw PDO that was open when the COMMIT was sent
        $this->ends = [];
        $this->pdo->beginTransaction();
        $thrown = new RuntimeException('thrown by the PDO class (scenario)');
        $this->pdo->throwFromCommit = $thrown;
        try {
            $this->driver->commit();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame($thrown, $e);
        }
        $this->driver->rollback();
        $this->assertSame([['outcome' => self::LOST, 'error' => $thrown]], $this->ends);
        $this->assertVisible([]);
    }

    /**
     * Asked whenever PDO does not say that the transaction is gone: not when it reports none (that
     * is 'lost' at once), but when the state cannot be read - the rollback later, with the state
     * readable again, confirms nothing then.
     */
    public function testTheDriverIsAskedUnlessPdoSaysTheTransactionIsGone(): void
    {
        // PDO reports no transaction after the failed COMMIT
        $this->pdo->vanishOnFailedCommit = true;
        $asked = $this->pdo->queryCalls;
        try {
            $this->driver->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
        }
        $this->pdo->vanishOnFailedCommit = false;
        $this->pdo->hideTransaction = false;
        $this->pdo->rollBack(); // what the simulation hid
        $this->assertSame($asked, $this->pdo->queryCalls);

        // The state cannot be read after the failed COMMIT
        $this->driver->beginTransaction();
        $this->pdo->failCommit = true;
        $this->pdo->duringCommit = function (): void {
            $this->pdo->stateUnreadable = true;
        };
        try {
            $this->driver->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $failure) {
            // expected
        }
        $this->pdo->stateUnreadable = false;
        $this->assertSame($asked + 1, $this->pdo->queryCalls);
        $this->ends = [];
        $this->events = [];
        $this->driver->rollback();
        $this->assertSame([['outcome' => self::LOST, 'error' => $failure]], $this->ends, 'readable again, but nothing confirmed');
        $this->assertSame(['end'], $this->events);

        // A transaction begun on raw PDO that was open when the COMMIT was sent is asked about
        $this->pdo->beginTransaction();
        $this->pdo->failCommit = true;
        try {
            $this->driver->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $failure) {
            // expected
        }
        $this->assertSame($asked + 2, $this->pdo->queryCalls);
        $this->ends = [];
        $this->events = [];
        $this->driver->rollback();
        $this->assertSame([['outcome' => self::LOST, 'error' => $failure]], $this->ends);
        $this->assertSame(['end'], $this->events);
        $this->assertVisible([]);
    }

    /**
     * Not asked either: after a commit the driver refused (nothing was sent), and for a
     * transaction whose 'lost' was already told while it may still be open (nothing owes its end).
     */
    public function testTheDriverIsNotAskedWhenNoCommitWasSentOrNoEndIsOwed(): void
    {
        $asked = $this->pdo->queryCalls;
        $this->driver->refusal = 'ended by the server (scenario)';
        $this->driver->beginTransaction();
        try {
            $this->driver->query('SELECT * FROM no_such_table');
        } catch (QueryException) {
            // swallowed
        }
        try {
            $this->driver->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame('ended by the server (scenario)', $e->getDebugMessage());
        }
        $this->assertSame($asked, $this->pdo->queryCalls);
        $this->driver->refusal = null;
        $this->driver->rollback();

        // The rollback after the callback's exception fails: 'lost', and the transaction may still be open
        $this->pdo->failRollBackAlways = true;
        try {
            $this->driver->transaction(static function (): void {
                throw new RuntimeException('the callback failed');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // expected
        }
        $this->pdo->failRollBackAlways = false;
        $this->assertTrue($this->pdo->reallyInTransaction());
        $this->pdo->failCommit = true;
        try {
            $this->driver->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException) {
            // expected
        }
        $this->assertSame($asked, $this->pdo->queryCalls);
        $this->pdo->rollBack();
    }

    /**
     * After the failed commit the caller runs a statement that fails, and the driver cannot find
     * out before the rollback whether the transaction still exists: the end is 'lost' either way,
     * and it names the failed commit - the reason the data may be committed.
     */
    public function testTheEndNamesTheFailedCommitAheadOfAStatementFailureSince(): void
    {
        $this->driver->stateKnown = false;
        $this->driver->beginTransaction();
        $this->pdo->failCommit = true;
        try {
            $this->driver->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $failure) {
            // expected
        }
        try {
            $this->driver->query('SELECT * FROM no_such_table');
        } catch (QueryException) {
            // swallowed
        }
        $this->driver->rollback();
        $this->assertSame([['outcome' => self::LOST, 'error' => $failure]], $this->ends);
        $this->assertSame(['end'], $this->events);
        $this->assertVisible([]);
    }

    /**
     * The same where the transaction turns out to be gone before any ROLLBACK is sent: a statement
     * failure since that ended it for certain (a deadlock) and PDO knows it, or the driver's
     * question before the rollback finds it gone. The end is 'lost', and it names the failed commit.
     */
    public function testTheEarlyLostEndsNameTheFailedCommitToo(): void
    {
        foreach (['over' => true, 'gone when asked' => false] as $case => $over) {
            $this->driver->over = $over;
            $this->driver->goneWhenAsked = !$over;
            $this->ends = [];
            $this->events = [];
            $this->driver->beginTransaction();
            $this->pdo->failCommit = true;
            try {
                $this->driver->commit();
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $failure) {
                // expected
            }
            try {
                $this->driver->query('SELECT * FROM no_such_table');
            } catch (QueryException) {
                // swallowed
            }
            if ($over) {
                $this->pdo->hideTransaction = true; // a statement on raw PDO told PDO
            }
            $sent = $this->pdo->rollBackCalls;
            $this->driver->rollback();
            $this->assertSame([['outcome' => self::LOST, 'error' => $failure]], $this->ends, $case);
            $this->assertSame(['end'], $this->events, $case);
            $this->assertSame($sent, $this->pdo->rollBackCalls, $case . ': nothing sent');
            $this->pdo->hideTransaction = false;
            $this->pdo->rollBack(); // what the simulation hid
        }
    }

    /**
     * Foreign code inside the COMMIT (an error handler) sends a statement through the driver - it
     * may have switched completion_type: the answer NO_CHAIN after it proves nothing, and the
     * rollback confirms nothing.
     */
    public function testTheAnswerDoesNotCountWhenAStatementWentThroughTheDriverSinceTheCommit(): void
    {
        $this->pdo->completionType = 'NO_CHAIN';
        $driver = $this->driver;
        foreach (['a statement inside the COMMIT' => self::LOST, 'none' => self::ROLLED_BACK] as $case => $outcome) {
            $this->ends = [];
            if ($outcome === self::LOST) {
                $this->pdo->duringCommit = static function () use ($driver): void {
                    $driver->execute("SET SESSION completion_type = 'NO_CHAIN'"); // what an error handler may send
                };
            }
            try {
                $driver->transaction(function (DatabaseInterface $db): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                    $this->pdo->failCommit = true;
                });
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $e) {
                $this->assertSame([['outcome' => $outcome, 'error' => $e]], $this->ends, $case);
            }
            $this->assertVisible([]);
        }
    }

    /**
     * A foreign exception from commit() after which PDO says, or learns from the question, that no
     * transaction is left: nothing could end it afterwards, so its end is told at once - 'lost',
     * with that exception, which passes unchanged - as after PDO's own failure.
     */
    public function testAForeignExceptionThatLeavesNoTransactionIsToldAsLostAtOnce(): void
    {
        foreach (['PDO says so' => 'after', 'the question tells PDO' => 'before'] as $case => $when) {
            $this->ends = [];
            $this->driver->beginTransaction();
            $this->driver->insert(self::TABLE, ['id' => 1, 'name' => $case]);
            $thrown = new RuntimeException('thrown by the PDO class (scenario)');
            if ($when === 'after') {
                $this->pdo->throwAfterCommit = $thrown; // committed: PDO reports no transaction
            } else {
                $this->pdo->throwFromCommit = $thrown;
                $this->pdo->duringQuery = function (): void {
                    $this->pdo->hideTransaction = true;
                };
            }
            try {
                $this->driver->commit();
                $this->fail('Expected RuntimeException');
            } catch (RuntimeException $e) {
                $this->assertSame($thrown, $e, $case);
            }
            $this->assertSame([['outcome' => self::LOST, 'error' => $thrown]], $this->ends, $case . ': told at once');
            $this->assertNull($this->driver->currentTransaction(), $case);
            $this->pdo->hideTransaction = false;
            if ($this->pdo->reallyInTransaction()) {
                $this->pdo->rollBack(); // what the simulation hid
            }
            $this->observer->delete(self::TABLE, ['id' => 1]);
        }
    }

    /**
     * A CommitFailedException that a PDO class or an error handler throws from commit() is not
     * the library's: when PDO reports no transaction after it, the end is told at once with it as
     * error, and nothing is written into it - one with an outcome keeps it, one without stays
     * without.
     */
    public function testAForeignCommitFailedExceptionIsNeverWrittenTo(): void
    {
        foreach ([null, self::ROLLED_BACK] as $outcome) {
            $this->ends = [];
            $foreign = new CommitFailedException(message: 'thrown by the PDO class (scenario)');
            if ($outcome !== null) {
                $foreign->settle($outcome);
            }
            $this->driver->beginTransaction();
            $this->pdo->throwAfterCommit = $foreign;
            try {
                $this->driver->commit();
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $e) {
                $this->assertSame($foreign, $e, 'unchanged');
            }
            $this->assertSame($outcome, $foreign->outcome, 'not written to');
            $this->assertSame([['outcome' => self::LOST, 'error' => $foreign]], $this->ends);
        }
    }

    /**
     * A statement that foreign code inside the COMMIT tries to send through the driver, but that the
     * driver refuses before it reaches the server, changes nothing on the session: the answer
     * NO_CHAIN counts, and the rollback is a confirmed one.
     */
    public function testAStatementRefusedBeforeItWasSentDoesNotCount(): void
    {
        $this->pdo->completionType = 'NO_CHAIN';
        $driver = $this->driver;
        $this->pdo->duringCommit = static function () use ($driver): void {
            try {
                $driver->query('SELECT ?', [INF]); // refused: MariaDB has no such number
            } catch (QueryException) {
                // what an error handler may swallow
            }
        };
        try {
            $driver->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends);
        }
        $this->assertVisible([]);
    }

    /**
     * A statement of the foreign code that reached the server counts, even when it failed there:
     * it may have changed the session before it failed. One the server refused to prepare was
     * never run, and does not count.
     */
    public function testAStatementThatRanCountsEvenWhenItFailed(): void
    {
        $this->pdo->completionType = 'NO_CHAIN';
        $driver = $this->driver;
        $observer = $this->observer;
        $observer->insert(self::TABLE, ['id' => 9, 'name' => 'taken']);
        foreach (['executed, then failed' => self::LOST, 'not prepared' => self::ROLLED_BACK] as $case => $outcome) {
            $this->ends = [];
            $this->pdo->duringCommit = static function () use ($driver, $case): void {
                try {
                    if ($case === 'not prepared') {
                        $driver->query('SELEKT 1'); // the server refuses to prepare it
                    } else {
                        $driver->insert(self::TABLE, ['id' => 9, 'name' => 'a duplicate']); // runs, fails on the key
                    }
                } catch (QueryException) {
                    // what an error handler may swallow
                }
            };
            try {
                $driver->transaction(function (DatabaseInterface $db): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                    $this->pdo->failCommit = true;
                });
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $e) {
                $this->assertSame([['outcome' => $outcome, 'error' => $e]], $this->ends, $case);
            }
        }
        $observer->delete(self::TABLE, ['id' => 9]);
        $this->assertVisible([]);
    }

    /**
     * A commit listener's transaction whose commit() failed on a session that may chain, and the
     * state cannot be read after the listener: 'lost', and the end names the failed commit.
     */
    public function testACommitListenersUnclearTransactionWithAnUnreadableStateNamesTheFailedCommit(): void
    {
        $driver = $this->driver;
        $failed = null;
        $driver->on('transaction.commit', function () use ($driver, &$failed): void {
            $driver->beginTransaction();
            $this->pdo->failCommit = true;
            try {
                $driver->commit();
            } catch (CommitFailedException $e) {
                $failed = $e;
            }
            $this->pdo->stateUnreadable = true;
        });
        try {
            $driver->transaction(static fn () => null);
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertTrue($e->connectionInTransaction, 'fail-closed');
        }
        $this->pdo->stateUnreadable = false;
        $this->assertCount(2, $this->ends);
        $this->assertSame(self::LOST, $this->ends[0]['outcome']);
        $this->assertNotNull($failed);
        $this->assertSame($failed, $this->ends[0]['error']);
        $this->assertSame(self::COMMITTED, $this->ends[1]['outcome']);
        $this->pdo->rollBack();
    }

    /**
     * The question is a call into PDO. When it makes PDO learn that the transaction is gone - also
     * on a session that does not chain: the server answered the COMMIT with a failure and ended the
     * transaction (a deadlock at commit) -, the end is 'lost' at once and nothing is sent. When
     * foreign code inside it ends the transaction through the driver, that call has told the end,
     * and its rollback confirms nothing, whatever the answer: it came before the answer.
     */
    public function testWhatHappensInsideTheQuestionIsSeen(): void
    {
        foreach (['CHAIN', 'NO_CHAIN'] as $answer) {
            $this->pdo->completionType = $answer;
            $this->pdo->duringQuery = function (): void {
                $this->pdo->hideTransaction = true;
            };
            $this->events = [];
            $this->ends = [];
            $sent = $this->pdo->rollBackCalls;
            try {
                $this->driver->transaction(function (DatabaseInterface $db): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                    $this->pdo->failCommit = true;
                });
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $e) {
                $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends, $answer);
                $this->assertSame(self::LOST, $e->outcome, $answer);
            }
            $this->assertSame(['end'], $this->events, $answer);
            $this->assertSame($sent, $this->pdo->rollBackCalls, $answer . ': nothing left to roll back');
            $this->pdo->hideTransaction = false;
            $this->pdo->rollBack(); // what the simulation hid
        }

        $driver = $this->driver;
        foreach (['CHAIN', 'NO_CHAIN'] as $answer) {
            $this->pdo->completionType = $answer;
            $this->pdo->duringQuery = static function () use ($driver): void {
                $driver->rollback(); // an error handler that ends the transaction
            };
            $this->events = [];
            $this->ends = [];
            try {
                $driver->transaction(function (DatabaseInterface $db): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                    $this->pdo->failCommit = true;
                });
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $e) {
                $this->assertSame(self::LOST, $e->outcome, $answer . ': nothing confirms what became of the commit');
            }
            $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends, $answer . ': told once, by the rollback inside the question');
            $this->assertSame(['end'], $this->events, $answer);
            $this->assertVisible([]);
        }
    }

    /**
     * A second COMMIT of the same transaction fails as well, and this time the answer is
     * NO_CHAIN: the first one may still have taken effect, and the rollback confirms nothing.
     */
    public function testAnEarlierFailedCommitOfTheTransactionStillCounts(): void
    {
        $this->driver->beginTransaction();
        $this->pdo->failCommit = true;
        try {
            $this->driver->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $first) {
            // expected
        }
        $this->pdo->completionType = 'NO_CHAIN';
        $this->pdo->failCommit = true;
        try {
            $this->driver->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException) {
            // expected
        }
        $this->driver->rollback();
        $this->assertSame([['outcome' => self::LOST, 'error' => $first]], $this->ends);
        $this->assertSame(['end'], $this->events);
        $this->assertVisible([]);
    }

    /**
     * What the failed commit says about the transaction holds for that transaction only: not once
     * it was ended (a commit() that went through after all), not for one begun through the driver
     * after it was ended on raw PDO.
     */
    public function testTheUnclearCommitGoesWithItsTransaction(): void
    {
        $this->driver->beginTransaction();
        $this->pdo->failCommit = true;
        try {
            $this->driver->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException) {
            // expected
        }
        $this->driver->commit();
        $this->pdo->beginTransaction();
        $this->events = [];
        $this->ends = [];
        $this->driver->rollback();
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => null]], $this->ends);
        $this->assertSame(['rollback', 'end'], $this->events);

        $this->pdo->beginTransaction();
        $this->pdo->failCommit = true;
        try {
            $this->driver->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException) {
            // expected
        }
        $this->pdo->rollBack();
        $this->driver->beginTransaction();
        $this->events = [];
        $this->ends = [];
        $this->driver->rollback();
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => null]], $this->ends);
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertVisible([]);
    }

    /**
     * A 'transaction.begin' listener commits the transaction it was told about, the commit fails
     * on a session that may chain, and the listener throws: the raw rollback that undoes the
     * transaction confirms nothing, the end is 'lost'. On NO_CHAIN it is 'rolled_back'.
     */
    public function testABeginListenerWhoseCommitFailedThenThrowsEndsAsLost(): void
    {
        $driver = $this->driver;
        $driver->on('transaction.begin', function () use ($driver): void {
            $this->pdo->failCommit = true;
            try {
                $driver->commit();
            } catch (CommitFailedException) {
                throw new RuntimeException('the listener gave up');
            }
        });
        foreach (['CHAIN' => self::LOST, 'NO_CHAIN' => self::ROLLED_BACK] as $answer => $outcome) {
            $this->pdo->completionType = $answer;
            $this->ends = [];
            try {
                $driver->transaction(static fn () => null);
                $this->fail('Expected RuntimeException');
            } catch (RuntimeException $e) {
                $this->assertSame([['outcome' => $outcome, 'error' => $e]], $this->ends, $answer);
            }
            $this->assertVisible([]);
        }
    }

    /**
     * A 'transaction.commit' listener runs a transaction of its own; its COMMIT fails on a session
     * that may chain, the listener catches that and returns with the transaction open. The raw
     * cleanup rolls it back, but confirms nothing: its own end is 'lost' with the failed commit as
     * error, then the outer 'committed'. On NO_CHAIN it is 'rolled_back'.
     */
    public function testACommitListenersTransactionWhoseCommitFailedIsToldAsLost(): void
    {
        $driver = $this->driver;
        $failed = null;
        $driver->on('transaction.commit', function () use ($driver, &$failed): void {
            $driver->beginTransaction();
            $driver->insert(self::TABLE, ['id' => 2, 'name' => 'by the listener']);
            $this->pdo->failCommit = true;
            try {
                $driver->commit();
            } catch (CommitFailedException $e) {
                $failed = $e;
            }
        });
        foreach (['CHAIN' => self::LOST, 'NO_CHAIN' => self::ROLLED_BACK] as $answer => $outcome) {
            $this->pdo->completionType = $answer;
            $this->ends = [];
            try {
                $driver->transaction(static fn (DatabaseInterface $db) => $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']));
                $this->fail('Expected CommitHookException');
            } catch (CommitHookException $e) {
                $this->assertFalse($e->connectionInTransaction, $answer . ': cleaned up');
                $this->assertInstanceOf(LogicException::class, $e->getPrevious());
            }
            $this->assertCount(2, $this->ends, $answer);
            $this->assertSame($outcome, $this->ends[0]['outcome'], $answer . ': the listener\'s transaction');
            if ($outcome === self::LOST) {
                $this->assertSame($failed, $this->ends[0]['error']);
            }
            $this->assertSame(self::COMMITTED, $this->ends[1]['outcome']);
            $this->assertVisible([1]);
            $this->observer->delete(self::TABLE, ['id' => 1]);
        }
    }

    /**
     * The chained transaction a session opens with the cleanup ROLLBACK is reported as after every
     * other ROLLBACK (see chainedTransaction()): thrown from a manual rollback(), after its 'lost'.
     */
    public function testAChainedTransactionAfterTheUnconfirmedRollbackIsThrown(): void
    {
        $this->driver->beginTransaction();
        $this->pdo->failCommit = true;
        try {
            $this->driver->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $failure) {
            // expected
        }
        $this->pdo->duringRollBack = function (): void {
            $this->pdo->exec('SET SESSION completion_type = CHAIN');
        };
        try {
            $this->driver->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Connection is in a new transaction', $e->getMessage());
        } finally {
            $this->pdo->exec('SET SESSION completion_type = NO_CHAIN');
        }
        $this->assertSame([['outcome' => self::LOST, 'error' => $failure]], $this->ends);
        $this->assertTrue($this->pdo->reallyInTransaction(), 'the chained one');
        $this->pdo->rollBack();
    }
}
