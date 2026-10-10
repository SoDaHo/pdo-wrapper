<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Tests\Support\DsnRefusingPdo;
use Sodaho\PdoWrapper\Tests\Support\Untyped;

/**
 * The driver's configuration, without a database: what is refused before connecting, and which values
 * reach the connection - read from the failed attempt's message and the DSN. Every attempt goes to a
 * PDO class that keeps the DSN and refuses the connection (DsnRefusingPdo): nothing is opened, so
 * whatever listens on a port of the machine - a server on 3306 that lets an unknown user in
 * included - cannot answer.
 */
class MariaDbDriverTest extends TestCase
{
    /** A user no test server knows: an attempt fails whatever listens on the port */
    private const NOBODY = 'pdo_wrapper_nobody';

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
                new MariaDbDriver([$key => $value] + $base);
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

        foreach (['abc', '3306;host=evil', '', 0, -1, 65536, 3306.5, true, "3306\n"] as $port) {
            try {
                Untyped::create(MariaDbDriver::class, ['port' => $port] + $base); // not all of them are a port by type
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
                Database::fromEnv(['driver' => 'mariadb', 'pdoClass' => DsnRefusingPdo::class]);
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
                Database::fromEnv(['driver' => 'mariadb', 'pdoClass' => DsnRefusingPdo::class, 'host' => '127.0.0.1', 'password' => 'wrong-on-purpose']);
                $this->fail('Expected ConnectionException: wrong password or nothing listening');
            } catch (ConnectionException $e) {
                $this->assertStringContainsString("MariaDB connection to 127.0.0.1:{$expected} failed", (string) $e->getDebugMessage());
                $this->assertStringContainsString(";port={$expected};", (string) DsnRefusingPdo::$dsn);
            }
        }

        // A port given in the config is judged the same way
        foreach ([0, '0', '', ' 3306'] as $port) {
            try {
                Database::mariadb(['host' => '127.0.0.1', 'port' => $port]);
                $this->fail('Expected ConnectionException for port ' . var_export($port, true));
            } catch (ConnectionException $e) {
                $this->assertSame('Invalid config value "port": expected a whole number between 1 and 65535', $e->getDebugMessage());
            }
        }
    }

    public function testAcceptsANumericStringAsPort(): void
    {
        try {
            new MariaDbDriver(['host' => '127.0.0.1', 'port' => '59999', 'database' => 'app', 'username' => 'root', 'password' => 'x', 'pdoClass' => DsnRefusingPdo::class]);
            $this->fail('Expected ConnectionException: nothing listens there');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('MariaDB connection to 127.0.0.1:59999 failed', (string) $e->getDebugMessage());
        }
    }

    public function testThrowsExceptionWhenHostMissing(): void
    {
        $this->expectException(ConnectionException::class);

        new MariaDbDriver([
            'database' => 'test',
            'username' => 'root',
        ]);
    }

    public function testThrowsExceptionWhenDatabaseMissing(): void
    {
        $this->expectException(ConnectionException::class);

        new MariaDbDriver([
            'host' => 'localhost',
            'username' => 'root',
        ]);
    }

    public function testThrowsExceptionWhenUsernameMissing(): void
    {
        $this->expectException(ConnectionException::class);

        new MariaDbDriver([
            'host' => 'localhost',
            'database' => 'test',
        ]);
    }

    /**
     * An empty host, database or username counts as missing - pdo_mysql would take them for the
     * local socket, no database and an anonymous login. An empty password is a password: the
     * connection is attempted (here against a port nothing listens on).
     */
    public function testAnEmptyHostDatabaseOrUsernameCountsAsMissing(): void
    {
        $config = ['host' => '127.0.0.1', 'port' => 59999, 'database' => 'test', 'username' => 'root', 'password' => '', 'pdoClass' => DsnRefusingPdo::class];
        foreach (['host', 'database', 'username'] as $key) {
            try {
                new MariaDbDriver([$key => ''] + $config);
                $this->fail('Expected ConnectionException: ' . $key);
            } catch (ConnectionException $e) {
                $this->assertSame('Missing required config: host, database, or username', $e->getDebugMessage(), $key);
                $this->assertNull($e->getPrevious(), $key . ': refused before connecting');
            }
        }

        try {
            new MariaDbDriver($config);
            $this->fail('Expected ConnectionException: nothing listens on the port');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:59999 failed', (string) $e->getDebugMessage());
        }
    }

    /**
     * In a config array a value can be of any type. One that is not what its key takes - no string
     * for a text, no array of PDO attributes for the options, no string for the driver's name - is
     * refused before anything is cast, built or tried, by every factory: a ConnectionException that
     * names the key and never the value, not a TypeError and not an int cast into the DSN. null
     * stays "not set": the default for charset and options.
     */
    public function testAValueOfTheWrongTypeIsRefusedByItsKey(): void
    {
        $base = ['host' => '127.0.0.1', 'port' => 59994, 'database' => 'app', 'username' => self::NOBODY, 'password' => 'x', 'pdoClass' => DsnRefusingPdo::class];
        $text = 'expected a string';
        $options = 'expected an array of PDO attributes (PDO::ATTR_* => value)';
        foreach ([
            ['host', 7, $text], ['host', ['127.0.0.1'], $text], ['database', 1.5, $text], ['username', 7, $text],
            ['password', 4711, $text], ['password', new \stdClass(), $text], ['charset', ['utf8mb4'], $text],
            ['options', 'bad', $options], ['options', 5, $options], ['options', ['ATTR_TIMEOUT' => 5], $options],
        ] as [$key, $value, $expected]) {
            foreach ([
                'new MariaDbDriver()' => static fn (): mixed => Untyped::create(MariaDbDriver::class, [$key => $value] + $base),
                'connect()' => static fn (): mixed => Untyped::call(Database::connect(...), ['driver' => 'mariadb', $key => $value] + $base),
            ] as $how => $call) {
                DsnRefusingPdo::$dsn = null;
                try {
                    $call();
                    $this->fail("Expected ConnectionException: {$key}, {$how}");
                } catch (ConnectionException $e) {
                    $this->assertSame('Database connection failed', $e->getMessage());
                    $this->assertSame(sprintf('Invalid config value "%s": %s', $key, $expected), $e->getDebugMessage(), "{$key}, {$how}");
                    $this->assertNull($e->getPrevious(), "{$key}, {$how}: nothing was tried");
                    $this->assertNull(DsnRefusingPdo::$dsn, "{$key}, {$how}: no DSN was built");
                }
            }
        }

        foreach ([[], 7, new \stdClass()] as $driver) {
            try {
                Untyped::call(Database::connect(...), ['driver' => $driver] + $base);
                $this->fail('Expected ConnectionException: driver ' . get_debug_type($driver));
            } catch (ConnectionException $e) {
                $this->assertSame('Invalid config value "driver": expected a string (mariadb)', $e->getDebugMessage());
            }
        }

        try {
            Untyped::create(MariaDbDriver::class, ['charset' => null, 'options' => null] + $base);
            $this->fail('Expected ConnectionException: nothing listens on the port');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:59994 failed', (string) $e->getDebugMessage());
            $this->assertSame('mysql:host=127.0.0.1;port=59994;dbname=app;charset=utf8mb4', DsnRefusingPdo::$dsn, 'null is the default');
        }
    }

    public function testExceptionHasDebugMessage(): void
    {
        try {
            new MariaDbDriver([]);
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
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_PORT'] = '59991';
        $_ENV['DB_DATABASE'] = 'testdb';
        $_ENV['DB_USERNAME'] = self::NOBODY;
        $_ENV['DB_PASSWORD'] = 'testpass';

        try {
            Database::fromEnv(['driver' => 'mariadb', 'pdoClass' => DsnRefusingPdo::class]);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:59991 failed', (string) $e->getDebugMessage());
        }
    }

    /**
     * fromEnv() reads from getenv() as fallback.
     */
    public function testFactoryReadsConfigFromGetenv(): void
    {
        putenv('DB_HOST=127.0.0.1');
        putenv('DB_PORT=59992');
        putenv('DB_DATABASE=testdb');
        putenv('DB_USERNAME=' . self::NOBODY);

        try {
            Database::fromEnv(['driver' => 'mariadb', 'pdoClass' => DsnRefusingPdo::class]);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:59992 failed', (string) $e->getDebugMessage());
        }
    }

    /**
     * Test that $_ENV has priority over getenv().
     */
    public function testEnvHasPriorityOverGetenv(): void
    {
        $_ENV['DB_HOST'] = '127.0.0.1';
        putenv('DB_HOST=getenv-host-should-not-be-used');
        $_ENV['DB_PORT'] = '59993';
        $_ENV['DB_DATABASE'] = 'testdb';
        $_ENV['DB_USERNAME'] = self::NOBODY;

        try {
            Database::fromEnv(['driver' => 'mariadb', 'pdoClass' => DsnRefusingPdo::class]);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:59993 failed', (string) $e->getDebugMessage());
            $this->assertStringNotContainsString('getenv-host', (string) $e->getDebugMessage());
        }
    }

    /**
     * The first channel that has a value decides: an empty value in $_ENV (DB_HOST= in a dotenv
     * template) has none, and the process environment answers.
     */
    public function testAnEmptyEnvValueLeavesTheProcessEnvironmentToAnswer(): void
    {
        $_ENV['DB_HOST'] = '';
        putenv('DB_HOST=127.0.0.1');
        $_ENV['DB_PORT'] = '59999';
        $_ENV['DB_DATABASE'] = 'testdb';
        $_ENV['DB_USERNAME'] = self::NOBODY;

        try {
            Database::fromEnv(['driver' => 'mariadb', 'pdoClass' => DsnRefusingPdo::class]);
            $this->fail('Expected ConnectionException: nothing listens on the port');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:59999 failed', (string) $e->getDebugMessage());
        }
    }

    public function testTheFactoryIgnoresTheEnvironmentAndFromEnvLetsOverridesWin(): void
    {
        $_ENV['DB_HOST'] = 'env-host';
        $_ENV['DB_DATABASE'] = 'env-db';
        $_ENV['DB_USERNAME'] = 'env-user';

        // the factory takes what it is given: nothing is filled in from the environment
        try {
            Database::mariadb(['database' => 'array-db', 'username' => 'array-user']);
            $this->fail('Expected ConnectionException: no host was passed');
        } catch (ConnectionException $e) {
            $this->assertSame('Missing required config: host, database, or username', $e->getDebugMessage());
        }

        // fromEnv(): what is passed counts instead of the variable ...
        try {
            Database::fromEnv(['driver' => 'mariadb', 'pdoClass' => DsnRefusingPdo::class, 'host' => '127.0.0.1', 'port' => 59994, 'username' => self::NOBODY]);
            $this->fail('Expected ConnectionException: nothing listens on the port');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:59994 failed', (string) $e->getDebugMessage());
            $this->assertStringNotContainsString('env-host', (string) $e->getDebugMessage());
        }

        // ... also null: an explicit null is a missing value, not "ask the environment"
        try {
            Database::fromEnv(['driver' => 'mariadb', 'pdoClass' => DsnRefusingPdo::class, 'host' => null]);
            $this->fail('Expected ConnectionException: the host was passed as null');
        } catch (ConnectionException $e) {
            $this->assertSame('Missing required config: host, database, or username', $e->getDebugMessage());
        }
    }

    /**
     * The default port reaches the DSN - read from a PDO class that refuses every connection: a
     * server that listens on 3306 (and lets an unknown user in, as anonymous accounts do) cannot
     * answer the test.
     */
    public function testDefaultPortIs3306(): void
    {
        try {
            new MariaDbDriver([
                'host' => '127.0.0.1',
                'database' => 'test',
                'username' => self::NOBODY,
                'pdoClass' => DsnRefusingPdo::class,
            ]);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:3306 failed', (string) $e->getDebugMessage());
            $this->assertSame('mysql:host=127.0.0.1;port=3306;dbname=test;charset=utf8mb4', DsnRefusingPdo::$dsn);
        }
    }

    public function testCustomPortFromConfig(): void
    {
        try {
            new MariaDbDriver([
                'host' => '127.0.0.1',
                'database' => 'test',
                'username' => self::NOBODY,
                'port' => 59994,
                'pdoClass' => DsnRefusingPdo::class,
            ]);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:59994 failed', (string) $e->getDebugMessage());
        }
    }

    public function testAPortThatIsPassedBeatsDbPort(): void
    {
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_DATABASE'] = 'test';
        $_ENV['DB_USERNAME'] = self::NOBODY;
        $_ENV['DB_PORT'] = '59996';

        try {
            Database::fromEnv(['driver' => 'mariadb', 'pdoClass' => DsnRefusingPdo::class, 'port' => 59997, 'password' => 'wrong-on-purpose']);
            $this->fail('Expected ConnectionException: nothing listens there');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString(':59997', (string) $e->getDebugMessage());
            $this->assertSame(['HY000', 2002, 0], [$e->sqlState, $e->driverCode, $e->getCode()], 'a failed connection has the codes PDO reported');
        }
    }

    public function testCustomPortFromEnv(): void
    {
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_DATABASE'] = 'test';
        $_ENV['DB_USERNAME'] = self::NOBODY;
        $_ENV['DB_PORT'] = '59996';

        try {
            Database::fromEnv(['driver' => 'mariadb', 'pdoClass' => DsnRefusingPdo::class]);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:59996 failed', (string) $e->getDebugMessage());
        }
    }
}
