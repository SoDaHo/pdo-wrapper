<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\TransactionEnd;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;

class SqliteTransactionEndScenariosTest extends AbstractTransactionEndScenarios
{
    protected function makeScenarioPdo(): ScenarioPdo
    {
        return new ScenarioPdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    protected function makeDriver(ScenarioPdo $pdo): DatabaseInterface
    {
        return new class ($pdo) extends SqliteDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
    }

    protected function makeObserver(): ?DatabaseInterface
    {
        return null; // an in-memory database has no second connection
    }

    protected function createTableSql(): string
    {
        return 'CREATE TABLE ' . self::TABLE . ' (id INTEGER PRIMARY KEY, name TEXT)';
    }

    protected function aFailedStatementAbortsTheTransaction(): bool
    {
        return false;
    }

    // ---- the extension point behind the refused commit (PostgreSQL, MySQL deadlock) ---------------

    /**
     * A driver may flag statement failures that can end a transaction on the server and confirm
     * at commit time. Flagging alone refuses nothing: the default answer is "still committable".
     */
    public function testADriverThatOnlyFlagsStatementFailuresStillCommits(): void
    {
        $db = new class ($this->pdo) extends SqliteDriver {
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
        $ends = [];
        $db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data;
        });

        // still reported by PDO: nothing is told, the caller's rollback ends it
        $db->beginTransaction();
        $fail('fatal_table');
        try {
            $db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame([], $ends);
            $this->assertNull($e->outcome);
        }
        $db->rollback();
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_ROLLED_BACK, 'error' => null]], $ends);

        // no longer reported by PDO: the refusal tells 'lost', with itself as the error
        $ends = [];
        $db->beginTransaction();
        $fail('fatal_table');
        $this->pdo->hideTransaction = true;
        try {
            $db->commit();
            $this->fail('Expected TransactionException');
        } catch (CommitFailedException $e) {
            $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $e]], $ends);
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome);
        } finally {
            $this->pdo->hideTransaction = false;
        }
        $this->pdo->rollBack();

        // and transaction() tells that end exactly once
        $ends = [];
        try {
            $db->transaction(function () use ($fail): void {
                $fail('fatal_table');
                $this->pdo->hideTransaction = true;
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $e]], $ends);
        } finally {
            $this->pdo->hideTransaction = false;
        }
        $this->pdo->rollBack();

        // a transaction this library did not begin (raw PDO) has no end to tell
        $ends = [];
        $this->pdo->beginTransaction();
        $fail('fatal_table');
        $this->pdo->hideTransaction = true;
        try {
            $db->commit();
            $this->fail('Expected TransactionException');
        } catch (TransactionException) {
            $this->assertSame([], $ends);
        } finally {
            $this->pdo->hideTransaction = false;
        }
        $this->pdo->rollBack();

        // a state that cannot be read is not "gone": nothing is told
        $ends = [];
        $db->beginTransaction();
        $fail('fatal_table');
        $this->pdo->stateUnreadable = true;
        try {
            $db->commit();
            $this->fail('Expected TransactionException');
        } catch (TransactionException) {
            $this->assertSame([], $ends);
        } finally {
            $this->pdo->stateUnreadable = false;
        }
        $db->rollback();
    }

    /**
     * A driver that knows a failure ended the transaction for certain (MySQL/MariaDB: a deadlock)
     * has the library accept nothing but the end of that transaction: no statement, no new
     * transaction. For a transaction this library began that holds whatever PDO reports; one begun
     * on raw PDO is only held for as long as PDO reports it.
     */
    public function testAfterACertainEndNothingIsSentUntilTheRollback(): void
    {
        $db = new class ($this->pdo) extends SqliteDriver {
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
        $assertRefused = function () use ($db): void {
            try {
                $db->insert(self::TABLE, ['id' => 9, 'name' => 'outside']);
                $this->fail('Expected QueryException: nothing is sent');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Not sent: the server rolled the open transaction back', (string) $e->getDebugMessage());
                $this->assertStringContainsString('fatal_table', (string) $e->getPrevious()?->getMessage());
            }
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
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $failure]], $ends, 'nothing to roll back: the end is told as lost, with the failure');
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
        $this->assertSame([2, 3, 4], array_map('intval', array_column($db->table(self::TABLE)->orderBy('id')->get(), 'id')));

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
     * A custom driver may override commit() and throw what it likes - here the exception of an
     * earlier transaction, whose second commit attempt went through. transaction() rolls back,
     * but that exception is not the failure of the commit it ran: it is not written to.
     */
    public function testAnOverridingCommitThatThrowsAnOlderFailedCommitDoesNotGetItSettled(): void
    {
        $db = new class ($this->pdo) extends SqliteDriver {
            public ?\Throwable $throwFromCommit = null;

            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            public function commit(): void
            {
                if ($this->throwFromCommit !== null) {
                    $thrown = $this->throwFromCommit;
                    $this->throwFromCommit = null;

                    throw $thrown;
                }
                parent::commit();
            }
        };
        $ends = [];
        $db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data;
        });

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

        $ends = [];
        $db->throwFromCommit = $old;
        try {
            $db->transaction(static fn (SqliteDriver $db) => $db->insert(self::TABLE, ['id' => 2, 'name' => 'b']));
            $this->fail('Expected the exception of the overriding commit()');
        } catch (CommitFailedException $e) {
            $this->assertSame($old, $e);
        }
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST, DatabaseInterface::TRANSACTION_ROLLED_BACK], array_column($ends, 'outcome'), 'the first transaction as ended behind the library, then this one');
        $this->assertSame($old, $ends[1]['error']);
        $this->assertNull($old->outcome, 'its own transaction was committed on the second attempt');
        $this->assertSame([1], array_column($db->table(self::TABLE)->get(), 'id'));
    }

    /**
     * A custom driver may override rollback() too - here it gets the failed commit through after
     * all and moves on to another transaction before it calls the parent. What the parent rolls
     * back then is not the transaction whose commit failed: no 'rolled_back' for that exception.
     */
    public function testAnOverridingRollbackThatCommitsAfterAllVoidsTheSettlement(): void
    {
        foreach (['commits through the library, begins on raw PDO', 'commits on raw PDO, begins through the library'] as $how) {
            $db = new class ($this->pdo) extends SqliteDriver {
                public ?string $retry = null;

                public function __construct(PDO $pdo)
                {
                    $this->pdo = $pdo;
                }

                public function rollback(): void
                {
                    $retry = $this->retry;
                    $this->retry = null;
                    if ($retry === 'commits through the library, begins on raw PDO') {
                        $this->commit();
                        $this->pdo->beginTransaction();
                    } elseif ($retry !== null) {
                        $this->pdo->commit();
                        $this->beginTransaction();
                    }
                    parent::rollback();
                }
            };
            $db->execute('DELETE FROM ' . self::TABLE);
            $db->retry = $how;

            try {
                $db->transaction(function (SqliteDriver $db): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                    $this->pdo->failCommit = true;
                });
                $this->fail('Expected CommitFailedException: ' . $how);
            } catch (CommitFailedException $e) {
                $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome, $how);
            }
            $this->assertFalse($this->pdo->reallyInTransaction(), $how);
            $this->assertSame([1], array_column($db->table(self::TABLE)->get(), 'id'), 'the row is committed: ' . $how);
        }
    }

    /**
     * An overriding rollback() that does not go through the one of this library tells no end. The
     * failed commit of transaction() still leaves with an outcome - 'lost', nothing was confirmed -
     * and what nobody took is taken back: a later transaction rolled back with the same exception
     * does not write into it.
     */
    public function testAFailedCommitLeavesWithAnOutcomeWhenAnOverridingRollbackTellsNoEnd(): void
    {
        $db = new class ($this->pdo) extends SqliteDriver {
            public bool $bypass = true;

            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            public function rollback(): void
            {
                if ($this->bypass) {
                    $this->pdo->rollBack();

                    return;
                }
                parent::rollback();
            }
        };
        $ends = [];
        $db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data['outcome'];
        });

        try {
            $db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failCommit = true;
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $first) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $first->outcome, 'never without an outcome');
        }
        $this->assertSame([], $ends, 'the override told none');
        $this->assertFalse($this->pdo->reallyInTransaction());

        $db->bypass = false;
        try {
            $db->transaction(static function () use ($first): void {
                throw $first;
            });
            $this->fail('Expected the callback exception');
        } catch (CommitFailedException $e) {
            $this->assertSame($first, $e);
        }
        $this->assertSame(
            [DatabaseInterface::TRANSACTION_LOST, DatabaseInterface::TRANSACTION_ROLLED_BACK],
            $ends,
            'the end the override never told is told when the next transaction begins; then the end of that one'
        );
        $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $first->outcome, 'the later rollback is not about that commit');
    }

    /**
     * A rollback that tells no end - a 'lost' was told before, for a transaction that may still
     * be open - confirms nothing: the failed commit says 'lost', not 'rolled_back'. Since
     * transaction() ends only the transaction it began, only a driver whose beginTransaction()
     * bypasses this library's gets there: its transactions have no number.
     */
    public function testARollbackThatTellsNoEndDoesNotMakeAFailedCommitRolledBack(): void
    {
        $db = new class ($this->pdo) extends SqliteDriver {
            public bool $join = false;

            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            public function beginTransaction(): void
            {
                if (!$this->join) {
                    parent::beginTransaction();
                }
                // join: the transaction that is still open is taken as the one to work in
            }
        };
        $ends = [];
        $db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data['outcome'];
        });

        // told as lost, and still open: its rollback failed
        $this->pdo->failRollBackAlways = true;
        try {
            $db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                throw new \RuntimeException('callback failed');
            });
            $this->fail('Expected the callback exception');
        } catch (\RuntimeException) {
            $this->pdo->failRollBackAlways = false;
        }
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST], $ends);
        $this->assertTrue($this->pdo->reallyInTransaction());

        $db->join = true;
        $this->pdo->failCommit = true;
        try {
            $db->transaction(static fn (): null => null);
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome, 'no end was told with it: no rollback is confirmed');
        }
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST], $ends, 'no second end');
        $this->assertFalse($this->pdo->reallyInTransaction(), 'the rollback went through');
    }

    /**
     * @return SqliteDriver&object{asked: list<string>}
     */
    private function makeFlaggingDriver(): SqliteDriver
    {
        return new class ($this->pdo) extends SqliteDriver {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            protected function failureToRemember(?PDOException $remembered, PDOException $failure): ?PDOException
            {
                return str_contains($failure->getMessage(), 'ignored_table') ? $remembered : $failure;
            }

            protected function transactionEndedBy(PDOException $failure): ?string
            {
                $this->asked[] = $failure->getMessage();

                return str_contains($failure->getMessage(), 'fatal_table') ? 'ended by the server (scenario)' : null;
            }
        };
    }

    /**
     * @return \Closure(string): void Runs a query on a table that does not exist and swallows the failure
     */
    private function failingQuery(SqliteDriver $db): \Closure
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
