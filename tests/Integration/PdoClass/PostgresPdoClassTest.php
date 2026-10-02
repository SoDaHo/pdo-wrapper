<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\PdoClass;

use Pdo\Pgsql;
use Pdo\Sqlite;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

#[Group('postgres')]
class PostgresPdoClassTest extends AbstractPdoClassScenarios
{
    protected function factories(): array
    {
        $config = TestEnvironment::postgres();

        return [
            'Database::postgres()' => static fn (array $extra): DatabaseInterface => Database::postgres($config + $extra),
            'new PostgresDriver()' => static fn (array $extra): DatabaseInterface => new PostgresDriver($config + $extra),
            'Database::connect()' => static fn (array $extra): DatabaseInterface => Database::connect(['driver' => 'pgsql'] + $config + $extra),
            'Database::fromEnv() with everything passed' => static fn (array $extra): DatabaseInterface => Database::fromEnv(['driver' => 'pgsql'] + $config + $extra),
            'Database::fromEnv() with DB_*' => static function (array $extra) use ($config): DatabaseInterface {
                $_ENV['DB_DRIVER'] = 'pgsql';
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
        return Pgsql::class;
    }

    protected function createTableSql(): string
    {
        return 'CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, name VARCHAR(50))';
    }

    /**
     * A class of another driver passes the check - it extends PDO - and fails where PDO refuses
     * it: as the failed connection it is.
     */
    public function testAnotherDriversClassFailsAsAFailedConnection(): void
    {
        try {
            Database::postgres(TestEnvironment::postgres() + ['pdoClass' => Sqlite::class]);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
            $this->assertStringContainsString('cannot be used for connecting to the "pgsql" driver', (string) $e->getDebugMessage());
        }
    }
}
