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
        } finally {
            $db->execute('SET SESSION completion_type = NO_CHAIN');
            if ($db->inTransaction()) {
                $db->rollback();
            }
        }

        $this->assertFalse($secondRan);
        $this->assertFalse($db->inTransaction());
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
                $measured = [];
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
                $measured = [];
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
                $measured = [];
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
     * @param Closure(MySqlDriver, Closure): array{QueryException, array<string, mixed>} $scenario
     *
     * @return array{QueryException, int, array<string, mixed>, string} exception, rollback listener runs, measurements, child output
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
