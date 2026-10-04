<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use Pdo\Mysql;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Driver\ConnectionSettings;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Tests\Support\SqliteBackedPdo;

/**
 * A driver keeps its credentials and options for reconnect(): they reach PDO on every connect,
 * and never a dump of the driver.
 */
class ConnectionSettingsTest extends TestCase
{
    public function testTheSettingsShowInNoDump(): void
    {
        $settings = new ConnectionSettings('dump-user-1', 'dump-secret-1', [1002 => 'dump-option-1']);
        $this->assertSame(['dump-user-1', 'dump-secret-1', [1002 => 'dump-option-1']], [$settings->username(), $settings->password(), $settings->options()]);
        foreach ($this->dumps($settings) as $how => $dump) {
            foreach (['dump-secret-1', 'dump-user-1', 'dump-option-1'] as $value) {
                $this->assertStringNotContainsString($value, $dump, "{$how}: {$value}");
            }
        }

        $none = new ConnectionSettings(null, null, []);
        $this->assertSame([null, null, []], [$none->username(), $none->password(), $none->options()], 'a connection without credentials (SQLite), or without a password');
    }

    /**
     * The credentials and options reach the PDO class on the first connect and on reconnect(),
     * and a dump of the driver shows none of them.
     */
    public function testAServerDriverKeepsItsSettingsForReconnectOutOfItsDumps(): void
    {
        $config = ['host' => 'db.internal', 'database' => 'app', 'username' => 'dump-user-2', 'password' => 'dump-secret-2', 'pdoClass' => SqliteBackedPdo::class, 'options' => [Mysql::ATTR_INIT_COMMAND => "SET @marker = 'dump-option-2'"]];
        $drivers = [
            'mysql:host=db.internal;port=3306;dbname=app;charset=utf8mb4' => static fn (): AbstractDriver => new MySqlDriver($config),
            "pgsql:host='db.internal';port=5432;dbname='app'" => static fn (): AbstractDriver => new PostgresDriver($config),
        ];

        foreach ($drivers as $dsn => $create) {
            SqliteBackedPdo::forget();
            $db = $create();
            foreach ($this->dumps($db) as $how => $dump) {
                foreach (['dump-secret-2', 'dump-user-2', 'dump-option-2'] as $value) {
                    $this->assertStringNotContainsString($value, $dump, "{$dsn}: {$how}: {$value}");
                }
            }

            $db->reconnect();

            $given = SqliteBackedPdo::given();
            $this->assertCount(2, $given, $dsn);
            foreach ($given as $n => [$givenDsn, $username, $password, $options]) {
                $this->assertSame([$dsn, 'dump-user-2', 'dump-secret-2'], [$givenDsn, $username, $password], "{$dsn}: connect {$n}");
                $this->assertSame("SET @marker = 'dump-option-2'", $options[Mysql::ATTR_INIT_COMMAND] ?? null, "{$dsn}: connect {$n}");
            }
        }
    }

    /**
     * SQLite keeps its options the same way.
     */
    public function testSqliteKeepsItsOptionsOutOfItsDumps(): void
    {
        SqliteBackedPdo::forget();
        $db = Database::sqlite(':memory:', [Mysql::ATTR_INIT_COMMAND => 'dump-option-3'], SqliteBackedPdo::class);
        foreach ($this->dumps($db) as $how => $dump) {
            $this->assertStringNotContainsString('dump-option-3', $dump, $how);
        }
        $db->reconnect();
        $this->assertSame(['dump-option-3', 'dump-option-3'], array_map(static fn (array $given): mixed => $given[3][Mysql::ATTR_INIT_COMMAND] ?? null, SqliteBackedPdo::given()));
    }

    /**
     * @return array<string, string>
     */
    private function dumps(object $subject): array
    {
        ob_start();
        var_dump($subject);
        $varDump = (string) ob_get_clean();

        return [
            'var_dump' => $varDump,
            'print_r' => print_r($subject, true),
            'var_export' => var_export($subject, true),
            'json_encode' => (string) json_encode($subject),
        ];
    }
}
