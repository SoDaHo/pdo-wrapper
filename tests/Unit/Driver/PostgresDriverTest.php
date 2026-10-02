<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Tests\Support\Untyped;

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
        unset($_ENV['DB_DRIVER']);
        putenv('DB_DRIVER');
    }

    /**
     * A ";" in a DSN value is PDO's separator, NUL truncates the DSN. Both are rejected before any
     * connection attempt, without echoing the value. Whitespace is handled by libpq quoting (integration test).
     */
    public function testRejectsSemicolonAndNulInConnectionValuesBeforeConnecting(): void
    {
        $base = ['host' => '127.0.0.1', 'database' => 'app', 'username' => 'postgres', 'password' => 'x'];
        $cases = [
            ['host', '127.0.0.1;port=5433'],
            ['database', 'app;host=evil'],
            ['host', "127.0.0.1\0"],
            ['database', "app\0 host=evil"],
        ];

        foreach ($cases as [$key, $value]) {
            try {
                new PostgresDriver([$key => $value] + $base);
                $this->fail("Expected ConnectionException for {$key}");
            } catch (ConnectionException $e) {
                $this->assertSame('Database connection failed', $e->getMessage());
                $this->assertSame(sprintf('Invalid character in config value "%s"', $key), $e->getDebugMessage());
            }
        }
    }

    public function testRejectsAPortThatIsNotAWholeNumberInRange(): void
    {
        $base = ['host' => '127.0.0.1', 'database' => 'app', 'username' => 'postgres', 'password' => 'x'];

        foreach (['abc', '5432 host=evil', 0, 65536, 5432.5] as $port) {
            try {
                Untyped::create(PostgresDriver::class, ['port' => $port] + $base); // not all of them are a port by type
                $this->fail('Expected ConnectionException for port ' . var_export($port, true));
            } catch (ConnectionException $e) {
                $this->assertSame('Invalid config value "port": expected a whole number between 1 and 65535', $e->getDebugMessage());
            }
        }
    }

    public function testAnInvalidPortFromTheEnvironmentIsRejected(): void
    {
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_DATABASE'] = 'test';
        $_ENV['DB_USERNAME'] = 'postgres';
        $_ENV['DB_PORT'] = 'abc';

        try {
            Database::fromEnv(['driver' => 'pgsql']);
            $this->fail('Expected ConnectionException for DB_PORT=abc');
        } catch (ConnectionException $e) {
            $this->assertSame('Invalid config value "port": expected a whole number between 1 and 65535', $e->getDebugMessage());
        }
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
     * fromEnv() reads from $_ENV.
     */
    public function testFactoryReadsConfigFromEnv(): void
    {
        $_ENV['DB_HOST'] = 'invalid-host-that-does-not-exist';
        $_ENV['DB_DATABASE'] = 'testdb';
        $_ENV['DB_USERNAME'] = 'testuser';
        $_ENV['DB_PASSWORD'] = 'testpass';

        $this->expectException(ConnectionException::class);

        Database::fromEnv(['driver' => 'pgsql']);
    }

    /**
     * fromEnv() reads from getenv() as fallback.
     */
    public function testFactoryReadsConfigFromGetenv(): void
    {
        putenv('DB_HOST=getenv-host-invalid');
        putenv('DB_DATABASE=testdb');
        putenv('DB_USERNAME=testuser');

        $this->expectException(ConnectionException::class);

        try {
            Database::fromEnv(['driver' => 'pgsql']);
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('getenv-host-invalid', $e->getDebugMessage() ?? '');
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
            Database::fromEnv(['driver' => 'pgsql']);
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('env-host-invalid', $e->getDebugMessage() ?? '');
            $this->assertStringNotContainsString('getenv-host', $e->getDebugMessage() ?? '');
            throw $e;
        }
    }

    public function testTheFactoryIgnoresTheEnvironmentAndFromEnvLetsOverridesWin(): void
    {
        $_ENV['DB_HOST'] = 'env-host';
        $_ENV['DB_DATABASE'] = 'env-db';
        $_ENV['DB_USERNAME'] = 'env-user';

        // the factory takes what it is given: nothing is filled in from the environment
        try {
            Database::postgres(['database' => 'array-db', 'username' => 'array-user']);
            $this->fail('Expected ConnectionException: no host was passed');
        } catch (ConnectionException $e) {
            $this->assertSame('Missing required config: host, database, or username', $e->getDebugMessage());
        }

        // fromEnv(): what is passed counts instead of the variable ...
        try {
            Database::fromEnv(['driver' => 'pgsql', 'host' => 'array-host']);
            $this->fail('Expected ConnectionException: no such host');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('array-host', (string) $e->getDebugMessage());
            $this->assertStringNotContainsString('env-host', (string) $e->getDebugMessage());
        }

        // ... also null: an explicit null is a missing value, not "ask the environment"
        try {
            Database::fromEnv(['driver' => 'pgsql', 'host' => null]);
            $this->fail('Expected ConnectionException: the host was passed as null');
        } catch (ConnectionException $e) {
            $this->assertSame('Missing required config: host, database, or username', $e->getDebugMessage());
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
            $this->assertStringContainsString(':5432', $e->getDebugMessage() ?? '');
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
            $this->assertStringContainsString(':5433', $e->getDebugMessage() ?? '');
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
            Database::fromEnv(['driver' => 'pgsql']);
        } catch (ConnectionException $e) {
            $this->assertStringContainsString(':5434', $e->getDebugMessage() ?? '');
            throw $e;
        }
    }
}
