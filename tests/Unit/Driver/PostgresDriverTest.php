<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;

class PostgresDriverTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up ENV after each test
        unset($_ENV['DB_HOST'], $_ENV['DB_DATABASE'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $_ENV['DB_PORT']);
        putenv('DB_HOST');
        putenv('DB_DATABASE');
        putenv('DB_USERNAME');
        putenv('DB_PASSWORD');
        putenv('DB_PORT');
    }

    public function testThrowsExceptionWhenHostMissing(): void
    {
        $this->expectException(ConnectionException::class);

        new PostgresDriver([
            'database' => 'test',
            'username' => 'postgres',
        ]);
    }

    public function testThrowsExceptionWhenDatabaseMissing(): void
    {
        $this->expectException(ConnectionException::class);

        new PostgresDriver([
            'host' => 'localhost',
            'username' => 'postgres',
        ]);
    }

    public function testThrowsExceptionWhenUsernameMissing(): void
    {
        $this->expectException(ConnectionException::class);

        new PostgresDriver([
            'host' => 'localhost',
            'database' => 'test',
        ]);
    }

    public function testExceptionHasDebugMessage(): void
    {
        try {
            new PostgresDriver([]);
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertSame('Missing required config: host, database, or username', $e->getDebugMessage());
            return;
        }

        $this->fail('Expected ConnectionException was not thrown');
    }

    /**
     * Test that Factory reads from $_ENV.
     */
    public function testFactoryReadsConfigFromEnv(): void
    {
        $_ENV['DB_HOST'] = 'invalid-host-that-does-not-exist';
        $_ENV['DB_DATABASE'] = 'testdb';
        $_ENV['DB_USERNAME'] = 'testuser';
        $_ENV['DB_PASSWORD'] = 'testpass';

        $this->expectException(ConnectionException::class);

        // Factory reads $_ENV and passes to driver
        Database::postgres();
    }

    /**
     * Test that Factory reads from getenv() as fallback.
     */
    public function testFactoryReadsConfigFromGetenv(): void
    {
        putenv('DB_HOST=getenv-host-invalid');
        putenv('DB_DATABASE=testdb');
        putenv('DB_USERNAME=testuser');

        $this->expectException(ConnectionException::class);

        try {
            Database::postgres();
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('getenv-host-invalid', $e->getDebugMessage());
            throw $e;
        }
    }

    /**
     * Test that $_ENV has priority over getenv().
     */
    public function testEnvHasPriorityOverGetenv(): void
    {
        $_ENV['DB_HOST'] = 'env-host-invalid';
        putenv('DB_HOST=getenv-host-should-not-be-used');
        $_ENV['DB_DATABASE'] = 'testdb';
        $_ENV['DB_USERNAME'] = 'testuser';

        $this->expectException(ConnectionException::class);

        try {
            Database::postgres();
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('env-host-invalid', $e->getDebugMessage());
            $this->assertStringNotContainsString('getenv-host', $e->getDebugMessage());
            throw $e;
        }
    }

    public function testArrayConfigOverridesEnv(): void
    {
        $_ENV['DB_HOST'] = 'env-host';
        $_ENV['DB_DATABASE'] = 'env-db';
        $_ENV['DB_USERNAME'] = 'env-user';

        $this->expectException(ConnectionException::class);

        try {
            Database::postgres([
                'host' => 'array-host',
                'database' => 'array-db',
                'username' => 'array-user',
            ]);
        } catch (ConnectionException $e) {
            // Verify array config was used, not ENV
            $this->assertStringContainsString('array-host', $e->getDebugMessage());
            throw $e;
        }
    }

    public function testDefaultPortIs5432(): void
    {
        $this->expectException(ConnectionException::class);

        try {
            new PostgresDriver([
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'postgres',
            ]);
        } catch (ConnectionException $e) {
            $this->assertStringContainsString(':5432', $e->getDebugMessage());
            throw $e;
        }
    }

    public function testCustomPortFromConfig(): void
    {
        $this->expectException(ConnectionException::class);

        try {
            new PostgresDriver([
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'postgres',
                'port' => 5433,
            ]);
        } catch (ConnectionException $e) {
            $this->assertStringContainsString(':5433', $e->getDebugMessage());
            throw $e;
        }
    }

    public function testCustomPortFromEnv(): void
    {
        $_ENV['DB_HOST'] = 'localhost';
        $_ENV['DB_DATABASE'] = 'test';
        $_ENV['DB_USERNAME'] = 'postgres';
        $_ENV['DB_PORT'] = '5434';

        $this->expectException(ConnectionException::class);

        try {
            Database::postgres();
        } catch (ConnectionException $e) {
            $this->assertStringContainsString(':5434', $e->getDebugMessage());
            throw $e;
        }
    }
}
