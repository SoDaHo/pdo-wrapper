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
     * connect() reads DB_DRIVER and DB_SQLITE_PATH from $_ENV and getenv(): both channels are
     * cleared for the tests and restored afterwards.
     */
    protected function setUp(): void
    {
        foreach (['DB_DRIVER', 'DB_SQLITE_PATH'] as $key) {
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

    public function testConnectPicksTheDriverFromConfigOrEnvironment(): void
    {
        $this->assertInstanceOf(SqliteDriver::class, Database::connect(['driver' => 'sqlite']));
        $this->assertInstanceOf(SqliteDriver::class, Database::connect(['driver' => ' SQLite ', 'path' => ':memory:']));
        $this->assertInstanceOf(SqliteDriver::class, Database::connect(['driver' => 'sqlite', 'database' => ':memory:']));

        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_SQLITE_PATH'] = ':memory:';
        $db = Database::connect();
        $this->assertInstanceOf(SqliteDriver::class, $db);
        $this->assertSame(1, (int) $db->query('SELECT 1')->fetchColumn());
    }

    public function testConnectRejectsMissingAndUnknownDrivers(): void
    {
        try {
            Database::connect();
            $this->fail('Expected ConnectionException without a driver');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('No database driver given', $e->getDebugMessage() ?? '');
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
}
