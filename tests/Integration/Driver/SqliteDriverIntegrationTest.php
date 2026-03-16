<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;

class SqliteDriverIntegrationTest extends TestCase
{
    public function testImplementsDatabaseInterface(): void
    {
        $driver = new SqliteDriver();

        $this->assertInstanceOf(DatabaseInterface::class, $driver);
    }

    public function testConnectsToMemoryDatabase(): void
    {
        $driver = new SqliteDriver();

        $this->assertInstanceOf(PDO::class, $driver->getPdo());
    }

    public function testConnectsWithExplicitMemoryPath(): void
    {
        $driver = new SqliteDriver(':memory:');

        $this->assertInstanceOf(PDO::class, $driver->getPdo());
    }

    public function testQueryReturnsStatement(): void
    {
        $driver = new SqliteDriver();

        $stmt = $driver->query('SELECT 1 as test');

        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertSame(1, $stmt->fetch()['test']);
    }

    public function testExecuteReturnsAffectedRows(): void
    {
        $driver = new SqliteDriver();
        $driver->execute('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');

        $affected = $driver->execute('INSERT INTO test (name) VALUES (?)', ['hello']);

        $this->assertSame(1, $affected);
    }

    public function testLastInsertId(): void
    {
        $driver = new SqliteDriver();
        $driver->execute('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $driver->execute('INSERT INTO test (name) VALUES (?)', ['hello']);

        $id = $driver->lastInsertId();

        $this->assertSame('1', $id);
    }

    public function testConnectionUsesExceptionErrorMode(): void
    {
        $driver = new SqliteDriver();
        $pdo = $driver->getPdo();

        $errorMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $errorMode);
    }

    public function testConnectionUsesFetchAssoc(): void
    {
        $driver = new SqliteDriver();
        $pdo = $driver->getPdo();

        $fetchMode = $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);

        $this->assertSame(PDO::FETCH_ASSOC, $fetchMode);
    }

    public function testReadsPathFromEnv(): void
    {
        $_ENV['DB_SQLITE_PATH'] = ':memory:';

        $driver = new SqliteDriver();

        $this->assertInstanceOf(PDO::class, $driver->getPdo());

        unset($_ENV['DB_SQLITE_PATH']);
    }

    public function testExplicitPathOverridesEnv(): void
    {
        $_ENV['DB_SQLITE_PATH'] = '/some/other/path.db';

        $driver = new SqliteDriver(':memory:');

        $this->assertInstanceOf(PDO::class, $driver->getPdo());

        unset($_ENV['DB_SQLITE_PATH']);
    }

    public function testInvalidPathThrowsConnectionException(): void
    {
        $this->expectException(ConnectionException::class);

        new SqliteDriver('/nonexistent/directory/that/does/not/exist/test.db');
    }

    public function testForeignKeysAreEnabled(): void
    {
        $driver = new SqliteDriver();

        $result = $driver->query('PRAGMA foreign_keys')->fetch();

        $this->assertSame(1, $result['foreign_keys']);
    }

    /**
     * Regression test: Foreign key constraints must be enforced.
     *
     * Previously, SQLite FK constraints were defined but not enforced,
     * allowing inserts with invalid foreign keys (silent data corruption).
     */
    public function testForeignKeyConstraintIsEnforced(): void
    {
        $driver = new SqliteDriver();

        // Create parent table
        $driver->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        // Create child table with FK constraint
        $driver->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id))');

        // Insert a valid user
        $driver->execute('INSERT INTO users (id, name) VALUES (1, ?)', ['Max']);

        // This should work - valid FK
        $driver->execute('INSERT INTO posts (user_id) VALUES (1)');

        // This should fail - user 999 does not exist
        $this->expectException(QueryException::class);
        $driver->execute('INSERT INTO posts (user_id) VALUES (999)');
    }
}
