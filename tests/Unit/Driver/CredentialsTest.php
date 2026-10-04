<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Driver\Credentials;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Tests\Support\SqliteBackedPdo;

/**
 * A server driver keeps its credentials for reconnect(): they reach PDO on every connect, and
 * never a dump of the driver.
 */
class CredentialsTest extends TestCase
{
    public function testTheCredentialsShowInNoDump(): void
    {
        $credentials = new Credentials('dump-user-1', 'dump-secret-1');
        $this->assertSame(['dump-user-1', 'dump-secret-1'], [$credentials->username(), $credentials->password()]);
        foreach ($this->dumps($credentials) as $how => $dump) {
            $this->assertStringNotContainsString('dump-secret-1', $dump, $how);
            $this->assertStringNotContainsString('dump-user-1', $dump, $how);
        }

        $none = new Credentials(null, null);
        $this->assertSame([null, null], [$none->username(), $none->password()], 'a connection without credentials (or without a password)');
    }

    /**
     * The credentials reach the PDO class on the first connect and on reconnect(), and a dump of
     * the driver shows neither.
     */
    public function testAServerDriverKeepsItsCredentialsForReconnectOutOfItsDumps(): void
    {
        $drivers = [
            'mysql:host=db.internal;port=3306;dbname=app;charset=utf8mb4' => static fn (): AbstractDriver => new MySqlDriver(['host' => 'db.internal', 'database' => 'app', 'username' => 'dump-user-2', 'password' => 'dump-secret-2', 'pdoClass' => SqliteBackedPdo::class]),
            "pgsql:host='db.internal';port=5432;dbname='app'" => static fn (): AbstractDriver => new PostgresDriver(['host' => 'db.internal', 'database' => 'app', 'username' => 'dump-user-2', 'password' => 'dump-secret-2', 'pdoClass' => SqliteBackedPdo::class]),
        ];

        foreach ($drivers as $dsn => $create) {
            SqliteBackedPdo::$given = [];
            $db = $create();
            foreach ($this->dumps($db) as $how => $dump) {
                $this->assertStringNotContainsString('dump-secret-2', $dump, "{$dsn}: {$how}");
                $this->assertStringNotContainsString('dump-user-2', $dump, "{$dsn}: {$how}");
            }

            $db->reconnect();

            $this->assertSame([[$dsn, 'dump-user-2', 'dump-secret-2'], [$dsn, 'dump-user-2', 'dump-secret-2']], SqliteBackedPdo::$given, $dsn);
        }
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
