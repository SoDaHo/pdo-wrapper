<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;

class SqliteDriverTest extends TestCase
{
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
