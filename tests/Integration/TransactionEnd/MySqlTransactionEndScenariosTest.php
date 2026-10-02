<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\TransactionEnd;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
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
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS end_scenarios_ddl');
        }

        $this->assertSame(['end'], $this->events, 'no rollback listener');
        $this->assertVisible([1], 'the row is committed although the end says lost');
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
            $this->fail('Expected TransactionException: the outer commit finds no transaction');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
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
        }

        $this->assertSame(['end'], $this->events, 'no commit, no rollback listener');
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
