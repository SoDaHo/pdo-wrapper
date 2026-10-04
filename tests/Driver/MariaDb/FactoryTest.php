<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

/**
 * The factories with a real connection: by name, from the environment alone, and the PDO class
 * that is never read from it.
 */
class FactoryTest extends TestCase
{
    /** @var array<string, array{env: mixed, process: string|false}> */
    private array $savedEnvironment = [];

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

    public function testConnectAndMysqlReturnTheDriver(): void
    {
        $this->assertInstanceOf(MariaDbDriver::class, Database::mariadb(TestEnvironment::mariadb()));
        foreach (['mariadb', ' MariaDB '] as $driver) {
            $db = Database::connect(['driver' => $driver, ...TestEnvironment::mariadb()]);
            $this->assertInstanceOf(MariaDbDriver::class, $db);
            $this->assertSame(1, (int) $db->query('SELECT 1')->fetchColumn(), $driver);
        }
    }

    /**
     * Everything from the environment, the password included; a password that is passed - null
     * too - counts instead of DB_PASSWORD.
     */
    public function testFromEnvConnectsFromTheEnvironmentAlone(): void
    {
        $this->fromTheEnvironment();

        $db = Database::fromEnv();
        $this->assertInstanceOf(MariaDbDriver::class, $db);
        $this->assertSame(1, (int) $db->query('SELECT 1')->fetchColumn());

        $this->expectException(ConnectionException::class);
        Database::fromEnv(['password' => null]);
    }

    /**
     * The class has no variable: a name from the environment would be handed the credentials.
     */
    public function testThePdoClassIsNeverReadFromTheEnvironment(): void
    {
        $names = ['DB_PDO_CLASS', 'DB_PDOCLASS', 'DB_CLASS', 'PDO_CLASS', 'pdoClass'];
        $this->fromTheEnvironment();

        try {
            foreach ($names as $name) {
                $_ENV[$name] = ScenarioPdo::class;
                putenv($name . '=' . ScenarioPdo::class);
            }

            $this->assertSame(PDO::class, Database::fromEnv()->getPdo()::class);
            $this->assertSame(ScenarioPdo::class, Database::fromEnv(['pdoClass' => ScenarioPdo::class])->getPdo()::class, 'only what is passed');
        } finally {
            foreach ($names as $name) {
                unset($_ENV[$name]);
                putenv($name);
            }
        }
    }

    private function fromTheEnvironment(): void
    {
        $test = TestEnvironment::mariadb();
        $_ENV['DB_DRIVER'] = 'mariadb';
        $_ENV['DB_HOST'] = $test['host'];
        $_ENV['DB_PORT'] = (string) $test['port'];
        $_ENV['DB_DATABASE'] = $test['database'];
        $_ENV['DB_USERNAME'] = $test['username'];
        $_ENV['DB_PASSWORD'] = $test['password'];
    }
}
