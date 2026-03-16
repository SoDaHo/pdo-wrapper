<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * @group postgres
 */
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
