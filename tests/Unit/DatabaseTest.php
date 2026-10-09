<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Tests\Support\AbstractPdo;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;
use Sodaho\PdoWrapper\Tests\Support\Untyped;

/**
 * The factories without a database: which driver they pick, what they read and refuse. A
 * connection attempt goes to a port nothing listens on: it fails as the driver it was meant for.
 */
class DatabaseTest extends TestCase
{
    /** Where nothing listens: an attempt to connect fails there */
    private const NOTHING_LISTENS = ['host' => '127.0.0.1', 'port' => 59996, 'database' => 'app', 'username' => 'root', 'password' => 'x'];

    /** @var array<string, array{env: mixed, process: string|false}> */
    private array $savedEnvironment = [];

    /**
     * fromEnv() reads DB_* from $_ENV and getenv(): both channels are cleared for the tests and
     * restored afterwards.
     */
    protected function setUp(): void
    {
        foreach (['DB_DRIVER', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PORT'] as $key) {
            $this->savedEnvironment[$key] = ['env' => $_ENV[$key] ?? null, 'process' => getenv($key)];
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $saved) {
            unset($_ENV[$key]);
            if ($saved['env'] !== null) {
                $_ENV[$key] = $saved['env'];
            }
            putenv($saved['process'] === false ? $key : "{$key}={$saved['process']}");
        }
    }

    public function testConnectAndFromEnvPickTheDriverByName(): void
    {
        foreach (['mariadb', ' MariaDB ', 'MARIADB'] as $driver) {
            $this->assertConnectionAttempt(static fn (): DatabaseInterface => Database::connect(['driver' => $driver] + self::NOTHING_LISTENS), $driver);
        }

        $_ENV['DB_DRIVER'] = ' MariaDB ';
        $this->assertConnectionAttempt(static fn (): DatabaseInterface => Database::fromEnv(self::NOTHING_LISTENS), '$_ENV');

        // getenv() is the second channel
        unset($_ENV['DB_DRIVER']);
        putenv('DB_DRIVER=mariadb');
        $this->assertConnectionAttempt(static fn (): DatabaseInterface => Database::fromEnv(self::NOTHING_LISTENS), 'getenv()');
    }

    /**
     * The driver was called "mysql" before 3.0, and MySQL servers are not supported any more: the
     * old name says so instead of connecting as MariaDB.
     */
    public function testTheOldDriverNameSaysWhatItIsCalledNow(): void
    {
        foreach (['connect()' => static fn (): DatabaseInterface => Database::connect(['driver' => ' MySQL '] + self::NOTHING_LISTENS), 'fromEnv()' => static function (): DatabaseInterface {
            $_ENV['DB_DRIVER'] = 'mysql';

            return Database::fromEnv(self::NOTHING_LISTENS);
        }] as $how => $connect) {
            try {
                $connect();
                $this->fail('Expected ConnectionException in ' . $how);
            } catch (ConnectionException $e) {
                $this->assertSame('The driver "mysql" is called "mariadb" since 3.0, and MySQL servers are not supported: this library supports MariaDB only', $e->getDebugMessage(), $how);
                $this->assertNull($e->getPrevious(), $how . ': nothing was tried');
            }
        }
    }

    /**
     * The drivers for PostgreSQL and SQLite are gone with 3.0: their names say so instead of
     * "unknown".
     */
    public function testARemovedDriverSaysItWasRemoved(): void
    {
        foreach (['sqlite', 'pgsql', ' Postgres ', 'postgresql'] as $driver) {
            foreach ([
                'connect()' => static fn (): DatabaseInterface => Database::connect(['driver' => $driver, 'path' => ':memory:']),
                'fromEnv()' => static function () use ($driver): DatabaseInterface {
                    $_ENV['DB_DRIVER'] = $driver;

                    return Database::fromEnv();
                },
            ] as $how => $connect) {
                try {
                    $connect();
                    $this->fail("Expected ConnectionException for {$driver} in {$how}");
                } catch (ConnectionException $e) {
                    $this->assertSame('Database connection failed', $e->getMessage());
                    $this->assertSame(sprintf('The driver "%s" was removed in 3.0: this library supports MariaDB only', strtolower(trim($driver))), $e->getDebugMessage(), $how);
                }
            }
        }
    }

    /**
     * The factories take what they are given. Whatever the environment says, nothing of it is
     * filled in: that is fromEnv()'s job alone.
     */
    public function testTheFactoriesDoNotReadTheEnvironment(): void
    {
        $_ENV['DB_DRIVER'] = 'mariadb';
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_DATABASE'] = 'app';
        $_ENV['DB_USERNAME'] = 'app';

        try {
            Database::connect([]);
            $this->fail('Expected ConnectionException: no driver was passed');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('No database driver given', (string) $e->getDebugMessage());
        }
        $this->assertMissingConfig(static fn (): MariaDbDriver => Database::mariadb([]));
        $this->assertMissingConfig(static fn (): DatabaseInterface => Database::connect(['driver' => 'mariadb']));
    }

    /**
     * What fromEnv() is handed counts instead of the environment, null and empty included.
     */
    public function testFromEnvLetsOverridesWin(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_DATABASE'] = 'app';
        $_ENV['DB_USERNAME'] = 'app';

        // the driver that was passed beats DB_DRIVER, a null that was passed beats DB_DATABASE
        $this->assertMissingConfig(static fn (): DatabaseInterface => Database::fromEnv(['driver' => 'mariadb', 'database' => null]));
        $this->assertConnectionAttempt(static fn (): DatabaseInterface => Database::fromEnv(['driver' => 'mariadb'] + self::NOTHING_LISTENS), 'everything passed');
    }

    public function testConnectRejectsMissingAndUnknownDrivers(): void
    {
        try {
            Database::connect([]);
            $this->fail('Expected ConnectionException without a driver');
        } catch (ConnectionException $e) {
            $this->assertSame('No database driver given: pass \'driver\' (mariadb), or set DB_DRIVER for fromEnv()', $e->getDebugMessage());
        }

        try {
            Database::fromEnv();
            $this->fail('Expected ConnectionException without DB_DRIVER');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('No database driver given', (string) $e->getDebugMessage());
        }

        try {
            Database::connect(['driver' => 'oracle']);
            $this->fail('Expected ConnectionException for an unknown driver');
        } catch (ConnectionException $e) {
            $this->assertSame('Unknown database driver "oracle": use mariadb', $e->getDebugMessage());
        }
    }

    /**
     * `DB_HOST=` in a dotenv template: set, but empty. It counts as not set - the first channel that
     * has a value decides, so the process environment answers; a required value without one is then
     * reported as missing instead of connecting with an empty one, an optional one takes its
     * default. The same for a value that is no scalar.
     */
    public function testAnEmptyEnvironmentVariableCountsAsNotSet(): void
    {
        // $_ENV
        $_ENV['DB_HOST'] = '';
        $_ENV['DB_DATABASE'] = 'app';
        $_ENV['DB_USERNAME'] = 'app';
        $this->assertMissingConfig(static fn (): DatabaseInterface => Database::fromEnv(['driver' => 'mariadb']));

        $_ENV['DB_HOST'] = ['127.0.0.1'];
        $this->assertMissingConfig(static fn (): \Sodaho\PdoWrapper\DatabaseInterface => Database::fromEnv(['driver' => 'mariadb']));

        // empty, or no scalar, in $_ENV: no value there, getenv() is asked
        foreach (['', ['127.0.0.1']] as $nothing) {
            $_ENV['DB_HOST'] = $nothing;
            putenv('DB_HOST=127.0.0.1');
            $_ENV['DB_PORT'] = '59996';
            $this->assertConnectionAttempt(static fn (): DatabaseInterface => Database::fromEnv(['driver' => 'mariadb']), 'the host from getenv()');
            putenv('DB_HOST');
            unset($_ENV['DB_PORT']);
        }

        // null in $_ENV is no value (as before): getenv() is asked
        $_ENV['DB_HOST'] = null;
        putenv('DB_HOST=127.0.0.1');
        $_ENV['DB_PORT'] = '59996';
        $this->assertConnectionAttempt(static fn (): DatabaseInterface => Database::fromEnv(['driver' => 'mariadb']), 'the host from getenv()');
        putenv('DB_HOST');
        unset($_ENV['DB_PORT']);

        // getenv()
        unset($_ENV['DB_HOST'], $_ENV['DB_DRIVER']);
        putenv('DB_HOST=');
        $this->assertMissingConfig(static fn (): DatabaseInterface => Database::fromEnv(['driver' => 'mariadb']));
        putenv('DB_DRIVER=');
        try {
            Database::fromEnv();
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('No database driver given', (string) $e->getDebugMessage());
        }
    }

    /**
     * The attempt reached the MySQL/MariaDB driver's connection: the driver was picked, and the
     * values got through to it.
     *
     * @param callable(): DatabaseInterface $connect
     */
    private function assertConnectionAttempt(callable $connect, string $message): void
    {
        try {
            $connect();
            $this->fail('Expected ConnectionException: nothing listens there - ' . $message);
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:59996 failed', (string) $e->getDebugMessage(), $message);
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious(), $message);
        }
    }

    private function assertMissingConfig(callable $connect): void
    {
        try {
            $connect();
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('Missing required config: host, database, or username', $e->getDebugMessage());
        }
    }

    /**
     * The config carries the password: PHP keeps a parameter marked sensitive out of stack traces.
     */
    public function testTheConfigParametersAreMarkedSensitive(): void
    {
        $parameters = [
            new \ReflectionParameter([Database::class, 'mariadb'], 'config'),
            new \ReflectionParameter([Database::class, 'connect'], 'config'),
            new \ReflectionParameter([Database::class, 'fromEnv'], 'overrides'),
            new \ReflectionParameter([MariaDbDriver::class, '__construct'], 'config'),
        ];

        foreach ($parameters as $parameter) {
            $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class), $parameter->getDeclaringFunction()->getName());
        }

        try {
            new MariaDbDriver(['host' => 'h', 'database' => 'd', 'username' => 'u', 'password' => 'secret', 'port' => 'abc']);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringNotContainsString('secret', var_export($e->getTrace(), true));
        }
    }

    /**
     * 'pdoClass' is used with new: it is checked first, in every factory. What is no class that
     * extends PDO and can be created is a ConnectionException that names the key - never the
     * value - instead of an Error from new, or an object the driver cannot use.
     */
    public function testAPdoClassThatCannotStandInForPdoIsRejectedByEveryFactory(): void
    {
        $server = ['host' => '127.0.0.1', 'database' => 'app', 'username' => 'root'];
        $invalid = [
            'a class that is no PDO' => \stdClass::class,
            'a name without a class' => 'No\\Such\\PdoClass',
            'an empty name' => '',
            'another class of PDO' => \PDOStatement::class,
            'a class that cannot be created' => AbstractPdo::class,
        ];

        foreach ($invalid as $what => $class) {
            $_ENV['DB_DRIVER'] = 'mariadb';
            $calls = [
                'mariadb()' => static fn (): mixed => Untyped::call(Database::mariadb(...), $server + ['pdoClass' => $class]),
                'new MariaDbDriver()' => static fn (): mixed => Untyped::create(MariaDbDriver::class, $server + ['pdoClass' => $class]),
                'connect()' => static fn (): mixed => Untyped::call(Database::connect(...), ['driver' => 'mariadb'] + $server + ['pdoClass' => $class]),
                'fromEnv() with everything passed' => static fn (): mixed => Untyped::call(Database::fromEnv(...), ['driver' => 'mariadb'] + $server + ['pdoClass' => $class]),
                'fromEnv() with DB_DRIVER' => static fn (): mixed => Untyped::call(Database::fromEnv(...), $server + ['pdoClass' => $class]),
            ];

            foreach ($calls as $how => $call) {
                try {
                    $call();
                    $this->fail("Expected ConnectionException for {$what} in {$how}");
                } catch (ConnectionException $e) {
                    $this->assertSame('Database connection failed', $e->getMessage(), "{$what} in {$how}");
                    $this->assertSame(
                        'Invalid config value "pdoClass": expected the name of a class that extends PDO and can be instantiated',
                        $e->getDebugMessage(),
                        "{$what} in {$how}: the key, not the value"
                    );
                    $this->assertNull($e->getPrevious(), "{$what} in {$how}: refused before anything was tried");
                }
            }
        }
    }

    /**
     * In a config array the value can be anything: what is no string is refused the same way -
     * also a PDO object. The class is named; a connection that exists is not taken over.
     */
    public function testAPdoClassThatIsNoStringIsRejected(): void
    {
        $server = ['host' => '127.0.0.1', 'database' => 'app', 'username' => 'root'];
        $pdoObject = (new \ReflectionClass(PDO::class))->newInstanceWithoutConstructor();

        foreach ([123, true, 1.5, ['PDO'], new \stdClass(), $pdoObject] as $class) {
            foreach ([
                'new MariaDbDriver()' => static fn (): mixed => Untyped::create(MariaDbDriver::class, $server + ['pdoClass' => $class]),
                'connect()' => static fn (): mixed => Untyped::call(Database::connect(...), ['driver' => 'mariadb'] + $server + ['pdoClass' => $class]),
            ] as $how => $call) {
                try {
                    $call();
                    $this->fail("Expected ConnectionException for {$how}, " . get_debug_type($class));
                } catch (ConnectionException $e) {
                    $this->assertSame(
                        'Invalid config value "pdoClass": expected the name of a class that extends PDO and can be instantiated',
                        $e->getDebugMessage(),
                        $how . ', ' . get_debug_type($class)
                    );
                }
            }
        }
    }

    /**
     * The check lets through what it should: a class that extends PDO reaches the connection
     * attempt, and so does PDO itself by name, also with a leading backslash.
     */
    public function testAValidPdoClassReachesTheConnectionAttempt(): void
    {
        foreach ([ScenarioPdo::class, PDO::class, '\\PDO'] as $class) {
            $this->assertConnectionAttempt(static fn (): DatabaseInterface => Untyped::create(MariaDbDriver::class, self::NOTHING_LISTENS + ['pdoClass' => $class]), $class);
        }
    }
}
