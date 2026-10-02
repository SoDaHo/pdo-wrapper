<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;

class DatabaseTest extends TestCase
{
    /** @var array<string, array{env: string|null, process: string|false}> */
    private array $savedEnvironment = [];

    /**
     * fromEnv() reads DB_* from $_ENV and getenv(): both channels are cleared for the tests and
     * restored afterwards.
     */
    protected function setUp(): void
    {
        foreach (['DB_DRIVER', 'DB_SQLITE_PATH', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PORT'] as $key) {
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

    public function testConnectPicksTheDriverFromTheConfig(): void
    {
        $this->assertInstanceOf(SqliteDriver::class, Database::connect(['driver' => 'sqlite']));
        $this->assertInstanceOf(SqliteDriver::class, Database::connect(['driver' => ' SQLite ', 'path' => ':memory:']));
        $this->assertInstanceOf(SqliteDriver::class, Database::connect(['driver' => 'sqlite', 'database' => ':memory:']));

        // 'path' comes before 'database'
        $file = sys_get_temp_dir() . '/pdo-wrapper-connect-' . bin2hex(random_bytes(4)) . '.db';
        $other = $file . '.other';

        try {
            Database::connect(['driver' => 'sqlite', 'database' => $other, 'path' => $file])->execute('CREATE TABLE t (id INTEGER)');
            $this->assertFileExists($file);
            $this->assertFileDoesNotExist($other);
        } finally {
            @unlink($file);
            @unlink($other);
        }
    }

    public function testFromEnvPicksTheDriverAndThePathFromTheEnvironment(): void
    {
        $file = sys_get_temp_dir() . '/pdo-wrapper-fromenv-' . bin2hex(random_bytes(4)) . '.db';
        $_ENV['DB_DRIVER'] = ' SQLite ';
        $_ENV['DB_SQLITE_PATH'] = $file;

        try {
            $db = Database::fromEnv();
            $this->assertInstanceOf(SqliteDriver::class, $db);
            $db->execute('CREATE TABLE t (id INTEGER)');
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
        }

        // without DB_SQLITE_PATH: in memory
        unset($_ENV['DB_SQLITE_PATH']);
        $this->assertSame(1, (int) Database::fromEnv()->query('SELECT 1')->fetchColumn());

        // getenv() is the second channel
        unset($_ENV['DB_DRIVER']);
        putenv('DB_DRIVER=sqlite');
        $this->assertInstanceOf(SqliteDriver::class, Database::fromEnv());
    }

    /**
     * The factories take what they are given. Whatever the environment says, nothing of it is
     * filled in: that is fromEnv()'s job alone.
     */
    public function testTheFactoriesDoNotReadTheEnvironment(): void
    {
        $file = sys_get_temp_dir() . '/pdo-wrapper-ignored-' . bin2hex(random_bytes(4)) . '.db';
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_SQLITE_PATH'] = $file;
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_DATABASE'] = 'app';
        $_ENV['DB_USERNAME'] = 'app';

        try {
            Database::connect([]);
            $this->fail('Expected ConnectionException: no driver was passed');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('No database driver given', (string) $e->getDebugMessage());
        }
        $this->assertMissingConfig(static fn (): MySqlDriver => Database::mysql([]));
        $this->assertMissingConfig(static fn (): PostgresDriver => Database::postgres([]));
        $this->assertMissingConfig(static fn (): \Sodaho\PdoWrapper\DatabaseInterface => Database::connect(['driver' => 'mysql']));

        Database::sqlite()->execute('CREATE TABLE t (id INTEGER)');
        Database::connect(['driver' => 'sqlite'])->execute('CREATE TABLE t (id INTEGER)');
        $this->assertFileDoesNotExist($file, 'in memory: DB_SQLITE_PATH was not read');
    }

    /**
     * What fromEnv() is handed counts instead of the environment, null and empty included, and
     * DB_DATABASE - the name of a server database - never becomes the SQLite file.
     */
    public function testFromEnvLetsOverridesWinAndKeepsDbDatabaseOutOfSqlite(): void
    {
        $serverDatabase = sys_get_temp_dir() . '/pdo-wrapper-server-database-' . bin2hex(random_bytes(4));
        $file = sys_get_temp_dir() . '/pdo-wrapper-override-' . bin2hex(random_bytes(4)) . '.db';
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $serverDatabase;

        try {
            Database::fromEnv()->execute('CREATE TABLE t (id INTEGER)');
            $this->assertFileDoesNotExist($serverDatabase, 'DB_DATABASE is not the SQLite path');

            // 'database' passed by the application is one, as in connect()
            Database::fromEnv(['database' => $file])->execute('CREATE TABLE t (id INTEGER)');
            $this->assertFileExists($file);
            @unlink($file);

            $_ENV['DB_SQLITE_PATH'] = $serverDatabase;
            Database::fromEnv(['path' => $file])->execute('CREATE TABLE t (id INTEGER)');
            $this->assertFileExists($file, "'path' beats DB_SQLITE_PATH");
            $this->assertFileDoesNotExist($serverDatabase);

            // a path passed as null is "no path" (in memory), not "ask the environment"
            @unlink($file);
            $_ENV['DB_SQLITE_PATH'] = $file;
            Database::fromEnv(['path' => null])->execute('CREATE TABLE t (id INTEGER)');
            $this->assertFileDoesNotExist($file);
            $this->assertFileDoesNotExist($serverDatabase, 'and DB_DATABASE does not step in for it');
            Database::fromEnv(['database' => null])->execute('CREATE TABLE t (id INTEGER)');
            $this->assertFileDoesNotExist($file);
            $this->assertFileDoesNotExist($serverDatabase);
            $_ENV['DB_SQLITE_PATH'] = $serverDatabase;

            // an empty path that was passed is an empty path, not "ask the environment"
            try {
                Database::fromEnv(['path' => '']);
                $this->fail('Expected ConnectionException');
            } catch (ConnectionException $e) {
                $this->assertStringStartsWith('SQLite path is empty', (string) $e->getDebugMessage());
            }

            // the driver that was passed beats DB_DRIVER
            $_ENV['DB_HOST'] = '127.0.0.1';
            $_ENV['DB_USERNAME'] = 'app';
            $this->assertMissingConfig(static fn (): \Sodaho\PdoWrapper\DatabaseInterface => Database::fromEnv(['driver' => 'mysql', 'database' => null]));
        } finally {
            @unlink($file);
            @unlink($serverDatabase);
        }
    }

    public function testConnectRejectsMissingAndUnknownDrivers(): void
    {
        try {
            Database::connect([]);
            $this->fail('Expected ConnectionException without a driver');
        } catch (ConnectionException $e) {
            $this->assertSame('No database driver given: pass \'driver\' (mysql, pgsql or sqlite), or set DB_DRIVER for fromEnv()', $e->getDebugMessage());
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
            $this->assertSame('Unknown database driver "oracle": use mysql, pgsql or sqlite', $e->getDebugMessage());
        }
    }

    #[Group('mysql')]
    public function testConnectMysqlByDriverName(): void
    {
        foreach (['mysql', 'mariadb'] as $driver) {
            $this->assertInstanceOf(MySqlDriver::class, Database::connect([
                'driver' => $driver,
                'host' => $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
                'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
                'database' => $_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test',
                'username' => $_ENV['MYSQL_USERNAME'] ?? 'root',
                'password' => $_ENV['MYSQL_PASSWORD'] ?? 'root',
            ]));
        }
    }

    #[Group('postgres')]
    public function testConnectPostgresByDriverName(): void
    {
        foreach (['pgsql', 'postgres', 'postgresql'] as $driver) {
            $this->assertInstanceOf(PostgresDriver::class, Database::connect([
                'driver' => $driver,
                'host' => $_ENV['POSTGRES_HOST'] ?? '127.0.0.1',
                'port' => (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
                'database' => $_ENV['POSTGRES_DATABASE'] ?? 'pdo_wrapper_test',
                'username' => $_ENV['POSTGRES_USERNAME'] ?? 'postgres',
                'password' => $_ENV['POSTGRES_PASSWORD'] ?? 'postgres',
            ]));
        }
    }

    /**
     * Everything from the environment, the password included; a password that is passed - null
     * too - counts instead of DB_PASSWORD.
     */
    #[Group('mysql')]
    public function testFromEnvConnectsToMysqlFromTheEnvironmentAlone(): void
    {
        $_ENV['DB_DRIVER'] = 'mysql';
        $_ENV['DB_HOST'] = $_ENV['MYSQL_HOST'] ?? '127.0.0.1';
        $_ENV['DB_PORT'] = (string) ($_ENV['MYSQL_PORT'] ?? 3306);
        $_ENV['DB_DATABASE'] = $_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test';
        $_ENV['DB_USERNAME'] = $_ENV['MYSQL_USERNAME'] ?? 'root';
        $_ENV['DB_PASSWORD'] = $_ENV['MYSQL_PASSWORD'] ?? 'root';

        $db = Database::fromEnv();
        $this->assertInstanceOf(MySqlDriver::class, $db);
        $this->assertSame(1, (int) $db->query('SELECT 1')->fetchColumn());

        $this->expectException(ConnectionException::class);
        Database::fromEnv(['password' => null]);
    }

    #[Group('postgres')]
    public function testFromEnvConnectsToPostgresFromTheEnvironmentAlone(): void
    {
        $_ENV['DB_DRIVER'] = 'pgsql';
        $_ENV['DB_HOST'] = $_ENV['POSTGRES_HOST'] ?? '127.0.0.1';
        $_ENV['DB_PORT'] = (string) ($_ENV['POSTGRES_PORT'] ?? 5432);
        $_ENV['DB_DATABASE'] = $_ENV['POSTGRES_DATABASE'] ?? 'pdo_wrapper_test';
        $_ENV['DB_USERNAME'] = $_ENV['POSTGRES_USERNAME'] ?? 'postgres';
        $_ENV['DB_PASSWORD'] = $_ENV['POSTGRES_PASSWORD'] ?? 'postgres';

        $db = Database::fromEnv();
        $this->assertInstanceOf(PostgresDriver::class, $db);
        $this->assertSame(1, (int) $db->query('SELECT 1')->fetchColumn());

        $this->expectException(ConnectionException::class);
        Database::fromEnv(['password' => null]);
    }

    #[Group('mysql')]
    public function testMysqlReturnsDriver(): void
    {
        $driver = Database::mysql([
            'host' => $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
            'database' => $_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['MYSQL_USERNAME'] ?? 'root',
            'password' => $_ENV['MYSQL_PASSWORD'] ?? 'root',
        ]);

        $this->assertInstanceOf(MySqlDriver::class, $driver);
    }

    #[Group('postgres')]
    public function testPostgresReturnsDriver(): void
    {
        $driver = Database::postgres([
            'host' => $_ENV['POSTGRES_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
            'database' => $_ENV['POSTGRES_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['POSTGRES_USERNAME'] ?? 'postgres',
            'password' => $_ENV['POSTGRES_PASSWORD'] ?? 'postgres',
        ]);

        $this->assertInstanceOf(PostgresDriver::class, $driver);
    }

    public function testSqliteReturnsDriver(): void
    {
        $driver = Database::sqlite();

        $this->assertInstanceOf(SqliteDriver::class, $driver);
    }

    public function testSqliteWithPathReturnsDriver(): void
    {
        $driver = Database::sqlite(':memory:');

        $this->assertInstanceOf(SqliteDriver::class, $driver);
    }

    /**
     * SQLite would open a private temporary database for an empty path and delete it on close.
     */
    public function testAnEmptySqlitePathIsRejected(): void
    {
        $attempts = [
            static fn (): SqliteDriver => new SqliteDriver(''),
            static fn (): SqliteDriver => Database::sqlite(''),
            static fn (): \Sodaho\PdoWrapper\DatabaseInterface => Database::connect(['driver' => 'sqlite', 'path' => '']),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Expected ConnectionException');
            } catch (ConnectionException $e) {
                $this->assertSame('Database connection failed', $e->getMessage());
                $this->assertSame('SQLite path is empty: use ":memory:" for an in-memory database, or the path of a file', $e->getDebugMessage());
            }
        }
    }

    /**
     * `DB_HOST=` in a dotenv template: set, but empty. It counts as not set - a required value is
     * then reported as missing instead of connecting with an empty one, an optional one takes its
     * default. The same for a value that is no scalar.
     */
    public function testAnEmptyEnvironmentVariableCountsAsNotSet(): void
    {
        // $_ENV
        $_ENV['DB_HOST'] = '';
        putenv('DB_HOST=127.0.0.1'); // $_ENV has the key: it decides
        $_ENV['DB_DATABASE'] = 'app';
        $_ENV['DB_USERNAME'] = 'app';
        $this->assertMissingConfig(static fn (): \Sodaho\PdoWrapper\DatabaseInterface => Database::fromEnv(['driver' => 'mysql']));
        $this->assertMissingConfig(static fn (): \Sodaho\PdoWrapper\DatabaseInterface => Database::fromEnv(['driver' => 'pgsql']));
        putenv('DB_HOST');

        $_ENV['DB_HOST'] = ['127.0.0.1'];
        $this->assertMissingConfig(static fn (): \Sodaho\PdoWrapper\DatabaseInterface => Database::fromEnv(['driver' => 'mysql']));

        // null in $_ENV is no value (as before): getenv() is asked
        $file = sys_get_temp_dir() . '/pdo-wrapper-env-' . bin2hex(random_bytes(4)) . '.db';
        $_ENV['DB_SQLITE_PATH'] = null;
        putenv('DB_SQLITE_PATH=' . $file);
        try {
            Database::fromEnv(['driver' => 'sqlite'])->execute('CREATE TABLE t (id INTEGER)');
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
            putenv('DB_SQLITE_PATH');
        }

        // getenv()
        unset($_ENV['DB_HOST'], $_ENV['DB_DRIVER']);
        putenv('DB_HOST=');
        $this->assertMissingConfig(static fn (): \Sodaho\PdoWrapper\DatabaseInterface => Database::fromEnv(['driver' => 'mysql']));
        putenv('DB_DRIVER=');
        try {
            Database::fromEnv();
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('No database driver given', (string) $e->getDebugMessage());
        }
    }

    /**
     * An unset DB_SQLITE_PATH means the in-memory default. One that is set but empty is a broken
     * setting: the default would be a database that forgets everything, without a word.
     */
    public function testAnEmptySqlitePathFromTheEnvironmentIsRejected(): void
    {
        $this->assertSame(1, (int) Database::fromEnv(['driver' => 'sqlite'])->query('SELECT 1')->fetchColumn(), 'not set: :memory:');

        $attempts = [
            'in $_ENV' => static function (): void {
                $_ENV['DB_SQLITE_PATH'] = '';
            },
            'in $_ENV, although getenv() has a path' => static function (): void {
                $_ENV['DB_SQLITE_PATH'] = '';
                putenv('DB_SQLITE_PATH=:memory:');
            },
            'in getenv()' => static function (): void {
                unset($_ENV['DB_SQLITE_PATH']);
                putenv('DB_SQLITE_PATH=');
            },
            'in $_ENV as an array' => static function (): void {
                putenv('DB_SQLITE_PATH');
                $_ENV['DB_SQLITE_PATH'] = ['/data/app.db'];
            },
        ];

        foreach ($attempts as $where => $prepare) {
            $prepare();
            try {
                Database::fromEnv(['driver' => 'sqlite']);
                $this->fail('Expected ConnectionException: ' . $where);
            } catch (ConnectionException $e) {
                $this->assertStringStartsWith('DB_SQLITE_PATH is set but empty', (string) $e->getDebugMessage(), $where);
            }

            // a path that was passed wins over the broken variable, and another driver does not look at it
            $this->assertSame(1, (int) Database::fromEnv(['driver' => 'sqlite', 'path' => ':memory:'])->query('SELECT 1')->fetchColumn());
            $this->assertMissingConfig(static fn (): \Sodaho\PdoWrapper\DatabaseInterface => Database::fromEnv(['driver' => 'mysql']));
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
            new \ReflectionParameter([Database::class, 'mysql'], 'config'),
            new \ReflectionParameter([Database::class, 'postgres'], 'config'),
            new \ReflectionParameter([Database::class, 'connect'], 'config'),
            new \ReflectionParameter([Database::class, 'fromEnv'], 'overrides'),
            new \ReflectionParameter([MySqlDriver::class, '__construct'], 'config'),
            new \ReflectionParameter([PostgresDriver::class, '__construct'], 'config'),
        ];

        foreach ($parameters as $parameter) {
            $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class), $parameter->getDeclaringFunction()->getName());
        }

        try {
            new MySqlDriver(['host' => 'h', 'database' => 'd', 'username' => 'u', 'password' => 'secret', 'port' => 'abc']);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringNotContainsString('secret', var_export($e->getTrace(), true));
        }
    }
}
