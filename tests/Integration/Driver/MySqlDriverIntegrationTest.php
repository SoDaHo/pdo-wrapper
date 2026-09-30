<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\CommitHookException;
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
