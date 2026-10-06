<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;
use PDOException;
use RuntimeException;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Contract\TransactionEnd\TransactionEndTestCase;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

/**
 * completion_type on MariaDB. The driver sets NO_CHAIN when it connects - against the server's
 * default and an INIT_COMMAND, at reconnect() as well. A session switched to CHAIN or RELEASE
 * afterwards: a COMMIT that took effect and is reported as failed (ScenarioPdo::$failAfterCommit)
 * must never end as 'rolled_back' - under CHAIN PDO then reports the next transaction, which the
 * server opened with that COMMIT (measured on 10.11, 11.4 and 12.3: the row is written).
 */
class ChainedCommitTest extends TransactionEndTestCase
{
    protected function tearDown(): void
    {
        PinRefusingPdo::$mode = '';
        try {
            $this->pdo->exec("SET SESSION completion_type = 'NO_CHAIN'");
        } catch (PDOException) {
            // a session the server closed (RELEASE)
        }
        parent::tearDown();
    }

    /**
     * The K1 case: transaction(), the COMMIT takes effect, the answer is lost. Under every
     * completion_type the outcome is 'lost', the row is committed; under CHAIN the ROLLBACK
     * cleans up the chained transaction, confirms nothing, and chains the next one - told to the
     * 'error' hook, the CommitFailedException reaches the caller.
     */
    public function testACommitThatTookEffectAndFailedIsNeverRolledBackUnderAnyCompletionType(): void
    {
        foreach (['NO_CHAIN', 'CHAIN', 'RELEASE'] as $type) {
            $this->db->execute(sprintf("SET SESSION completion_type = '%s'", $type));
            $this->events = [];
            $this->ends = [];
            $this->errors = [];
            try {
                $this->db->transaction(function (DatabaseInterface $db) use ($type): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => $type]);
                    $this->pdo->failAfterCommit = true;
                });
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $e) {
                $this->assertSame(self::LOST, $e->outcome, $type);
                $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends, $type);
            }
            $this->assertSame(['end'], $this->events, $type . ': no rollback listener');
            $this->assertSame([1], array_column($this->rows(), 'id'), $type . ': committed');
            $chained = array_values(array_filter($this->errors, static fn (array $error): bool => $error['error'] === 'Connection is in a new transaction'));
            $this->assertSame($type === 'CHAIN' ? [self::LOST] : [], array_column($chained, 'outcome'), $type);
            if ($type === 'CHAIN') {
                $this->assertTrue($this->pdo->reallyInTransaction(), 'the transaction the cleanup ROLLBACK chained');
                $this->pdo->exec("SET SESSION completion_type = 'NO_CHAIN'");
                $this->pdo->rollBack();
            }
            if ($type === 'RELEASE') {
                $this->assertGone($this->db);
            }
            $this->observer->delete(self::TABLE, ['id' => 1]);
        }
    }

    /**
     * The same under CHAIN with commit() and rollback() the caller issues: the commit's exception
     * keeps its null outcome, the rollback tells 'lost' with it as error and throws for the
     * transaction its ROLLBACK chained.
     */
    public function testAManualCommitThatTookEffectAndFailedIsToldAsLostByTheRollback(): void
    {
        $this->db->execute("SET SESSION completion_type = 'CHAIN'");
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->pdo->failAfterCommit = true;
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $failure) {
            $this->assertNull($failure->outcome);
        }
        $this->assertSame([], $this->ends);
        try {
            $this->db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Connection is in a new transaction', $e->getMessage());
        }
        $this->assertSame([['outcome' => self::LOST, 'error' => $failure]], $this->ends);
        $this->assertSame(['end'], $this->events);
        $this->pdo->exec("SET SESSION completion_type = 'NO_CHAIN'");
        $this->pdo->rollBack();
        $this->assertVisible([1]);
    }

    /**
     * Whether the session chains is asked on raw PDO after the failed COMMIT; only the answer
     * NO_CHAIN keeps the rollback a confirmed one. No answer - the question fails, or returns
     * false in a non-exception error mode - is the same as CHAIN: 'lost'. A COMMIT that failed
     * before it was sent (nothing committed) on a session that does not chain stays 'rolled_back'.
     */
    public function testOnlyTheAnswerNoChainKeepsTheRollbackConfirmed(): void
    {
        foreach (['NO_CHAIN' => self::ROLLED_BACK, 'question fails' => self::LOST, 'question returns false' => self::LOST] as $case => $outcome) {
            $this->events = [];
            $this->ends = [];
            $this->errors = [];
            $asked = $this->pdo->queryCalls;
            try {
                $this->db->transaction(function (DatabaseInterface $db) use ($case): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                    $this->pdo->failCommit = true;
                    $this->pdo->failQuery = $case === 'question fails';
                    $this->pdo->queryReturnsFalse = $case === 'question returns false';
                });
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $e) {
                $this->assertSame($outcome, $e->outcome, $case);
                $this->assertSame([['outcome' => $outcome, 'error' => $e]], $this->ends, $case);
            } finally {
                $this->pdo->failQuery = false;
                $this->pdo->queryReturnsFalse = false;
            }
            $this->assertSame($asked + 1, $this->pdo->queryCalls, $case . ': asked once');
            $this->assertSame($outcome === self::ROLLED_BACK ? ['rollback', 'end'] : ['end'], $this->events, $case);
            $this->assertSame([], $this->errors, $case . ': no hook saw the question');
            $this->assertVisible([]);
        }
    }

    /**
     * A COMMIT that failed before it was sent, under CHAIN: nothing is committed, but the driver
     * cannot tell that from a chained transaction - 'lost', fail-closed. Under RELEASE the same,
     * and the ROLLBACK that cleans up closes the connection.
     */
    public function testAFailedCommitUnderChainOrReleaseIsLostEvenWhenNothingWasCommitted(): void
    {
        foreach (['CHAIN', 'RELEASE'] as $type) {
            $db = $this->connect(['pdoClass' => ScenarioPdo::class]);
            $pdo = $db->getPdo();
            $this->assertInstanceOf(ScenarioPdo::class, $pdo);
            $ends = [];
            $db->on('transaction.end', static function (array $data) use (&$ends): void {
                $ends[] = $data['outcome'];
            });
            $db->execute(sprintf("SET SESSION completion_type = '%s'", $type));
            try {
                $db->transaction(static function (DatabaseInterface $db) use ($pdo): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                    $pdo->failCommit = true;
                });
                $this->fail('Expected CommitFailedException');
            } catch (CommitFailedException $e) {
                $this->assertSame(self::LOST, $e->outcome, $type);
            }
            $this->assertSame([self::LOST], $ends, $type);
            $this->assertSame([], $this->rows(), $type . ': nothing committed');
            if ($type === 'RELEASE') {
                $this->assertGone($db);
            } else {
                $pdo->exec("SET SESSION completion_type = 'NO_CHAIN'");
                $pdo->rollBack();
            }
        }
    }

    /**
     * reconnect() opens a connection on which no transaction is the one whose COMMIT failed: a
     * transaction begun on raw PDO there and rolled back through the driver is a confirmed rollback.
     */
    public function testReconnectLeavesTheUnclearCommitBehind(): void
    {
        $this->db->execute("SET SESSION completion_type = 'CHAIN'");
        $this->pdo->beginTransaction();
        $this->pdo->failCommit = true;
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException) {
            // expected
        }
        $this->pdo->exec("SET SESSION completion_type = 'NO_CHAIN'");
        $this->pdo->rollBack();
        $this->assertInstanceOf(AbstractDriver::class, $this->db);
        $this->db->reconnect();
        $this->db->getPdo()->beginTransaction();
        $this->events = [];
        $this->ends = [];
        $this->db->rollback();
        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => null]], $this->ends);
        $this->assertSame(['rollback', 'end'], $this->events);
    }

    /**
     * The driver sets completion_type to NO_CHAIN when it connects: a server default of CHAIN
     * (every new session inherits it), an INIT_COMMAND, a SET SESSION before reconnect() - each
     * session starts with NO_CHAIN, and transactions work on it as on any other.
     */
    public function testEveryConnectionTheDriverOpensStartsWithNoChain(): void
    {
        $this->observer->execute("SET GLOBAL completion_type = 'CHAIN'");
        try {
            $env = TestEnvironment::mariadb();
            $raw = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', $env['host'], $env['port'], $env['database']), $env['username'], $env['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $inherited = $raw->query('SELECT @@completion_type');
            $this->assertNotFalse($inherited);
            $this->assertSame('CHAIN', $inherited->fetchColumn(), 'what every new session inherits');

            $db = Database::mariadb(TestEnvironment::mariadb());
            $this->assertSame('NO_CHAIN', $db->query('SELECT @@completion_type')->fetchColumn());
            $db->transaction(static fn (DatabaseInterface $db) => $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']));
            $db->transaction(static fn (DatabaseInterface $db) => $db->insert(self::TABLE, ['id' => 2, 'name' => 'b']));
            $this->assertFalse($db->inTransaction());
            $this->assertSame([1, 2], array_column($this->rows(), 'id'));
        } finally {
            $this->observer->execute("SET GLOBAL completion_type = 'NO_CHAIN'");
        }

        $db = $this->connect(['options' => [\Pdo\Mysql::ATTR_INIT_COMMAND => "SET SESSION completion_type = 'CHAIN'"]]);
        $this->assertSame('NO_CHAIN', $db->query('SELECT @@completion_type')->fetchColumn(), 'after the INIT_COMMAND');

        $db->execute("SET SESSION completion_type = 'RELEASE'");
        $db->reconnect();
        $this->assertSame('NO_CHAIN', $db->query('SELECT @@completion_type')->fetchColumn(), 'after reconnect()');
    }

    /**
     * When the statement does not go through, the connection is refused: a ConnectionException
     * with the PDOException as previous, or with what PDO says in a non-exception error mode. What
     * a PDO class of the caller's throws besides a PDOException passes unchanged.
     */
    public function testAConnectionOnWhichNoChainCannotBeSetIsRefused(): void
    {
        $cases = [
            'throws' => ['completion_type refused (scenario)', true],
            'fails silently' => ["Variable 'completion_type' can't be set to the value of '42'", false],
            'returns false' => ['PDO::exec() returned false', false],
        ];
        foreach ($cases as $mode => [$why, $previous]) {
            PinRefusingPdo::$mode = $mode;
            try {
                $this->connect(['pdoClass' => PinRefusingPdo::class, 'options' => [PDO::ATTR_ERRMODE => $mode === 'throws' ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT]]);
                $this->fail('Expected ConnectionException: ' . $mode);
            } catch (ConnectionException $e) {
                $this->assertSame('Database connection failed', $e->getMessage());
                $this->assertNull($e->refusal, $mode);
                $env = TestEnvironment::mariadb();
                $this->assertSame(sprintf("MariaDB connection to %s:%d failed: SET SESSION completion_type = 'NO_CHAIN' did not go through: %s", $env['host'], $env['port'], $why), $e->getDebugMessage(), $mode);
                $this->assertSame($previous, $e->getPrevious() instanceof PDOException, $mode);
            }
        }

        PinRefusingPdo::$mode = 'foreign';
        try {
            $this->connect(['pdoClass' => PinRefusingPdo::class]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('a foreign failure (scenario)', $e->getMessage());
        }

        PinRefusingPdo::$mode = '';
        $db = $this->connect(['pdoClass' => PinRefusingPdo::class]);
        $this->assertSame('NO_CHAIN', $db->query('SELECT @@completion_type')->fetchColumn());
    }

    /**
     * What a PDO class of the caller's throws besides a PDOException, after its COMMIT took effect
     * under CHAIN, passes unchanged - and the end is 'lost', not 'rolled_back': the row is written.
     */
    public function testAForeignExceptionAfterACommitThatTookEffectUnderChainIsLost(): void
    {
        $this->db->execute("SET SESSION completion_type = 'CHAIN'");
        $thrown = new RuntimeException('thrown by the PDO class after its COMMIT (scenario)');
        try {
            $this->db->transaction(function (DatabaseInterface $db) use ($thrown): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->throwAfterCommit = $thrown;
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame($thrown, $e);
        }
        $this->assertSame([['outcome' => self::LOST, 'error' => $thrown]], $this->ends);
        $this->assertSame(['end'], $this->events);
        $this->pdo->exec("SET SESSION completion_type = 'NO_CHAIN'");
        $this->pdo->rollBack(); // the transaction the cleanup ROLLBACK chained
        $this->assertVisible([1]);
    }

    /**
     * A 'transaction.commit' listener switches the session to CHAIN and runs a transaction of its
     * own; its COMMIT takes effect and is reported as failed. The listener catches that, switches
     * back and returns: the raw cleanup rolls back only the chained transaction - the listener's
     * transaction is told 'lost', its row is written. When the listener keeps CHAIN and lets the
     * failure escape, the cleanup cannot end the chained transaction either: 'lost' as well, and
     * the caller's rollback() afterwards tells no second end (its rollback listeners run - the
     * documented limit for every 'lost' told while the transaction may still be open).
     */
    public function testACommitListenersTransactionThatTookEffectIsLostAndToldOnce(): void
    {
        $pdo = $this->pdo;
        $db = $this->db;
        $listener = new class () {
            public bool $escape = false;

            public ?CommitFailedException $failed = null;
        };
        $begins = [];
        $db->on('transaction.begin', static function (array $data) use (&$begins): void {
            $begins[] = $data['depth'];
        });
        $db->on('transaction.commit', static function (array $data) use ($db, $pdo, $listener): void {
            if ($data['depth'] !== 1) {
                return;
            }
            $pdo->exec("SET SESSION completion_type = 'CHAIN'");
            $db->beginTransaction();
            $db->insert(self::TABLE, ['id' => 2, 'name' => 'by the listener']);
            $pdo->failAfterCommit = true;
            try {
                $db->commit();
            } catch (CommitFailedException $e) {
                $listener->failed = $e;
                if ($listener->escape) {
                    throw $e;
                }
                $pdo->exec("SET SESSION completion_type = 'NO_CHAIN'");
            }
        });

        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertFalse($e->connectionInTransaction, 'the chained transaction was rolled back');
        }
        $this->assertSame([self::LOST, self::COMMITTED], array_column($this->ends, 'outcome'));
        $this->assertInstanceOf(CommitFailedException::class, $this->ends[0]['error']);
        $this->assertVisible([1, 2]);

        $this->observer->delete(self::TABLE, ['id' => 1]);
        $this->observer->delete(self::TABLE, ['id' => 2]);
        $listener->escape = true;
        $this->ends = [];
        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertTrue($e->connectionInTransaction, 'the cleanup ROLLBACK chained');
        }
        $this->assertSame([self::LOST, self::COMMITTED], array_column($this->ends, 'outcome'));
        $this->assertInstanceOf(CommitFailedException::class, $this->ends[0]['error'], 'the failed commit names the reason');
        $this->assertSame($listener->failed, $this->ends[0]['error']);
        $this->events = [];
        try {
            $db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Connection is in a new transaction', $e->getMessage());
        }
        $this->assertSame([self::LOST, self::COMMITTED], array_column($this->ends, 'outcome'), 'no second end');
        $this->assertSame(['rollback'], $this->events, 'the documented limit: the rollback listeners run, as after every lost told while the transaction may still be open');
        $pdo->exec("SET SESSION completion_type = 'NO_CHAIN'");
        $pdo->rollBack();
        $this->assertSame([1, 2], array_column($this->rows(), 'id'));

        $begins = [];
        $db->beginTransaction();
        $db->rollback();
        $this->assertSame([1], $begins, 'the depth is sound');
    }

    /**
     * A successful COMMIT under CHAIN is reported as before: committed, the commit listeners
     * skipped, CommitHookException with the connection in the new transaction.
     */
    public function testASuccessfulCommitUnderChainIsReportedAsBefore(): void
    {
        $this->db->execute("SET SESSION completion_type = 'CHAIN'");
        try {
            $this->db->transaction(static fn (DatabaseInterface $db) => $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertTrue($e->connectionInTransaction);
            $this->assertSame('Connection is in a new transaction', $e->getPrevious()?->getMessage());
        }
        $this->assertSame([['outcome' => self::COMMITTED, 'error' => null]], $this->ends);
        $this->pdo->exec("SET SESSION completion_type = 'NO_CHAIN'");
        $this->pdo->rollBack();
        $this->assertVisible([1]);
    }

    private function assertGone(DatabaseInterface $db): void
    {
        try {
            $db->query('SELECT 1');
            $this->fail('Expected the connection to be closed (RELEASE)');
        } catch (QueryException $e) {
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
        }
    }
}
