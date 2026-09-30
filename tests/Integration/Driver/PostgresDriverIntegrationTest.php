<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;

#[Group('postgres')]
class PostgresDriverIntegrationTest extends TestCase
{
    private PostgresDriver $driver;

    private static function getConfig(): array
    {
        return [
            'host' => $_ENV['POSTGRES_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
            'database' => $_ENV['POSTGRES_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['POSTGRES_USERNAME'] ?? 'postgres',
            'password' => $_ENV['POSTGRES_PASSWORD'] ?? 'postgres',
        ];
    }

    protected function setUp(): void
    {
        $this->driver = new PostgresDriver(self::getConfig());
    }

    public function testImplementsDatabaseInterface(): void
    {
        $this->assertInstanceOf(DatabaseInterface::class, $this->driver);
    }

    public function testConnectsToPostgres(): void
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
        $this->driver->execute('CREATE TEMPORARY TABLE test_pg (id SERIAL PRIMARY KEY, name TEXT)');

        $affected = $this->driver->execute('INSERT INTO test_pg (name) VALUES ($1)', ['hello']);

        $this->assertSame(1, $affected);
    }

    public function testLastInsertId(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_pg2 (id SERIAL PRIMARY KEY, name TEXT)');
        $this->driver->execute('INSERT INTO test_pg2 (name) VALUES ($1)', ['hello']);

        $id = $this->driver->lastInsertId('test_pg2_id_seq');

        $this->assertSame('1', $id);
    }

    public function testLastInsertIdWithInvalidSequenceThrowsQueryException(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Failed to get last insert ID');

        $this->driver->lastInsertId('non_existent_sequence_that_does_not_exist');
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

    public function testInvalidHostThrowsConnectionException(): void
    {
        $this->expectException(ConnectionException::class);

        new PostgresDriver([
            'host' => 'invalid-host-that-does-not-exist',
            'database' => 'test',
            'username' => 'test',
            'password' => 'test',
        ]);
    }

    public function testInsertReturnsIdForSerialTable(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_insert_serial (id SERIAL PRIMARY KEY, name TEXT)');

        $id = $this->driver->insert('test_insert_serial', ['name' => 'hello']);

        $this->assertSame(1, $id);
    }

    public function testInsertReturnsZeroForTableWithoutSequence(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_insert_composite (article_id INT, stat_date DATE, views INT, PRIMARY KEY (article_id, stat_date))');

        $id = $this->driver->insert('test_insert_composite', ['article_id' => 1, 'stat_date' => '2026-01-01', 'views' => 42]);

        $this->assertSame(0, $id);
    }

    /**
     * Regression test: the failing currval() probe for a table without {table}_id_seq aborted the
     * surrounding transaction, so the later COMMIT silently became a ROLLBACK and nothing was stored.
     */
    public function testInsertWithoutSequenceInsideTransactionKeepsTheTransaction(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_tx_tokens (token TEXT PRIMARY KEY, name TEXT)');
        $this->driver->execute('CREATE TEMPORARY TABLE test_tx_serial (id SERIAL PRIMARY KEY, name TEXT)');

        $ids = $this->driver->transaction(fn (DatabaseInterface $db): array => [
            $db->insert('test_tx_tokens', ['token' => 'tok', 'name' => 'first']),
            $db->insert('test_tx_serial', ['name' => 'second']),
        ]);

        $this->assertSame([0, 1], $ids);
        $this->assertFalse($this->driver->getPdo()->inTransaction());
        $this->assertSame(1, $this->driver->table('test_tx_tokens')->count());
        $this->assertSame(1, $this->driver->table('test_tx_serial')->count());
    }

    /**
     * currval() is undefined until nextval() ran in this session: an explicit id skips the sequence,
     * the probe fails, and the row must still be committed.
     */
    public function testInsertWithExplicitIdInsideTransactionKeepsTheTransaction(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_tx_explicit (id SERIAL PRIMARY KEY, name TEXT)');

        $id = $this->driver->transaction(
            fn (DatabaseInterface $db): int|string => $db->insert('test_tx_explicit', ['id' => 1000, 'name' => 'explicit'])
        );

        $this->assertSame(0, $id);
        $this->assertNotNull($this->driver->findOne('test_tx_explicit', ['id' => 1000]));
    }

    /**
     * In a non-exception error mode PDO reports the failed currval() probe as false instead of
     * throwing; the transaction is aborted all the same and must be rescued through the savepoint.
     */
    public function testInsertWithoutSequenceInsideTransactionInSilentErrorMode(): void
    {
        $driver = new PostgresDriver(self::getConfig() + ['options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]]);
        $driver->execute('CREATE TEMPORARY TABLE test_silent_tokens (token TEXT PRIMARY KEY, name TEXT)');

        $id = $driver->transaction(
            fn (DatabaseInterface $db): int|string => $db->insert('test_silent_tokens', ['token' => 'tok', 'name' => 'silent'])
        );

        $this->assertSame(0, $id);
        $this->assertFalse($driver->getPdo()->inTransaction());
        $this->assertSame(1, $driver->table('test_silent_tokens')->count());
    }

    public function testNowAndUtcNowAreUsableAsValues(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_now (id SERIAL PRIMARY KEY, local_at TIMESTAMP, utc_at TIMESTAMP)');

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
        // INTERVAL form: ISO sign (east positive). A bare '+02:00' would be read POSIX-style, i.e. west of UTC.
        $this->driver->execute("SET TIME ZONE INTERVAL '+02:00' HOUR TO MINUTE");
        $this->driver->execute('CREATE TEMPORARY TABLE test_tz (id SERIAL PRIMARY KEY, local_at TIMESTAMP, utc_at TIMESTAMP)');

        $id = $this->driver->insert('test_tz', ['local_at' => $this->driver->now(), 'utc_at' => $this->driver->utcNow()]);
        $row = $this->driver->findOne('test_tz', ['id' => $id]);

        $this->assertNotNull($row);
        $local = (int) strtotime((string) $row['local_at'] . ' UTC');
        $utc = (int) strtotime((string) $row['utc_at'] . ' UTC');
        $this->assertEqualsWithDelta(7200, $local - $utc, 2);
        $this->assertEqualsWithDelta(time(), $utc, 5);
    }

    /**
     * now()/utcNow() are statement time, not transaction time: two statements a second apart in the
     * same open transaction must differ (NOW()/LOCALTIMESTAMP would return the same value twice).
     */
    public function testNowIsStatementTimeNotTransactionTime(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_stmt (id SERIAL PRIMARY KEY, local_at TIMESTAMP, utc_at TIMESTAMP)');
        $this->driver->beginTransaction();
        $first = $this->driver->insert('test_stmt', ['local_at' => $this->driver->now(), 'utc_at' => $this->driver->utcNow()]);
        usleep(1100000);
        $second = $this->driver->insert('test_stmt', ['local_at' => $this->driver->now(), 'utc_at' => $this->driver->utcNow()]);
        $rows = $this->driver->findAll('test_stmt');
        $this->driver->rollback();

        $this->assertSame([1, 2], [$first, $second]);
        $this->assertGreaterThan((string) $rows[0]['local_at'], (string) $rows[1]['local_at']);
        $this->assertGreaterThan((string) $rows[0]['utc_at'], (string) $rows[1]['utc_at']);
    }

    /**
     * Raw values in insert() go through the same savepoint probe: both rows must be committed.
     */
    public function testRawInsertValuesInsideTransactionAreCommitted(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_raw_serial (id SERIAL PRIMARY KEY, seen_at TIMESTAMP)');
        $this->driver->execute('CREATE TEMPORARY TABLE test_raw_tokens (token TEXT PRIMARY KEY, seen_at TIMESTAMP)');

        $ids = $this->driver->transaction(fn (DatabaseInterface $db): array => [
            $db->insert('test_raw_tokens', ['token' => 'tok', 'seen_at' => $db->utcNow()]),
            $db->insert('test_raw_serial', ['seen_at' => $db->now()]),
        ]);

        $this->assertSame([0, 1], $ids);
        $this->assertFalse($this->driver->getPdo()->inTransaction());
        $this->assertSame(1, $this->driver->table('test_raw_tokens')->whereNotNull('seen_at')->count());
        $this->assertSame(1, $this->driver->table('test_raw_serial')->whereNotNull('seen_at')->count());
    }

    public function testConnectionExceptionHasDebugMessage(): void
    {
        try {
            new PostgresDriver([
                'host' => $_ENV['POSTGRES_HOST'] ?? '127.0.0.1',
                'database' => 'nonexistent_db_that_does_not_exist',
                'username' => $_ENV['POSTGRES_USERNAME'] ?? 'postgres',
                'password' => $_ENV['POSTGRES_PASSWORD'] ?? 'postgres',
            ]);
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertNotNull($e->getDebugMessage());
            $this->assertStringContainsString('PostgreSQL', $e->getDebugMessage());
            return;
        }

        $this->fail('Expected ConnectionException was not thrown');
    }
}
