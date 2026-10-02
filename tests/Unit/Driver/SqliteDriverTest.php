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

    /**
     * SQLite reads the path up to a NUL byte: "data.db\0.txt" would open "data.db".
     */
    public function testRejectsANulByteInThePath(): void
    {
        $path = sys_get_temp_dir() . '/pdo-wrapper-nul-' . bin2hex(random_bytes(4));

        try {
            new SqliteDriver($path . "\0.txt");
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('Invalid character in config value "path"', $e->getDebugMessage());
        }

        $this->assertFileDoesNotExist($path);
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
