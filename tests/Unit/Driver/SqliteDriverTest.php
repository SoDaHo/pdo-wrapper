<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;

class SqliteDriverTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['DB_SQLITE_PATH']);
    }

    public function testDefaultsToMemory(): void
    {
        $driver = new SqliteDriver();

        $this->assertNotNull($driver->getPdo());
    }

    public function testExplicitMemoryPath(): void
    {
        $driver = new SqliteDriver(':memory:');

        $this->assertNotNull($driver->getPdo());
    }

    public function testReadsPathFromEnv(): void
    {
        $_ENV['DB_SQLITE_PATH'] = ':memory:';

        $driver = new SqliteDriver();

        $this->assertNotNull($driver->getPdo());
    }

    public function testExplicitPathOverridesEnv(): void
    {
        $_ENV['DB_SQLITE_PATH'] = '/some/invalid/path.db';

        // Explicit :memory: should override ENV
        $driver = new SqliteDriver(':memory:');

        $this->assertNotNull($driver->getPdo());
    }

    public function testInvalidPathThrowsConnectionException(): void
    {
        $this->expectException(ConnectionException::class);

        new SqliteDriver('/nonexistent/directory/that/does/not/exist/test.db');
    }

    public function testConnectionExceptionHasDebugMessage(): void
    {
        try {
            new SqliteDriver('/nonexistent/directory/test.db');
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertStringContainsString('SQLite', $e->getDebugMessage());
            return;
        }

        $this->fail('Expected ConnectionException was not thrown');
    }
}
