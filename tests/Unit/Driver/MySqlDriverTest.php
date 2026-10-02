<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;

class MySqlDriverTest extends TestCase
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
     * A ";" in a DSN value appends further keys (a later host=/port= would redirect the connection),
     * NUL truncates the DSN. Both are rejected before any connection attempt, without echoing the value.
     */
    public function testRejectsSemicolonAndNulInConnectionValuesBeforeConnecting(): void
    {
        $base = ['host' => '127.0.0.1', 'database' => 'app', 'username' => 'root', 'password' => 'x'];
        $cases = [
            ['host', '127.0.0.1;port=3307'],
            ['database', 'app;host=evil'],
            ['charset', 'utf8mb4;host=evil'],
            ['host', "127.0.0.1\0"],
            ['database', "app\0"],
            ['charset', "utf8mb4\0"],
        ];

        foreach ($cases as [$key, $value]) {
            try {
                new MySqlDriver([$key => $value] + $base);
                $this->fail("Expected ConnectionException for {$key}");
            } catch (ConnectionException $e) {
                $this->assertSame('Database connection failed', $e->getMessage());
                $this->assertSame(sprintf('Invalid character in config value "%s"', $key), $e->getDebugMessage());
            }
        }
    }

    /**
     * The DSN is built with %d: "abc" would become port 0 and "3306;host=evil" 3306 without a word.
     */
    public function testRejectsAPortThatIsNotAWholeNumberInRange(): void
    {
        $base = ['host' => '127.0.0.1', 'database' => 'app', 'username' => 'root', 'password' => 'x'];

        foreach (['abc', '3306;host=evil', '', 0, -1, 65536, 3306.5, true] as $port) {
            try {
                new MySqlDriver(['port' => $port] + $base);
                $this->fail('Expected ConnectionException for port ' . var_export($port, true));
            } catch (ConnectionException $e) {
                $this->assertSame('Invalid config value "port": expected a whole number between 1 and 65535', $e->getDebugMessage());
            }
        }
    }

    /**
     * DB_PORT goes through the same check: "abc" no longer falls back to the default silently, and
     * "1e3" or "1.9" are no longer cut down to a number.
     */
    public function testAnInvalidPortFromTheEnvironmentIsRejected(): void
    {
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_DATABASE'] = 'test';
        $_ENV['DB_USERNAME'] = 'root';

        foreach (['abc', '1e3', '1.9', '70000', '65536', '0', '-1', '33 06'] as $port) {
            $_ENV['DB_PORT'] = $port;
            try {
                Database::fromEnv(['driver' => 'mysql']);
                $this->fail("Expected ConnectionException for DB_PORT={$port}");
            } catch (ConnectionException $e) {
                $this->assertSame('Invalid config value "port": expected a whole number between 1 and 65535', $e->getDebugMessage());
            }
        }

        // An empty value means "not set": the default port. Surrounding whitespace (a trailing CR
        // from an .env file) is not part of the value.
        foreach (['' => 3306, '   ' => 3306, " 59998\r\n" => 59998] as $value => $expected) {
            $_ENV['DB_PORT'] = $value;
            try {
                Database::fromEnv(['driver' => 'mysql', 'host' => '127.0.0.1', 'password' => 'wrong-on-purpose']);
                $this->fail('Expected ConnectionException: wrong password or nothing listening');
            } catch (ConnectionException $e) {
                $this->assertStringContainsString("MySQL connection to 127.0.0.1:{$expected} failed", (string) $e->getDebugMessage());
            }
        }

        // A port given in the config is judged the same way
        foreach ([0, '0', '', ' 3306'] as $port) {
            try {
                Database::mysql(['host' => '127.0.0.1', 'port' => $port]);
                $this->fail('Expected ConnectionException for port ' . var_export($port, true));
            } catch (ConnectionException $e) {
                $this->assertSame('Invalid config value "port": expected a whole number between 1 and 65535', $e->getDebugMessage());
            }
        }
    }

    public function testAcceptsANumericStringAsPort(): void
    {
        try {
            new MySqlDriver(['host' => '127.0.0.1', 'port' => '59999', 'database' => 'app', 'username' => 'root', 'password' => 'x']);
            $this->fail('Expected ConnectionException: nothing listens there');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('MySQL connection to 127.0.0.1:59999 failed', (string) $e->getDebugMessage());
        }
    }

    public function testThrowsExceptionWhenHostMissing(): void
    {
        $this->expectException(ConnectionException::class);

        new MySqlDriver([
            'database' => 'test',
            'username' => 'root',
        ]);
    }

    public function testThrowsExceptionWhenDatabaseMissing(): void
    {
        $this->expectException(ConnectionException::class);

        new MySqlDriver([
            'host' => 'localhost',
            'username' => 'root',
        ]);
    }

    public function testThrowsExceptionWhenUsernameMissing(): void
    {
        $this->expectException(ConnectionException::class);

        new MySqlDriver([
            'host' => 'localhost',
            'database' => 'test',
        ]);
    }

    public function testExceptionHasDebugMessage(): void
    {
        try {
            new MySqlDriver([]);
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

        Database::fromEnv(['driver' => 'mysql']);
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
            Database::fromEnv(['driver' => 'mysql']);
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
            Database::fromEnv(['driver' => 'mysql']);
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('env-host-invalid', $e->getDebugMessage());
            $this->assertStringNotContainsString('getenv-host', $e->getDebugMessage());
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
            Database::mysql(['database' => 'array-db', 'username' => 'array-user']);
            $this->fail('Expected ConnectionException: no host was passed');
        } catch (ConnectionException $e) {
            $this->assertSame('Missing required config: host, database, or username', $e->getDebugMessage());
        }

        // fromEnv(): what is passed counts instead of the variable ...
        try {
            Database::fromEnv(['driver' => 'mysql', 'host' => 'array-host']);
            $this->fail('Expected ConnectionException: no such host');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('array-host', (string) $e->getDebugMessage());
            $this->assertStringNotContainsString('env-host', (string) $e->getDebugMessage());
        }

        // ... also null: an explicit null is a missing value, not "ask the environment"
        try {
            Database::fromEnv(['driver' => 'mysql', 'host' => null]);
            $this->fail('Expected ConnectionException: the host was passed as null');
        } catch (ConnectionException $e) {
            $this->assertSame('Missing required config: host, database, or username', $e->getDebugMessage());
        }
    }

    public function testDefaultPortIs3306(): void
    {
        $this->expectException(ConnectionException::class);

        try {
            new MySqlDriver([
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'root',
            ]);
        } catch (ConnectionException $e) {
            $this->assertStringContainsString(':3306', $e->getDebugMessage());
            throw $e;
        }
    }

    public function testCustomPortFromConfig(): void
    {
        $this->expectException(ConnectionException::class);

        try {
            new MySqlDriver([
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'root',
                'port' => 3307,
            ]);
        } catch (ConnectionException $e) {
            $this->assertStringContainsString(':3307', $e->getDebugMessage());
            throw $e;
        }
    }

    public function testAPortThatIsPassedBeatsDbPort(): void
    {
        $_ENV['DB_HOST'] = 'localhost';
        $_ENV['DB_DATABASE'] = 'test';
        $_ENV['DB_USERNAME'] = 'root';
        $_ENV['DB_PORT'] = '3308';

        try {
            Database::fromEnv(['driver' => 'mysql', 'port' => 59997, 'password' => 'wrong-on-purpose']);
            $this->fail('Expected ConnectionException: nothing listens there');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString(':59997', (string) $e->getDebugMessage());
        }
    }

    public function testCustomPortFromEnv(): void
    {
        $_ENV['DB_HOST'] = 'localhost';
        $_ENV['DB_DATABASE'] = 'test';
        $_ENV['DB_USERNAME'] = 'root';
        $_ENV['DB_PORT'] = '3308';

        $this->expectException(ConnectionException::class);

        try {
            Database::fromEnv(['driver' => 'mysql']);
        } catch (ConnectionException $e) {
            $this->assertStringContainsString(':3308', $e->getDebugMessage());
            throw $e;
        }
    }
}
