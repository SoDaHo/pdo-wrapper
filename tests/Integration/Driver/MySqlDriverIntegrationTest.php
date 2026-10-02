<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use Closure;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;

#[Group('mysql')]
class MySqlDriverIntegrationTest extends TestCase
{
    private MySqlDriver $driver;

    private static function getConfig(): array
    {
        return [
            'host' => $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
            'database' => $_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['MYSQL_USERNAME'] ?? 'root',
            'password' => $_ENV['MYSQL_PASSWORD'] ?? 'root',
        ];
    }

    protected function setUp(): void
    {
        $this->driver = new MySqlDriver(self::getConfig());
    }

    public function testImplementsDatabaseInterface(): void
    {
        $this->assertInstanceOf(DatabaseInterface::class, $this->driver);
    }

    public function testConnectsToMySql(): void
    {
        $this->assertInstanceOf(PDO::class, $this->driver->getPdo());
    }

    public function testQueryReturnsStatement(): void
    {
        $stmt = $this->driver->query('SELECT 1 as test');

        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertSame(1, $stmt->fetch()['test']);
    }

    public function testExecuteReturnsAffectedRows(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_mysql (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))');

        $affected = $this->driver->execute('INSERT INTO test_mysql (name) VALUES (?)', ['hello']);

        $this->assertSame(1, $affected);
    }

    public function testLastInsertId(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_mysql2 (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))');
        $this->driver->execute('INSERT INTO test_mysql2 (name) VALUES (?)', ['hello']);

        $id = $this->driver->lastInsertId();

        $this->assertSame('1', $id);
    }

    public function testNowAndUtcNowAreUsableAsValues(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_now (id INT AUTO_INCREMENT PRIMARY KEY, local_at DATETIME, utc_at DATETIME)');

        $id = $this->driver->insert('test_now', ['local_at' => $this->driver->now(), 'utc_at' => $this->driver->utcNow()]);
        $row = $this->driver->findOne('test_now', ['id' => $id]);

        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['local_at']);
        $this->assertEqualsWithDelta(time(), (int) strtotime((string) $row['utc_at'] . ' UTC'), 5);
        $this->assertSame(1, $this->driver->table('test_now')->where('utc_at', '<=', $this->driver->utcNow())->count());
    }

    /**
     * now() follows the session's time zone, utcNow() does not: with the session two hours ahead
     * of UTC they must differ by exactly that offset (a now() that secretly returns UTC would fail here).
     */
    public function testNowFollowsTheSessionTimeZoneAndUtcNowDoesNot(): void
    {
        $this->driver->execute("SET time_zone = '+02:00'");
        $this->driver->execute('CREATE TEMPORARY TABLE test_tz (id INT AUTO_INCREMENT PRIMARY KEY, local_at DATETIME, utc_at DATETIME)');

        $id = $this->driver->insert('test_tz', ['local_at' => $this->driver->now(), 'utc_at' => $this->driver->utcNow()]);
        $row = $this->driver->findOne('test_tz', ['id' => $id]);

        $this->assertNotNull($row);
        $local = (int) strtotime((string) $row['local_at'] . ' UTC');
        $utc = (int) strtotime((string) $row['utc_at'] . ' UTC');
        $this->assertEqualsWithDelta(7200, $local - $utc, 2);
        $this->assertEqualsWithDelta(time(), $utc, 5);
    }

    public function testConnectionUsesUtf8mb4(): void
    {
        $stmt = $this->driver->query("SHOW VARIABLES LIKE 'character_set_client'");
        $result = $stmt->fetch();

        $this->assertSame('utf8mb4', $result['Value']);
    }

    public function testConnectionUsesExceptionErrorMode(): void
    {
        $errorMode = $this->driver->getPdo()->getAttribute(PDO::ATTR_ERRMODE);

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $errorMode);
    }

    public function testConnectionUsesFetchAssoc(): void
    {
        $fetchMode = $this->driver->getPdo()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);

        $this->assertSame(PDO::FETCH_ASSOC, $fetchMode);
    }

    public function testConnectionDisablesEmulatedPrepares(): void
    {
        $emulate = $this->driver->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES);

        // PDO returns int(0) on PHP 8.2-8.4, bool(false) on PHP 8.5+
        $this->assertEmpty($emulate);
    }

    /**
     * With completion_type=CHAIN, ROLLBACK opens the next transaction at once. The cleanup after a
     * commit listener that left a transaction open must notice that and report the connection as
     * (still) in a transaction instead of claiming a clean state.
     */
    public function testRollbackChainingAfterACommitListenerIsReportedAsInTransaction(): void
    {
        $db = $this->driver;
        $secondRan = false;
        $db->on('transaction.commit', static function () use ($db): void {
            $db->execute('SET SESSION completion_type = CHAIN');
            $db->beginTransaction();
        });
        $db->on('transaction.commit', static function () use (&$secondRan): void {
            $secondRan = true;
        });
        $ends = [];
        $db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data['outcome'];
        });

        $db->beginTransaction();
        try {
            $db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertTrue($e->connectionInTransaction);
            $this->assertCount(2, $e->failures);
            $this->assertSame('listener left a transaction open', $e->failures[0]->getMessage());
            $cleanupError = $e->failures[0]->getPrevious();
            $this->assertInstanceOf(TransactionException::class, $cleanupError);
            $this->assertSame('connection still in a transaction after PDO::rollBack()', $cleanupError->getDebugMessage());
            $this->assertSame('listener skipped: connection left in transaction', $e->failures[1]->getMessage());
            $this->assertSame($cleanupError, $e->failures[1]->getPrevious());
            $this->assertTrue($db->inTransaction(), 'the chained transaction is open');
            $this->assertSame(['lost', 'committed'], $ends, "the listener's transaction: rolled back, but the connection is still in one (chained) - lost; then the outer committed");
        } finally {
            $db->execute('SET SESSION completion_type = NO_CHAIN');
            if ($db->inTransaction()) {
                $db->rollback();
            }
        }

        $this->assertFalse($secondRan);
        $this->assertFalse($db->inTransaction());
        $this->assertSame(['lost', 'committed'], $ends, 'the rollback of the chained transaction tells no second end');
    }

    /**
     * With completion_type=CHAIN the server opens the next transaction with the COMMIT itself.
     * commit() reports that instead of leaving the caller in a transaction nobody will commit:
     * committed, commit listeners skipped, CommitHookException with connectionInTransaction.
     */
    public function testACommitThatChainsANewTransactionIsReported(): void
    {
        $db = $this->driver;
        $db->execute('DROP TABLE IF EXISTS chain_rows');
        $db->execute('CREATE TABLE chain_rows (id INT PRIMARY KEY) ENGINE=InnoDB');
        $listenerRan = false;
        $db->on('transaction.commit', static function () use (&$listenerRan): void {
            $listenerRan = true;
        });
        $ends = [];
        $db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data['outcome'];
        });

        try {
            $db->execute('SET SESSION completion_type = CHAIN');
            try {
                $db->transaction(static fn (DatabaseInterface $db): int|string => $db->insert('chain_rows', ['id' => 1]));
                $this->fail('Expected CommitHookException');
            } catch (CommitHookException $e) {
                $this->assertTrue($e->connectionInTransaction);
                $this->assertCount(2, $e->failures);
                $this->assertInstanceOf(TransactionException::class, $e->failures[0]);
                $this->assertSame('Connection is in a new transaction', $e->failures[0]->getMessage());
                $this->assertStringContainsString('right after COMMIT', (string) $e->failures[0]->getDebugMessage());
                $this->assertStringContainsString('completion_type=CHAIN', (string) $e->failures[0]->getDebugMessage());
                $this->assertSame('listener skipped: connection left in transaction', $e->failures[1]->getMessage());
                $this->assertSame($e->failures[0], $e->failures[1]->getPrevious());
            }

            $this->assertFalse($listenerRan);
            $this->assertSame(['committed'], $ends, 'the transaction itself is committed');
            $this->assertTrue($db->inTransaction(), 'the chained transaction is open');
            $observer = new MySqlDriver(self::getConfig());
            $this->assertSame(1, $observer->table('chain_rows')->count(), 'committed: another connection sees the row');
        } finally {
            $db->execute('SET SESSION completion_type = NO_CHAIN');
            if ($db->inTransaction()) {
                $db->rollback();
            }
            $db->execute('DROP TABLE IF EXISTS chain_rows');
        }
    }

    /**
     * The same after a ROLLBACK: rolled back, listeners and transaction.end run, then the caller
     * learns about the chained transaction. On the automatic rollback of transaction() the
     * callback's exception still reaches the caller unchanged.
     */
    public function testARollbackThatChainsANewTransactionIsReported(): void
    {
        $db = $this->driver;
        $events = [];
        $db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $db->on('transaction.end', static function (array $data) use (&$events): void {
            $events[] = $data['outcome'];
        });
        $reported = [];
        $db->on('error', static function (array $data) use (&$reported): void {
            $reported[] = $data;
        });

        try {
            $db->execute('SET SESSION completion_type = CHAIN');

            $db->beginTransaction();
            try {
                $db->rollback();
                $this->fail('Expected TransactionException');
            } catch (TransactionException $e) {
                $this->assertSame('Connection is in a new transaction', $e->getMessage());
                $this->assertStringContainsString('right after ROLLBACK', (string) $e->getDebugMessage());
            }
            $this->assertSame(['rollback', 'rolled_back'], $events);
            $this->assertSame([], $reported, 'thrown to the caller: the error hook is not told as well');
            $this->assertTrue($db->inTransaction(), 'the chained transaction is open');

            $db->getPdo()->exec('SET SESSION completion_type = NO_CHAIN');
            $db->getPdo()->rollBack();
            $db->execute('SET SESSION completion_type = CHAIN');
            $events = [];

            $cause = new \RuntimeException('callback failed');
            try {
                $db->transaction(static function () use ($cause): void {
                    throw $cause;
                });
                $this->fail('Expected the callback exception');
            } catch (\RuntimeException $e) {
                $this->assertSame($cause, $e);
            }
            $this->assertSame(['rollback', 'rolled_back'], $events);
            $this->assertCount(1, $reported, 'the error hook is where the chained transaction is told');
            $this->assertSame(['sql', 'params', 'error', 'code', 'outcome', 'exception'], array_keys($reported[0]));
            $this->assertSame('Connection is in a new transaction', $reported[0]['error']);
            $this->assertSame('rolled_back', $reported[0]['outcome']);
            $this->assertInstanceOf(TransactionException::class, $reported[0]['exception']);
        } finally {
            $db->getPdo()->exec('SET SESSION completion_type = NO_CHAIN');
            if ($db->getPdo()->inTransaction()) {
                $db->getPdo()->rollBack();
            }
        }
    }

    /**
     * A rollback listener's own exception keeps its precedence over the chained transaction.
     */
    public function testARollbackListenerExceptionWinsOverTheChainedTransaction(): void
    {
        $db = $this->driver;
        $listenerFailure = new \RuntimeException('listener failed');
        $db->on('transaction.rollback', static function () use ($listenerFailure): void {
            throw $listenerFailure;
        });
        $reported = [];
        $db->on('error', static function (array $data) use (&$reported): void {
            $reported[] = $data['error'];
        });

        try {
            $db->execute('SET SESSION completion_type = CHAIN');
            $db->beginTransaction();
            try {
                $db->rollback();
                $this->fail('Expected the listener exception');
            } catch (\RuntimeException $e) {
                $this->assertSame($listenerFailure, $e);
            }
            $this->assertSame(['Connection is in a new transaction'], $reported, 'not thrown, so the error hook is told');
        } finally {
            $db->getPdo()->exec('SET SESSION completion_type = NO_CHAIN');
            if ($db->getPdo()->inTransaction()) {
                $db->getPdo()->rollBack();
            }
        }
    }

    /**
     * Multi-statements are off by default: a second statement smuggled into one string is a syntax
     * error, on raw PDO and with emulated prepares too. The option brings them back.
     */
    public function testMultiStatementsAreOffByDefault(): void
    {
        $two = 'SELECT 1; SELECT 2';

        try {
            $this->driver->getPdo()->exec($two);
            $this->fail('Expected a syntax error on raw PDO');
        } catch (\PDOException $e) {
            $this->assertSame(1064, $e->errorInfo[1] ?? null);
        }

        $emulated = new MySqlDriver(self::getConfig() + ['options' => [PDO::ATTR_EMULATE_PREPARES => true]]);
        try {
            $emulated->query($two);
            $this->fail('Expected a syntax error with emulated prepares');
        } catch (QueryException $e) {
            $this->assertSame(1064, $e->getPrevious()?->errorInfo[1] ?? null);
        }

        $optedIn = new MySqlDriver(self::getConfig() + ['options' => [\Pdo\Mysql::ATTR_MULTI_STATEMENTS => true]]);
        $this->assertSame(0, $optedIn->getPdo()->exec($two));
    }

    /**
     * A deadlock rolls the whole transaction back on the server, but PDO keeps reporting it. A
     * callback that swallows the error and returns would get a COMMIT that succeeds and commits
     * nothing. commit() refuses; PDO still reports the transaction, so the rollback goes through
     * and 'transaction.end' reports 'rolled_back'.
     */
    public function testASwallowedDeadlockMakesTheCommitFail(): void
    {
        [$e, $listenerRuns, $measured] = $this->runSwallowedDeadlock();

        $this->assertSame(1213, $measured['swallowed']?->errorInfo[1] ?? null, 'ER_LOCK_DEADLOCK: this connection was the victim');
        $this->assertSame('Failed to commit transaction', $e->getMessage());
        $this->assertSame($measured['swallowed'], $e->getPrevious());
        $this->assertStringContainsString('deadlock (error 1213', (string) $e->getDebugMessage());
        $this->assertSame([['outcome' => 'rolled_back', 'error' => $e]], $measured['ends']);
        $this->assertInstanceOf(CommitFailedException::class, $e);
        $this->assertSame('rolled_back', $e->outcome, 'refused before it was sent and the rollback is confirmed: nothing is committed');
        $this->assertSame(1, $listenerRuns);
        $this->assertFalse($measured['inTransactionAfterwards']);
        $this->assertSame('Max', $measured['user1Name'], 'the update before the deadlock is gone, and nobody was told it was committed');
        $this->assertSame('Anna', $measured['user2Name']);
    }

    /**
     * After the deadlock the library sends nothing more on that connection until the rollback: a
     * statement the callback runs next would run outside the transaction and be committed on its
     * own. It throws instead, naming the deadlock, and no hook fires for it.
     */
    public function testAfterASwallowedDeadlockNoFurtherStatementIsSent(): void
    {
        $blocked = [];
        $hooks = [];
        [$e, $listenerRuns, $measured] = $this->runSwallowedDeadlock(
            afterwards: function (MySqlDriver $db) use (&$blocked, &$hooks): void {
                $db->on('query', static function (array $data) use (&$hooks): void {
                    $hooks[] = $data['sql'];
                });
                $db->on('error', static function (array $data) use (&$hooks): void {
                    $hooks[] = $data['sql'];
                });
                foreach (['UPDATE lock_users SET name = ? WHERE id = 2', 'SELECT name FROM lock_users WHERE id = ?'] as $sql) {
                    try {
                        $db->query($sql, $sql[0] === 'U' ? ['after the deadlock'] : [2]);
                        $this->fail('Expected QueryException: nothing is sent after the deadlock');
                    } catch (QueryException $e) {
                        $blocked[] = $e;
                    }
                }
                $this->assertTrue($db->inTransaction(), 'PDO still reports the transaction the server threw away');
            }
        );

        $this->assertCount(2, $blocked);
        foreach ($blocked as $refused) {
            $this->assertSame('Query failed', $refused->getMessage());
            $this->assertSame($measured['swallowed'], $refused->getPrevious(), 'the deadlock, for retry logic that looks at the cause');
            $this->assertStringContainsString('Not sent: the server rolled the open transaction back', (string) $refused->getDebugMessage());
        }
        $this->assertSame([], $hooks, 'not sent: neither query nor error hook');
        $this->assertSame('Anna', $measured['user2Name'], 'nothing was written outside the transaction');
        $this->assertSame([['outcome' => 'rolled_back', 'error' => $e]], $measured['ends']);
        $this->assertSame(1, $listenerRuns);
        $this->assertStringContainsString('deadlock (error 1213', (string) $e->getDebugMessage());
    }

    /**
     * Raw PDO is not held back: what the callback runs there after the swallowed deadlock is
     * committed on its own, and PDO then knows that the transaction is gone. The commit is refused
     * all the same, and the end says 'lost' (may be committed), not 'rolled_back'.
     */
    public function testRawStatementsAfterASwallowedDeadlockAreCommittedOnTheirOwn(): void
    {
        [$e, $listenerRuns, $measured] = $this->runSwallowedDeadlock(
            afterwards: static fn (MySqlDriver $db): int|false => $db->getPdo()->exec("UPDATE lock_users SET name = 'after the deadlock' WHERE id = 2")
        );

        $this->assertSame(1213, $e->getPrevious()?->errorInfo[1] ?? null);
        $this->assertSame([['outcome' => 'lost', 'error' => $e]], $measured['ends']);
        $this->assertSame(0, $listenerRuns);
        $this->assertSame('Max', $measured['user1Name'], 'before the deadlock: rolled back by the server');
        $this->assertSame('after the deadlock', $measured['user2Name'], 'after the deadlock, on raw PDO: committed on its own');
    }

    /**
     * A statement on raw PDO tells PDO that the transaction is gone. The library still accepts
     * nothing but the end of the transaction it began: no statement, no new transaction (which
     * updateMultiple() would open). The refused commit then tells the end as 'lost'.
     */
    public function testRawPdoRevealingTheEndDoesNotLiftTheBlock(): void
    {
        [$e, , $measured] = $this->runSwallowedDeadlock(
            afterwards: function (MySqlDriver $db): void {
                $db->getPdo()->exec('DO 1');
                $this->assertFalse($db->inTransaction());
                try {
                    $db->execute('UPDATE lock_users SET name = ? WHERE id = 2', ['in autocommit']);
                    $this->fail('Expected QueryException: still nothing is sent');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('Not sent', (string) $e->getDebugMessage());
                }
                try {
                    $db->updateMultiple('lock_users', [['id' => 2, 'name' => 'in a replacement transaction']]);
                    $this->fail('Expected TransactionException: no new transaction over the dead one');
                } catch (TransactionException $e) {
                    $this->assertSame('Failed to begin transaction', $e->getMessage());
                    $this->assertSame(1213, $e->getPrevious()?->errorInfo[1] ?? null);
                }
            }
        );

        $this->assertSame('Failed to commit transaction', $e->getMessage());
        $this->assertStringContainsString('deadlock (error 1213', (string) $e->getDebugMessage());
        $this->assertSame([['outcome' => 'lost', 'error' => $e]], $measured['ends']);
        $this->assertInstanceOf(CommitFailedException::class, $e);
        $this->assertSame('lost', $e->outcome);
        $this->assertSame('Anna', $measured['user2Name'], 'nothing was written after the deadlock');
    }

    /**
     * rollback() is the way out also when PDO no longer reports the dead transaction: there is
     * nothing to send, the end is told as 'lost' with the deadlock, and the next transaction begins.
     */
    public function testRollbackEndsADeadTransactionThatPdoNoLongerReports(): void
    {
        [$e, $listenerRuns, $measured] = $this->runSwallowedDeadlock(
            afterwards: static function (MySqlDriver $db): void {
                $db->getPdo()->exec('DO 1');
                $db->rollback();
            },
            manual: true
        );

        $this->assertStringContainsString('no active transaction', strtolower((string) $e->getDebugMessage()), 'the commit after the rollback: nothing is open');
        $this->assertSame(0, $listenerRuns, 'no ROLLBACK was sent, no rollback listener ran');
        $this->assertSame(['lost', 'committed'], array_column($measured['ends'], 'outcome'));
        $this->assertSame($measured['swallowed'], $measured['ends'][0]['error']);
    }

    /**
     * After a deadlock the transaction is to be ended with rollback(). Ended and begun again on
     * raw PDO, the old deadlock still holds everything back - statements and commit - until
     * rollback() is called: the library cannot tell the new raw transaction from the dead one.
     */
    public function testAfterADeadlockOnlyRollbackLiftsTheBlock(): void
    {
        [$e, $listenerRuns, $measured] = $this->runSwallowedDeadlock(
            afterwards: function (MySqlDriver $db): void {
                $db->getPdo()->rollBack();
                $db->getPdo()->beginTransaction();
                try {
                    $db->execute('UPDATE lock_users SET name = ? WHERE id = 2', ['in the raw transaction']);
                    $this->fail('Expected QueryException: still refused for the old deadlock');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('Call rollback()', (string) $e->getDebugMessage());
                }
                $db->rollback();
                $this->assertSame(1, $db->execute('UPDATE lock_users SET name = ? WHERE id = 2', ['after rollback()']));
            }
        );

        $this->assertStringContainsString('no active transaction', strtolower((string) $e->getDebugMessage()), 'the callback ended the transaction itself');
        $this->assertSame([['outcome' => 'rolled_back', 'error' => null]], $measured['ends'], "the callback's own rollback() told the end");
        $this->assertSame(1, $listenerRuns);
        $this->assertSame('after rollback()', $measured['user2Name']);
    }

    /**
     * A refused commit that told 'lost' has ended the transaction: a transaction an end listener
     * begins in response is the listener's own and is neither rolled back nor told about here.
     */
    public function testATransactionAnEndListenerBeginsAfterARefusalIsLeftAlone(): void
    {
        [$e, $listenerRuns, $measured] = $this->runSwallowedDeadlock(
            afterwards: static function (MySqlDriver $db): void {
                $db->getPdo()->exec("UPDATE lock_users SET name = 'after the deadlock' WHERE id = 2");
                $db->on('transaction.end', static function (array $data) use ($db): void {
                    if ($data['outcome'] === 'lost') {
                        $db->beginTransaction();
                    }
                });
            }
        );

        $this->assertSame([['outcome' => 'lost', 'error' => $e]], $measured['ends']);
        $this->assertSame(0, $listenerRuns, 'no rollback was sent for the listener\'s transaction');
        $this->assertTrue($measured['inTransactionAfterwards'], "the listener's transaction is still open");
    }

    /**
     * The same when the end listener first runs a whole transaction of its own (its commit must
     * not make the library forget that the refusal already told the end) and then leaves another open.
     */
    public function testAnEndListenerMayCommitATransactionOfItsOwnAfterARefusal(): void
    {
        [$e, $listenerRuns, $measured] = $this->runSwallowedDeadlock(
            afterwards: static function (MySqlDriver $db): void {
                $db->getPdo()->exec('DO 1');
                $db->on('transaction.end', static function (array $data) use ($db): void {
                    if ($data['outcome'] === 'lost') {
                        $db->updateMultiple('lock_users', [['id' => 3, 'name' => 'by the end listener']]);
                        $db->beginTransaction();
                    }
                });
            }
        );

        $this->assertSame(['lost', 'committed'], array_column($measured['ends'], 'outcome'), "the refused transaction, then the listener's own");
        $this->assertSame($e, $measured['ends'][0]['error']);
        $this->assertSame(0, $listenerRuns, 'no rollback was sent for the transaction the listener left open');
        $this->assertTrue($measured['inTransactionAfterwards']);
    }

    /**
     * Nothing replaces a remembered deadlock: also a failure reported outside of query() (a
     * driver's own statement) leaves the block in place.
     */
    public function testNothingReplacesARememberedDeadlock(): void
    {
        $db = new class (self::getConfig()) extends MySqlDriver {
            public function note(int $driverCode, string $state): void
            {
                $e = new \PDOException('synthetic failure', 0);
                $e->errorInfo = [$state, $driverCode, 'synthetic failure'];
                $this->noteStatementFailure($e);
            }
        };

        $db->beginTransaction();
        $db->note(1213, '40001');
        $db->note(1062, '23000');
        try {
            $db->query('SELECT 1');
            $this->fail('Expected QueryException: the deadlock is still remembered');
        } catch (QueryException $e) {
            $this->assertSame(1213, $e->getPrevious()?->errorInfo[1] ?? null);
        }
        $db->rollback();
        $this->assertSame('1', (string) $db->query('SELECT 1')->fetchColumn());
    }

    /**
     * With autocommit switched off, a raw statement after the deadlock silently opens a new
     * transaction - and PDO reports "in a transaction" again. The commit is refused all the same:
     * that transaction holds only what came after the deadlock. The rollback undoes it.
     */
    public function testASwallowedDeadlockIsRefusedWithAutocommitOffToo(): void
    {
        [$e, $listenerRuns, $measured] = $this->runSwallowedDeadlock(
            afterwards: function (MySqlDriver $db): void {
                $db->getPdo()->exec("UPDATE lock_users SET name = 'after the deadlock' WHERE id = 2");
                $this->assertTrue($db->inTransaction(), 'the raw statement opened a new transaction');
                try {
                    $db->execute('UPDATE lock_users SET name = ? WHERE id = 3', ['through the library']);
                    $this->fail('Expected QueryException: nothing is sent after the deadlock');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('Not sent', (string) $e->getDebugMessage());
                }
            },
            autocommitOff: true
        );

        $this->assertSame(1213, $e->getPrevious()?->errorInfo[1] ?? null);
        $this->assertStringContainsString('deadlock (error 1213', (string) $e->getDebugMessage());
        $this->assertSame([['outcome' => 'rolled_back', 'error' => $e]], $measured['ends']);
        $this->assertInstanceOf(CommitFailedException::class, $e);
        $this->assertSame('rolled_back', $e->outcome);
        $this->assertSame(1, $listenerRuns);
        $this->assertSame('Max', $measured['user1Name']);
        $this->assertSame('Anna', $measured['user2Name'], 'nothing of the half transaction is committed');
    }

    /**
     * The manual pattern: after the refused commit PDO reports no transaction any more (a raw
     * statement told it), so no rollback() could end it. The refusal itself tells the end as 'lost',
     * and the next transaction gets its own end.
     */
    public function testARefusedManualCommitOfATransactionThatIsGoneTellsItsEnd(): void
    {
        [$e, $listenerRuns, $measured] = $this->runSwallowedDeadlock(
            afterwards: static fn (MySqlDriver $db): int|false => $db->getPdo()->exec("UPDATE lock_users SET name = 'after the deadlock' WHERE id = 2"),
            manual: true
        );

        $this->assertSame('Failed to commit transaction', $e->getMessage());
        $this->assertFalse($measured['inTransactionAfterwards']);
        $this->assertSame(0, $listenerRuns);
        $this->assertSame(['lost', 'committed'], array_column($measured['ends'], 'outcome'), 'the refused one, then the next transaction');
        $this->assertSame($e, $measured['ends'][0]['error']);
        $this->assertInstanceOf(CommitFailedException::class, $e);
        $this->assertSame('lost', $e->outcome, 'on a direct commit() too, once the library has told the end');
    }

    /**
     * For a failure other than a deadlock, commit() asks the server whether the transaction still
     * exists. The question can fail itself: the connection is gone. A callback that swallowed the
     * failed statement gets no commit then either; nothing can be confirmed, the end is 'lost'.
     */
    public function testCommitAfterAFailedStatementOnALostConnectionIsRefused(): void
    {
        foreach ([PDO::ERRMODE_EXCEPTION, PDO::ERRMODE_SILENT] as $mode) {
            $db = new MySqlDriver(self::getConfig() + ['options' => [PDO::ATTR_ERRMODE => $mode]]);
            $killer = new MySqlDriver(self::getConfig());
            $connectionId = (int) $db->query('SELECT CONNECTION_ID()')->fetchColumn();
            $ends = [];
            $db->on('transaction.end', static function (array $data) use (&$ends): void {
                $ends[] = $data;
            });

            try {
                $db->transaction(function (MySqlDriver $db) use ($killer, $connectionId): void {
                    $killer->execute('KILL ' . $connectionId);
                    $gone = false;
                    for ($i = 0; $i < 100 && !$gone; $i++) {
                        $gone = (int) $killer->query('SELECT COUNT(*) FROM information_schema.processlist WHERE id = ?', [$connectionId])->fetchColumn() === 0;
                        if (!$gone) {
                            usleep(50_000);
                        }
                    }
                    $this->assertTrue($gone, 'the killed connection did not disappear within 5 s');
                    try {
                        $db->execute('DO 1');
                        $this->fail('Expected QueryException: the connection is gone');
                    } catch (QueryException) {
                        // swallowed: the callback returns normally
                    }
                });
                $this->fail('Expected TransactionException');
            } catch (TransactionException $e) {
                $this->assertSame('Failed to commit transaction', $e->getMessage());
                $this->assertStringContainsString('the server could not be asked whether it still exists', (string) $e->getDebugMessage());
                $this->assertSame([['outcome' => 'lost', 'error' => $e]], $ends);
                $this->assertInstanceOf(CommitFailedException::class, $e);
                $this->assertSame('lost', $e->outcome);
            }
        }
    }

    /**
     * @param (Closure(MySqlDriver): mixed)|null $afterwards What the callback does after it swallowed the deadlock
     * @param bool $manual beginTransaction()/commit() instead of transaction(), followed by one more (empty) transaction
     *
     * @return array{\Throwable, int, array<string, mixed>}
     */
    private function runSwallowedDeadlock(?Closure $afterwards = null, bool $autocommitOff = false, bool $manual = false): array
    {
        [$e, $listenerRuns, $measured, $childOutput] = $this->runLockScenario(
            <<<'PHP'
                $pdo->beginTransaction();
                $pdo->exec('UPDATE lock_attempts SET attempts = attempts + 1 WHERE user_id IN (1, 2, 3, 4)'); // the bigger transaction survives
                touch($marker);
                $pdo->query('SELECT id FROM lock_users WHERE id = 1 FOR UPDATE')->fetchAll(); // waits for the test's lock
                $pdo->commit();
                echo 'committed';
                PHP,
            function (MySqlDriver $db, Closure $startChild) use ($afterwards, $autocommitOff, $manual): array {
                $measured = ['ends' => [], 'swallowed' => null];
                $db->on('transaction.end', static function (array $data) use (&$measured): void {
                    $measured['ends'][] = $data;
                });
                if ($autocommitOff) {
                    $db->execute('SET autocommit = 0');
                }
                $work = static function (MySqlDriver $db) use ($startChild, &$measured, $afterwards): void {
                    $db->execute('UPDATE lock_users SET name = ? WHERE id = 1', ['renamed']);
                    $startChild();
                    try {
                        $db->execute('UPDATE lock_attempts SET attempts = attempts + 1 WHERE user_id = 1');
                    } catch (QueryException $e) {
                        $measured['swallowed'] = $e->getPrevious(); // swallowed: the callback goes on
                    }
                    if ($afterwards !== null) {
                        $afterwards($db);
                    }
                };
                try {
                    if ($manual) {
                        $db->beginTransaction();
                        $work($db);
                        $db->commit();
                    } else {
                        $db->transaction($work);
                    }
                    $this->fail('Expected TransactionException: nothing was committed');
                } catch (TransactionException $e) {
                    $measured['inTransactionAfterwards'] = $db->inTransaction();
                    if ($manual) {
                        $db->transaction(static fn (): null => null);
                    }

                    return [$e, $measured];
                }
            }
        );
        $this->assertSame('committed', $childOutput);

        return [$e, $listenerRuns, $measured];
    }

    /**
     * A deadlock makes InnoDB roll back the whole transaction on the server (error 1213). The client
     * still sees inTransaction() as true (mysqlnd keeps the status of the last OK packet), so
     * transaction() sends its ROLLBACK, which succeeds, and the 'transaction.rollback' listeners run.
     * Shape as in the AuthServer: a row lock on users via SELECT ... FOR UPDATE against an UPDATE on
     * login_attempts, in opposite order on two connections; the second connection is a child process
     * (in one PHP process the first blocked statement would stop everything).
     */
    public function testDeadlockRollbackByTheServerStillRunsTheRollbackListeners(): void
    {
        [$e, $listenerRuns, $measured, $childOutput] = $this->runLockScenario(
            <<<'PHP'
                $pdo->beginTransaction();
                $pdo->exec('UPDATE lock_attempts SET attempts = attempts + 1 WHERE user_id IN (1, 2, 3, 4)'); // the bigger transaction survives
                touch($marker);
                $pdo->query('SELECT id FROM lock_users WHERE id = 1 FOR UPDATE')->fetchAll(); // waits for the test's lock
                $pdo->commit();
                echo 'committed';
                PHP,
            function (MySqlDriver $db, Closure $startChild): array {
                $measured = ['ends' => []];
                $db->on('transaction.end', static function (array $data) use (&$measured): void {
                    $measured['ends'][] = $data;
                });
                try {
                    $db->transaction(static function (MySqlDriver $db) use ($startChild, &$measured): void {
                        $db->query('SELECT id FROM lock_users WHERE id = 1 FOR UPDATE')->fetchAll();
                        $startChild(); // the child locks its rows only after this connection holds the users row
                        try {
                            $db->execute('UPDATE lock_attempts SET attempts = attempts + 1 WHERE user_id = 1');
                        } catch (QueryException $e) {
                            $measured['inTransactionAfterError'] = $db->inTransaction();
                            throw $e;
                        }
                    });
                    $this->fail('Expected the deadlock');
                } catch (QueryException $e) {
                    $measured['inTransactionAfterwards'] = $db->inTransaction();

                    return [$e, $measured];
                }
            }
        );

        $this->assertSame(1213, $e->getPrevious()?->errorInfo[1] ?? null, 'ER_LOCK_DEADLOCK: this connection was the victim');
        $this->assertTrue($measured['inTransactionAfterError'], 'PDO still reports the transaction after the server rolled it back');
        $this->assertSame(1, $listenerRuns, 'transaction() rolled back and fired the listener');
        $this->assertSame([['outcome' => 'rolled_back', 'error' => $e]], $measured['ends'], 'transaction.end: rolled back, with the deadlock exception');
        $this->assertFalse($measured['inTransactionAfterwards']);
        $this->assertSame('committed', $childOutput);
    }

    /**
     * A lock wait timeout (error 1205) makes the server roll back only the failed statement; the
     * transaction stays open, so transaction() rolls it back and the listeners run.
     */
    public function testLockWaitTimeoutLeavesTheTransactionOpenAndTheRollbackListenersRun(): void
    {
        [$e, $listenerRuns, $measured, $childOutput] = $this->runLockScenario(
            <<<'PHP'
                $pdo->beginTransaction();
                $pdo->exec('UPDATE lock_attempts SET attempts = attempts + 1 WHERE user_id = 1');
                touch($marker);
                $awaitRelease(); // hold the lock until the test is done waiting
                $pdo->commit();
                echo 'committed';
                PHP,
            function (MySqlDriver $db, Closure $startChild): array {
                $db->execute('SET SESSION innodb_lock_wait_timeout = 1');
                $measured = ['ends' => []];
                $db->on('transaction.end', static function (array $data) use (&$measured): void {
                    $measured['ends'][] = $data;
                });
                try {
                    $db->transaction(static function (MySqlDriver $db) use ($startChild, &$measured): void {
                        $db->execute('UPDATE lock_users SET name = ? WHERE id = 1', ['touched']);
                        $startChild();
                        try {
                            $db->execute('UPDATE lock_attempts SET attempts = attempts + 1 WHERE user_id = 1');
                        } catch (QueryException $e) {
                            $measured['inTransactionAfterError'] = $db->inTransaction();
                            throw $e;
                        }
                    });
                    $this->fail('Expected the lock wait timeout');
                } catch (QueryException $e) {
                    $measured['inTransactionAfterwards'] = $db->inTransaction();

                    return [$e, $measured];
                }
            }
        );

        $this->assertSame(1205, $e->getPrevious()?->errorInfo[1] ?? null, 'ER_LOCK_WAIT_TIMEOUT');
        $this->assertTrue($measured['inTransactionAfterError'], 'only the statement was rolled back, the transaction is open');
        $this->assertSame(1, $listenerRuns);
        $this->assertSame([['outcome' => 'rolled_back', 'error' => $e]], $measured['ends'], 'transaction.end: rolled back, with the timeout exception');
        $this->assertFalse($measured['inTransactionAfterwards']);
        $this->assertSame('committed', $childOutput);
        $this->assertSame('Max', $measured['user1Name'], "the test connection's own update was rolled back");
    }

    /**
     * A lost connection (the server killed it: error 2006/2013): the callback's statement fails,
     * the rollback attempt fails too, and no 'transaction.rollback' listener runs. PDO still
     * reported the transaction after the failed rollback.
     */
    public function testLostConnectionRollsBackNothingAndRunsNoListener(): void
    {
        [$e, $listenerRuns, $measured] = $this->runLockScenario(
            null,
            function (MySqlDriver $db): array {
                $config = self::getConfig();
                $killer = new PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['database']),
                    $config['username'],
                    $config['password'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $connectionId = (int) $db->query('SELECT CONNECTION_ID()')->fetchColumn();
                $measured = ['ends' => []];
                $db->on('transaction.end', static function (array $data) use (&$measured): void {
                    $measured['ends'][] = $data;
                });
                try {
                    $db->transaction(static function (MySqlDriver $db) use ($killer, $connectionId, &$measured): void {
                        $db->execute('UPDATE lock_users SET name = ? WHERE id = 1', ['touched']);
                        $killer->exec('KILL ' . $connectionId);
                        // wait until the server has dropped the connection (not just scheduled the kill)
                        $connectionGone = false;
                        for ($i = 0; $i < 100 && !$connectionGone; $i++) {
                            $gone = $killer->prepare('SELECT COUNT(*) FROM information_schema.processlist WHERE id = ?');
                            $gone->execute([$connectionId]);
                            $connectionGone = (int) $gone->fetchColumn() === 0;
                            if (!$connectionGone) {
                                usleep(50_000);
                            }
                        }
                        if (!$connectionGone) {
                            throw new \RuntimeException('the killed connection did not disappear from the processlist within 5 s');
                        }
                        try {
                            $db->execute('UPDATE lock_attempts SET attempts = attempts + 1 WHERE user_id = 1');
                        } catch (QueryException $e) {
                            $measured['inTransactionAfterError'] = $db->inTransaction();
                            throw $e;
                        }
                    });
                    $this->fail('Expected the lost connection');
                } catch (QueryException $e) {
                    $measured['inTransactionAfterwards'] = $db->inTransaction();

                    return [$e, $measured];
                }
            }
        );

        $this->assertContains($e->getPrevious()?->errorInfo[1] ?? null, [2006, 2013], 'server has gone away / lost connection');
        $this->assertSame(0, $listenerRuns, 'the rollback failed with the connection: no listener');
        $this->assertSame([['outcome' => 'lost', 'error' => $e]], $measured['ends'], 'transaction.end reports lost with the statement exception');
        $this->assertSame('Max', $measured['user1Name'], 'the server rolled the killed connection back');
        $this->assertTrue($measured['inTransactionAfterError'], 'PDO still reports the transaction right after the error');
        $this->assertTrue($measured['inTransactionAfterwards'], 'and still after the failed rollback');
    }

    /**
     * Runs $scenario on a fresh driver with a 'transaction.rollback' counter. $startChild() launches a
     * child process (when $childBody is given) that runs $childBody on its own PDO connection -
     * $pdo, $marker and $awaitRelease() are available there - and returns once the child touched
     * $marker ("I hold my locks"); the child is released after the scenario and ended with a deadline.
     *
     * @param Closure(MySqlDriver, Closure): array{\Throwable, array<string, mixed>} $scenario
     *
     * @return array{\Throwable, int, array<string, mixed>, string} exception, rollback listener runs, measurements, child output
     */
    private function runLockScenario(?string $childBody, Closure $scenario): array
    {
        $config = self::getConfig();
        $db = null;
        $child = null;
        $pipes = [];
        $files = [];
        $listenerRuns = 0;
        $childOutput = '';

        try {
            $marker = $files[] = tempnam(sys_get_temp_dir(), 'pdo-lock-marker-');
            $release = $files[] = tempnam(sys_get_temp_dir(), 'pdo-lock-release-');
            $script = $files[] = tempnam(sys_get_temp_dir(), 'pdo-lock-child-');
            unlink($marker);
            unlink($release);

            $db = new MySqlDriver($config);
            $db->execute('DROP TABLE IF EXISTS lock_attempts');
            $db->execute('DROP TABLE IF EXISTS lock_users');
            $db->execute('CREATE TABLE lock_users (id INT PRIMARY KEY, name VARCHAR(20) NOT NULL) ENGINE=InnoDB');
            $db->execute('CREATE TABLE lock_attempts (user_id INT PRIMARY KEY, attempts INT NOT NULL) ENGINE=InnoDB');
            $db->execute("INSERT INTO lock_users (id, name) VALUES (1, 'Max'), (2, 'Anna'), (3, 'Tom'), (4, 'Eva')");
            $db->execute('INSERT INTO lock_attempts (user_id, attempts) VALUES (1, 0), (2, 0), (3, 0), (4, 0)');
            $db->on('transaction.rollback', static function () use (&$listenerRuns): void {
                $listenerRuns++;
            });

            $startChild = function () use (&$child, &$pipes, $childBody, $script, $config, $marker, $release): void {
                if ($childBody === null) {
                    return;
                }
                file_put_contents($script, "<?php\n[, \$dsn, \$user, \$password, \$marker, \$release] = \$argv;\n"
                    . "\$pdo = new PDO(\$dsn, \$user, \$password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);\n"
                    . "\$awaitRelease = static function () use (\$release): void { for (\$i = 0; \$i < 200 && !file_exists(\$release); \$i++) { usleep(100_000); } };\n"
                    . "try {\n" . $childBody . "\n} catch (PDOException \$e) {\n    echo 'child failed: ' . \$e->getMessage();\n}\n");
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['database']);
                $process = proc_open([PHP_BINARY, $script, $dsn, $config['username'], $config['password'], $marker, $release], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $child = is_resource($process) ? $process : null;
                $this->assertNotNull($child, 'child process did not start');
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                for ($i = 0; $i < 100 && !file_exists($marker); $i++) {
                    usleep(100_000);
                }
                $this->assertFileExists($marker, 'the child did not lock its rows in time');
                usleep(300_000); // let the child's waiting statement start waiting
            };

            [$e, $measured] = $scenario($db, $startChild);
            touch($release);
            $measured['user1Name'] = (string) ($this->driver->table('lock_users')->where('id', 1)->first()['name'] ?? '');
            $measured['user2Name'] = (string) ($this->driver->table('lock_users')->where('id', 2)->first()['name'] ?? '');

            if ($child !== null) {
                $childOutput = $this->collectChildOutput($child, $pipes, 10.0);
            }
        } finally {
            if (isset($release) && !file_exists($release)) {
                touch($release);
            }
            if ($child !== null) {
                $this->endChild($child, $pipes, 5.0);
            }
            if ($db !== null) {
                try {
                    if ($db->getPdo()->inTransaction()) {
                        $db->getPdo()->rollBack();
                    }
                } catch (\Throwable) {
                    // best effort: the connection may be gone
                }
                $db = null; // close the test connection and its locks before dropping the tables
            }
            try {
                $this->driver->execute('DROP TABLE IF EXISTS lock_attempts');
                $this->driver->execute('DROP TABLE IF EXISTS lock_users');
            } finally {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }

        return [$e, $listenerRuns, $measured, $childOutput];
    }

    /**
     * Reads the child's stdout until it exits or $seconds pass; stderr must stay empty.
     *
     * @param resource $child
     * @param array<int, resource> $pipes
     */
    private function collectChildOutput($child, array $pipes, float $seconds): string
    {
        $out = '';
        $err = '';
        $deadline = microtime(true) + $seconds;
        do {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $running = proc_get_status($child)['running'];
            if ($running) {
                usleep(50_000);
            }
        } while ($running && microtime(true) < $deadline);
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        $this->assertFalse($running, 'the child process did not finish in time');
        $this->assertSame('', trim($err));

        return trim($out);
    }

    /**
     * Ends the child: waits up to $seconds, then terminates it; closes the pipes either way.
     *
     * @param resource $child
     * @param array<int, resource> $pipes
     */
    private function endChild($child, array $pipes, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (proc_get_status($child)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (proc_get_status($child)['running']) {
            proc_terminate($child, 9);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($child);
    }

    public function testTransactionCommitWithoutBeginThrowsException(): void
    {
        $this->expectException(TransactionException::class);

        $this->driver->commit();
    }

    public function testTransactionRollbackWithoutBeginThrowsException(): void
    {
        $this->expectException(TransactionException::class);

        $this->driver->rollback();
    }

    public function testNestedTransactionBeginThrowsException(): void
    {
        $this->driver->beginTransaction();

        $this->expectException(TransactionException::class);

        $this->driver->beginTransaction();
    }

    public function testTransactionExceptionHasDebugMessage(): void
    {
        try {
            $this->driver->commit();
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertNotNull($e->getDebugMessage());
            return;
        }

        $this->fail('Expected TransactionException was not thrown');
    }
}
