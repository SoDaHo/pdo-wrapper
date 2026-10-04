<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Driver\ConnectionSettings;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Tests\Support\RecordingPdo;

/**
 * A driver keeps its credentials and options for reconnect(): they reach PDO on every connect,
 * and never a dump of the driver. The option key 1002 is pdo_mysql's init command.
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
        $this->assertSame([null, null, []], [$none->username(), $none->password(), $none->options()], 'a connection without credentials, or without a password');
    }

    /**
     * The credentials and options reach the PDO class on the first connect and on reconnect(),
     * and a dump of the driver shows none of them. (The PDO class keeps what it was given and
     * connects to the test database instead.)
     */
    public function testTheDriverKeepsItsSettingsForReconnectOutOfItsDumps(): void
    {
        $config = ['host' => 'db.internal', 'database' => 'app', 'username' => 'dump-user-2', 'password' => 'dump-secret-2', 'pdoClass' => RecordingPdo::class, 'options' => [1002 => "SET @marker = 'dump-option-2'"]];
        $drivers = [
            'mysql:host=db.internal;port=3306;dbname=app;charset=utf8mb4' => static fn (): AbstractDriver => new MySqlDriver($config),
        ];

        foreach ($drivers as $dsn => $create) {
            RecordingPdo::forget();
            $db = $create();
            foreach ($this->dumps($db) as $how => $dump) {
                foreach (['dump-secret-2', 'dump-user-2', 'dump-option-2'] as $value) {
                    $this->assertStringNotContainsString($value, $dump, "{$dsn}: {$how}: {$value}");
                }
            }

            $db->reconnect();

            $given = RecordingPdo::given();
            $this->assertCount(2, $given, $dsn);
            foreach ($given as $n => [$givenDsn, $username, $password, $options]) {
                $this->assertSame([$dsn, 'dump-user-2', 'dump-secret-2'], [$givenDsn, $username, $password], "{$dsn}: connect {$n}");
                $this->assertSame("SET @marker = 'dump-option-2'", $options[1002] ?? null, "{$dsn}: connect {$n}");
            }
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
