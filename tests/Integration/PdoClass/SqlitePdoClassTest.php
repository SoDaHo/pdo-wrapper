<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\PdoClass;

use PDO;
use Pdo\Sqlite;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\SqliteDriver;

class SqlitePdoClassTest extends AbstractPdoClassScenarios
{
    protected function factories(): array
    {
        return [
            // sqlite() and the constructor take the class as an argument: none is passed when the key is missing
            'Database::sqlite()' => static fn (array $extra): DatabaseInterface => array_key_exists('pdoClass', $extra)
                ? Database::sqlite(':memory:', $extra['options'] ?? [], $extra['pdoClass'] ?? PDO::class)
                : Database::sqlite(':memory:', $extra['options'] ?? []),
            'new SqliteDriver()' => static fn (array $extra): DatabaseInterface => array_key_exists('pdoClass', $extra)
                ? new SqliteDriver(':memory:', $extra['options'] ?? [], $extra['pdoClass'] ?? PDO::class)
                : new SqliteDriver(':memory:', $extra['options'] ?? []),
            'Database::connect()' => static fn (array $extra): DatabaseInterface => Database::connect(['driver' => 'sqlite', 'path' => ':memory:'] + $extra),
            'Database::fromEnv() with a path that is passed' => static fn (array $extra): DatabaseInterface => Database::fromEnv(['driver' => 'sqlite', 'path' => ':memory:'] + $extra),
            'Database::fromEnv() with DB_SQLITE_PATH' => static function (array $extra): DatabaseInterface {
                $_ENV['DB_DRIVER'] = 'sqlite';
                $_ENV['DB_SQLITE_PATH'] = ':memory:';
                try {
                    return Database::fromEnv($extra);
                } finally {
                    unset($_ENV['DB_DRIVER'], $_ENV['DB_SQLITE_PATH']);
                }
            },
        ];
    }

    protected function driversOwnPdoClass(): string
    {
        return Sqlite::class;
    }

    protected function createTableSql(): string
    {
        return 'CREATE TABLE ' . self::TABLE . ' (id INTEGER PRIMARY KEY, name TEXT)';
    }

    /**
     * What the library does to a SQLite connection after opening it is done to the given class too.
     */
    public function testForeignKeysAreSwitchedOnForTheGivenClass(): void
    {
        foreach ($this->factories() as $how => $connect) {
            $this->assertSame([['foreign_keys' => 1]], $connect(['pdoClass' => Sqlite::class])->query('PRAGMA foreign_keys')->fetchAll(), $how);
        }
    }

    /**
     * What the driver's own class is for: its methods, here a function defined in PHP.
     */
    public function testTheDriversOwnClassBringsItsMethods(): void
    {
        $db = Database::sqlite(':memory:', [], Sqlite::class);
        $pdo = $db->getPdo();
        $this->assertInstanceOf(Sqlite::class, $pdo);

        $pdo->createFunction('twice', static fn (int $n): int => 2 * $n, 1);

        $this->assertSame([['n' => 42]], $db->query('SELECT twice(21) AS n')->fetchAll());
    }
}
