<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\PdoClass;

use Pdo\Mysql;
use Pdo\Sqlite;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

#[Group('mysql')]
class MySqlPdoClassTest extends AbstractPdoClassScenarios
{
    protected function factories(): array
    {
        $config = TestEnvironment::mysql();

        return [
            'Database::mysql()' => static fn (array $extra): DatabaseInterface => Database::mysql($config + $extra),
            'new MySqlDriver()' => static fn (array $extra): DatabaseInterface => new MySqlDriver($config + $extra),
            'Database::connect()' => static fn (array $extra): DatabaseInterface => Database::connect(['driver' => 'mysql'] + $config + $extra),
            'Database::fromEnv() with everything passed' => static fn (array $extra): DatabaseInterface => Database::fromEnv(['driver' => 'mysql'] + $config + $extra),
            'Database::fromEnv() with DB_*' => static function (array $extra) use ($config): DatabaseInterface {
                $_ENV['DB_DRIVER'] = 'mysql';
                $_ENV['DB_HOST'] = $config['host'];
                $_ENV['DB_PORT'] = (string) $config['port'];
                $_ENV['DB_DATABASE'] = $config['database'];
                $_ENV['DB_USERNAME'] = $config['username'];
                $_ENV['DB_PASSWORD'] = $config['password'];
                try {
                    return Database::fromEnv($extra);
                } finally {
                    unset($_ENV['DB_DRIVER'], $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_DATABASE'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD']);
                }
            },
        ];
    }

    protected function driversOwnPdoClass(): string
    {
        return Mysql::class;
    }

    protected function createTableSql(): string
    {
        return 'CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, name VARCHAR(50))';
    }

    /**
     * A class of another driver passes the check - it extends PDO - and fails where PDO refuses
     * it: as the failed connection it is. The other driver has to be loaded for its class to exist.
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAnotherDriversClassFailsAsAFailedConnection(): void
    {
        try {
            Database::mysql(TestEnvironment::mysql() + ['pdoClass' => Sqlite::class]);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
            $this->assertStringContainsString('cannot be used for connecting to the "mysql" driver', (string) $e->getDebugMessage());
        }
    }
}
