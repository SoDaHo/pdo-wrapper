<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\TransactionEnd;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;

#[Group('mysql')]
class MySqlTransactionEndScenariosTest extends AbstractTransactionEndScenarios
{
    /** @return array{host: string, port: int, database: string, username: string, password: string} */
    private static function config(): array
    {
        return [
            'host' => (string) ($_ENV['MYSQL_HOST'] ?? '127.0.0.1'),
            'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
            'database' => (string) ($_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test'),
            'username' => (string) ($_ENV['MYSQL_USERNAME'] ?? 'root'),
            'password' => (string) ($_ENV['MYSQL_PASSWORD'] ?? 'root'),
        ];
    }

    protected function makeScenarioPdo(): ScenarioPdo
    {
        $c = self::config();

        return new ScenarioPdo(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']),
            $c['username'],
            $c['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    protected function makeDriver(ScenarioPdo $pdo): DatabaseInterface
    {
        return new class ($pdo) extends MySqlDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
    }

    protected function makeObserver(): ?DatabaseInterface
    {
        return new MySqlDriver(self::config());
    }

    protected function createTableSql(): string
    {
        return 'CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, name VARCHAR(50))';
    }

    protected function aFailedStatementAbortsTheTransaction(): bool
    {
        return false; // InnoDB rolls back only the failed statement (a deadlock is the exception, see MySqlDriverIntegrationTest)
    }

    /**
     * A DDL statement inside the callback commits the transaction implicitly (MySQL/MariaDB). The
     * library's own COMMIT then fails ("no active transaction"), the caller gets a
     * TransactionException, 'transaction.end' reports 'lost' - and the data is committed: that is why
     * 'lost' means "may be committed", fail-closed.
     */
    public function testADdlStatementInsideTheCallbackCommitsImplicitlyAndEndsAsLost(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS end_scenarios_ddl');

        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
                $db->execute('CREATE TABLE end_scenarios_ddl (id INT PRIMARY KEY)'); // implicit COMMIT
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $e]], $this->ends, 'no rollback could be confirmed');
            $this->assertInstanceOf(CommitFailedException::class, $e);
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome);
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS end_scenarios_ddl');
        }

        $this->assertSame(['end'], $this->events, 'no rollback listener');
        $this->assertVisible([1], 'the row is committed although the end says lost');
    }

    /**
     * A 'transaction.begin' listener that sends a DDL statement commits the transaction it was
     * told about implicitly: the begin fails, its end is told as 'lost', and the callback does
     * not run in autocommit.
     */
    public function testADdlStatementInABeginListenerMakesTheBeginFailAsLost(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS end_scenarios_ddl');
        $this->db->on('transaction.begin', static function () use (&$db): void {
            $db->execute('CREATE TABLE end_scenarios_ddl (id INT PRIMARY KEY)'); // implicit COMMIT
        });
        $db = $this->db;
        $this->events = [];
        $this->ends = [];
        $ran = false;

        try {
            $this->db->transaction(static function (DatabaseInterface $db) use (&$ran): void {
                $ran = true;
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'in autocommit']);
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertNotInstanceOf(CommitFailedException::class, $e);
            $this->assertStringStartsWith('A transaction.begin listener ended the transaction that was just begun outside this driver', (string) $e->getDebugMessage());
            $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $e]], $this->ends);
        } finally {
            $this->db->getPdo()->exec('DROP TABLE IF EXISTS end_scenarios_ddl');
        }
        $this->assertFalse($ran);
        $this->assertVisible([]);
    }

    /**
     * A DDL statement commits the open transaction even when it fails itself, and the server's
     * error does not tell the client: PDO keeps reporting the transaction. The rollback that
     * follows asks the server first. The end is told as 'lost', not as 'rolled_back': the rows
     * written before the statement are committed.
     */
    public function testAFailingDdlStatementThatLeavesTheCallbackIsToldAsLost(): void
    {
        $thrown = null;
        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
                $db->execute('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY)'); // exists: fails, and commits
            });
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $thrown = $e;
        }

        $this->assertSame(['end'], $this->events, 'no ROLLBACK was confirmed: no rollback listener');
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $thrown]], $this->ends);
        $this->assertVisible([1], 'the row before the failing DDL statement is committed');
    }

    /**
     * The same on the manual path: the failure is swallowed, rollback() is called. The end is told
     * as 'lost' with the remembered failure, rollback() does not throw, and the next transaction
     * begins normally.
     */
    public function testAManualRollbackAfterAFailingDdlStatementTellsLost(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
        try {
            $this->db->execute('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY)');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $failure = $e->getPrevious();
        }
        $this->db->rollback();

        $this->assertSame(['end'], $this->events);
        $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $this->ends[0]['outcome']);
        $this->assertSame($failure, $this->ends[0]['error'], 'the statement failure that was remembered');
        $this->assertVisible([1]);

        $this->db->transaction(static fn (DatabaseInterface $db) => $db->insert(self::TABLE, ['id' => 2, 'name' => 'next']));
        $this->assertSame(['end', 'commit', 'end'], $this->events, 'no end is owed twice');
        $this->assertVisible([1, 2]);
    }

    /**
     * A failed statement that cost only itself leaves the transaction alive: the server says so,
     * the ROLLBACK is sent and the end is 'rolled_back' as before. The question to the server is
     * no statement of the caller: no 'query' and no further 'error' listener sees it.
     */
    public function testAFailedStatementThatLeftTheTransactionAliveIsStillRolledBack(): void
    {
        $seen = [];
        $this->db->on('query', static function (array $data) use (&$seen): void {
            $seen[] = $data['sql'];
        });

        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $db->insert(self::TABLE, ['id' => 2, 'no_such_column' => 'x']);
            });
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_ROLLED_BACK, 'error' => $e]], $this->ends);
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertCount(1, $seen, 'the first insert; nothing else was reported');
        $this->assertCount(1, $this->errors, 'the failed insert; nothing else was reported');
        $this->assertVisible([]);
    }

    /**
     * After a failed statement commit() asks the server whether the transaction still exists. When
     * the answer cannot be read, nothing is known: the commit is refused, nothing is told, and the
     * transaction stays the caller's to roll back.
     */
    public function testACommitWhoseQuestionToTheServerCannotBeReadIsRefused(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        try {
            $this->db->insert(self::TABLE, ['id' => 2, 'no_such_column' => 'x']);
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // swallowed
        }

        $this->pdo->stateUnreadable = true;
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertStringContainsString('could not be asked whether it still exists', (string) $e->getDebugMessage());
            $this->assertNull($e->outcome);
        } finally {
            $this->pdo->stateUnreadable = false;
        }
        $this->assertSame([], $this->events, 'nothing was sent, nothing is told');

        $this->db->rollback();
        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertVisible([]);
    }

    /**
     * When the question before the ROLLBACK fails while the connection goes on working (a proxy
     * that rejects the statement), nothing is known. The ROLLBACK is sent to clean up - and here
     * it goes through over nothing: the failing DDL statement committed. No rollback is confirmed:
     * the end is 'lost', never 'rolled_back'.
     */
    public function testAFailingDdlStatementWhoseQuestionToTheServerFailsIsStillToldAsLost(): void
    {
        $thrown = null;
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
                $this->pdo->failExec = true;
                $db->execute('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY)');
            });
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $thrown = $e;
        } finally {
            $this->pdo->failExec = false;
        }

        $this->assertSame(['end'], $this->events, 'no rollback listener: the ROLLBACK that went through confirms nothing');
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $thrown]], $this->ends);
        $this->assertVisible([1], 'the row before the failing DDL statement is committed');
    }

    /**
     * The same question failing after a statement that cost only itself: the ROLLBACK does undo
     * the transaction - and still nobody can say so. 'lost' is the cautious answer; the next
     * transaction begins normally.
     */
    public function testARollbackWhoseQuestionToTheServerFailsConfirmsNothing(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        try {
            $this->db->insert(self::TABLE, ['id' => 2, 'no_such_column' => 'x']);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $failure = $e->getPrevious();
        }

        $this->pdo->failExec = true;
        try {
            $this->db->rollback();
        } finally {
            $this->pdo->failExec = false;
        }

        $this->assertSame(['end'], $this->events);
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $failure]], $this->ends);
        $this->assertVisible([], 'the ROLLBACK was sent');

        // Ended for certain: no mark of a transaction that "may still be open" stays behind, and the
        // failure is forgotten. A transaction begun on raw PDO is committed through the library without
        // a question to the server - one would fail here and refuse the commit - and tells its end.
        $this->pdo->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 3, 'name' => 'next']);
        $this->pdo->failExec = true;
        try {
            $this->db->commit();
        } finally {
            $this->pdo->failExec = false;
        }
        $this->assertSame(['end', 'commit', 'end'], $this->events);
        $this->assertVisible([3]);
    }

    /**
     * A session that chains transactions gets the next one from the ROLLBACK that was sent to
     * clean up. It is reported as after every other ROLLBACK: thrown on a manual rollback(), told
     * to the 'error' hook when the callback's exception reaches the caller.
     */
    public function testAChainedTransactionAfterARollbackThatConfirmsNothingIsReported(): void
    {
        $this->db->execute('SET SESSION completion_type = CHAIN');
        try {
            $this->db->beginTransaction();
            $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
            try {
                $this->db->insert(self::TABLE, ['id' => 2, 'no_such_column' => 'x']);
                $this->fail('Expected QueryException');
            } catch (QueryException) {
                // swallowed
            }
            $this->pdo->failExec = true;
            try {
                $this->db->rollback();
                $this->fail('Expected TransactionException: the connection is in a chained transaction');
            } catch (TransactionException $e) {
                $this->assertSame('Connection is in a new transaction', $e->getMessage());
                $this->assertNull($e->getPrevious(), 'thrown as it is');
            }
            $this->assertSame(['end'], $this->events);
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $this->ends[0]['outcome']);
            $this->assertSame([], $this->chainedReports(), 'thrown, not told to the error hook as well');
            $this->assertTrue($this->pdo->reallyInTransaction(), 'the chained one');
            $this->pdo->failExec = false;
            $this->db->execute('SET SESSION completion_type = NO_CHAIN');
            $this->pdo->rollBack();

            $this->db->execute('SET SESSION completion_type = CHAIN');
            $this->events = [];
            $this->errors = [];
            $thrown = null;
            try {
                $this->db->transaction(function (DatabaseInterface $db): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                    $this->pdo->failExec = true;
                    $db->insert(self::TABLE, ['id' => 2, 'no_such_column' => 'x']);
                });
                $this->fail('Expected QueryException');
            } catch (QueryException $e) {
                $thrown = $e;
            }
            $this->assertSame(['end'], $this->events);
            $this->assertSame([DatabaseInterface::TRANSACTION_LOST], $this->chainedReports(), 'told once, with the outcome that was told');
            $this->assertSame($thrown, $this->ends[array_key_last($this->ends)]['error']);
            $this->pdo->failExec = false;
            $this->db->execute('SET SESSION completion_type = NO_CHAIN');
            $this->pdo->rollBack();

            // the commit transaction() runs itself is refused (the question fails), the cleanup ROLLBACK chains
            $this->db->execute('SET SESSION completion_type = CHAIN');
            $this->events = [];
            $this->ends = [];
            $this->errors = [];
            try {
                $this->db->transaction(function (DatabaseInterface $db): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                    try {
                        $db->insert(self::TABLE, ['id' => 2, 'no_such_column' => 'x']);
                    } catch (QueryException) {
                        // swallowed
                    }
                    $this->pdo->failExec = true;
                });
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $refusal) {
                $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $refusal->outcome);
                $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $refusal]], $this->ends);
            }
            $this->assertSame(['end'], $this->events);
            $this->assertSame([DatabaseInterface::TRANSACTION_LOST], $this->chainedReports());
        } finally {
            $this->pdo->failExec = false;
            $this->db->execute('SET SESSION completion_type = NO_CHAIN');
            if ($this->pdo->reallyInTransaction()) {
                $this->pdo->rollBack();
            }
        }
        $this->assertVisible([]);
    }

    /**
     * CREATE TEMPORARY TABLE is the DDL statement that commits nothing: the transaction lives on,
     * the question says so, and the rollback is a rollback.
     */
    public function testADdlStatementWithoutAnImplicitCommitIsStillRolledBack(): void
    {
        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $db->execute('CREATE TEMPORARY TABLE end_scenarios_tmp (id INT)');
                $db->execute('CREATE TEMPORARY TABLE end_scenarios_tmp (id INT)'); // exists: fails, commits nothing
            });
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_ROLLED_BACK, 'error' => $e]], $this->ends);
        } finally {
            $this->db->getPdo()->exec('DROP TEMPORARY TABLE IF EXISTS end_scenarios_tmp');
        }

        $this->assertSame(['rollback', 'end'], $this->events);
        $this->assertVisible([]);
    }

    /**
     * A transaction begun on raw PDO whose rollback() through the library would tell an end: the
     * failing DDL statement committed it, and that end is 'lost' as well.
     */
    public function testARawBegunTransactionCommittedByAFailingDdlStatementIsToldAsLost(): void
    {
        $this->pdo->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
        try {
            $this->db->execute('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY)');
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // swallowed
        }
        $this->db->rollback();

        $this->assertSame(['end'], $this->events);
        $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $this->ends[0]['outcome']);
        $this->assertVisible([1]);
    }

    /**
     * The DDL statement ended the callback's transaction behind the library's back. A further
     * transaction begun inside the callback (updateMultiple() opens its own when PDO reports none)
     * must not take the owed end's place: the first one is told as 'lost' before the next begins,
     * and that one gets its own 'committed'.
     */
    public function testATransactionBegunAfterAnImplicitCommitDoesNotSwallowTheOwedEnd(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before']);
        $this->db->execute('DROP TABLE IF EXISTS end_scenarios_ddl');
        $this->events = [];
        $this->ends = [];

        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'before the DDL']);
                $db->execute('CREATE TABLE end_scenarios_ddl (id INT PRIMARY KEY)'); // implicit COMMIT
                $db->updateMultiple(self::TABLE, [['id' => 1, 'name' => 'in its own transaction']]);
            });
            $this->fail('Expected CommitFailedException: the outer commit finds no transaction');
        } catch (CommitFailedException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome, 'nothing was left to end: lost, and the rows are in fact committed');
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS end_scenarios_ddl');
        }

        $this->assertSame(['end', 'commit', 'end'], $this->events);
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST, DatabaseInterface::TRANSACTION_COMMITTED], array_column($this->ends, 'outcome'));
        $this->assertInstanceOf(TransactionException::class, $this->ends[0]['error']);
        $this->assertSame('Transaction ended outside this library', $this->ends[0]['error']->getMessage());
        $this->assertNull($this->ends[1]['error']);
        $this->assertVisible([1, 2]);
        $this->assertSame('in its own transaction', $this->db->findOne(self::TABLE, ['id' => 1])['name'] ?? null);
    }

    /**
     * A lock wait timeout (error 1205) undoes only its statement: a callback that swallows it
     * goes on and commits the rest - nothing is held back as after a deadlock. commit() asks the
     * server first (one no-op statement), and the transaction is still there.
     */
    public function testASwallowedLockWaitTimeoutStillCommitsTheRest(): void
    {
        $this->assertSame(1205, $this->runIntoALockWaitTimeout(endedByTheServer: false));

        $this->assertSame(['commit', 'end'], $this->events);
        $this->assertSame(DatabaseInterface::TRANSACTION_COMMITTED, $this->ends[0]['outcome']);
        $this->assertVisible([1, 2, 3], 'the rows written before and after the timeout are committed');
    }

    /**
     * With innodb_rollback_on_timeout the same error ends the whole transaction, like a deadlock:
     * PDO reports no transaction once it has asked the server (simulated here: the option cannot be
     * set at runtime). The commit is refused; nothing can be rolled back any more, so the end is 'lost'.
     */
    public function testALockWaitTimeoutThatEndedTheTransactionRefusesTheCommit(): void
    {
        try {
            $this->runIntoALockWaitTimeout(endedByTheServer: true);
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertStringContainsString('The server reports no transaction any more', (string) $e->getDebugMessage());
            $this->assertSame(1205, $e->getPrevious()?->errorInfo[1] ?? null);
            $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $e]], $this->ends);
            $this->assertInstanceOf(CommitFailedException::class, $e);
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome);
        }

        $this->assertSame(['end'], $this->events, 'no commit, no rollback listener');
    }

    /**
     * The same timeout leaving the callback right away: the rollback asks the server before it is
     * sent, as the commit does. With innodb_rollback_on_timeout the transaction is gone (simulated
     * as above: the question finds none), and the end is 'lost' - no rollback listener, because no
     * ROLLBACK of this library is confirmed. Without that option the transaction lives on and is
     * rolled back as before (testLockWaitTimeoutLeavesTheTransactionOpenAndTheRollbackListenersRun).
     */
    public function testALockWaitTimeoutThatEndedTheTransactionAndLeavesTheCallbackIsToldAsLost(): void
    {
        $this->assertNotNull($this->observer);
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'locked by the observer']);
        $this->db->execute('SET SESSION innodb_lock_wait_timeout = 1');
        $this->events = [];
        $thrown = null;

        $this->observer->beginTransaction();
        try {
            $this->observer->update(self::TABLE, ['name' => 'held'], ['id' => 1]);
            $this->pdo->vanishOnExec = true;
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'before the timeout']);
                $db->update(self::TABLE, ['name' => 'waits'], ['id' => 1]);
            });
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $thrown = $e;
            $this->assertTrue($this->pdo->reallyInTransaction(), 'no ROLLBACK was sent: what the simulation hides is still there');
        } finally {
            $this->pdo->hideTransaction = false;
            $this->pdo->vanishOnExec = false;
            if ($this->pdo->reallyInTransaction()) {
                $this->pdo->rollBack();
            }
            $this->observer->rollback();
        }

        $this->assertSame(1205, $thrown->getPrevious()?->errorInfo[1] ?? null);
        $this->assertSame(['end'], $this->events, 'no rollback listener');
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $thrown]], $this->ends);
    }

    /**
     * The outcomes the 'error' hook was told a chained transaction with.
     *
     * @return list<mixed>
     */
    private function chainedReports(): array
    {
        return array_values(array_map(
            static fn (array $error): mixed => $error['outcome'] ?? null,
            array_filter($this->errors, static fn (array $error): bool => $error['error'] === 'Connection is in a new transaction')
        ));
    }

    /**
     * The observer holds a row lock; the scenario connection writes a row, runs into the lock,
     * swallows the timeout and returns from the callback.
     *
     * @return int|null The swallowed error's code
     */
    private function runIntoALockWaitTimeout(bool $endedByTheServer): ?int
    {
        $this->assertNotNull($this->observer);
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'locked by the observer']);
        $this->db->execute('SET SESSION innodb_lock_wait_timeout = 1');
        $this->events = [];
        $code = null;

        $this->observer->beginTransaction();
        try {
            $this->observer->update(self::TABLE, ['name' => 'held'], ['id' => 1]);

            $this->db->transaction(function (DatabaseInterface $db) use (&$code, $endedByTheServer): void {
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'before the timeout']);
                try {
                    $db->update(self::TABLE, ['name' => 'waits'], ['id' => 1]);
                } catch (QueryException $e) {
                    $code = $e->getPrevious()?->errorInfo[1] ?? null; // swallowed: the callback goes on
                }
                if (!$endedByTheServer) {
                    // the transaction lives on: unlike after a deadlock, further statements are sent
                    $db->insert(self::TABLE, ['id' => 3, 'name' => 'after the timeout']);
                }
                $this->pdo->hideTransaction = $endedByTheServer;
            });
        } finally {
            $this->pdo->hideTransaction = false;
            $this->observer->rollback();
        }

        return $code;
    }
}
