<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Tests\Support\DsnRefusingPdo;
use Sodaho\PdoWrapper\Tests\Support\Untyped;

/**
 * A config key the driver does not take is refused by every factory before anything is checked,
 * built or tried: a misspelt `redactParamters` => true would connect without the redaction it asks
 * for. The key is named in the debug message alone. The
 * driver's name is a key of connect() and fromEnv(), which take it away before the driver sees the
 * rest; mariadb() and the driver itself refuse it. A connection attempt goes to a PDO class that
 * keeps the DSN and refuses it.
 */
class UnknownConfigKeyTest extends TestCase
{
    /** Every key the driver takes, a connection attempt refused by the PDO class */
    private const BASE = ['host' => '127.0.0.1', 'port' => 59994, 'database' => 'app', 'username' => 'pdo_wrapper_nobody', 'password' => 'x', 'pdoClass' => DsnRefusingPdo::class];

    private const KEYS = 'host, database, username, password, port, charset, options, pdoClass, redactParameters';

    private mixed $savedDriver = null;

    protected function setUp(): void
    {
        $this->savedDriver = $_ENV['DB_DRIVER'] ?? null;
        DsnRefusingPdo::$dsn = null;
    }

    protected function tearDown(): void
    {
        if ($this->savedDriver === null) {
            unset($_ENV['DB_DRIVER']);
        } else {
            $_ENV['DB_DRIVER'] = $this->savedDriver;
        }
    }

    /**
     * A misspelt switch through each factory: refused, nothing tried, the key named only in the
     * debug message.
     */
    public function testAMisspeltKeyIsRefusedByEveryFactory(): void
    {
        $misspelt = ['redactParamters' => true] + self::BASE;
        foreach ([
            'mariadb()' => static fn (): mixed => Untyped::call(Database::mariadb(...), $misspelt),
            'new MariaDbDriver()' => static fn (): mixed => Untyped::create(MariaDbDriver::class, $misspelt),
            'connect()' => static fn (): mixed => Untyped::call(Database::connect(...), ['driver' => 'mariadb'] + $misspelt),
            'fromEnv()' => static fn (): mixed => Untyped::call(Database::fromEnv(...), ['driver' => 'mariadb'] + $misspelt),
        ] as $how => $call) {
            $this->assertRefused($call, '"redactParamters"', $how);
        }
    }

    /**
     * Every key that is none of the driver's is named - also one of a list (an integer), and the
     * driver's name where mariadb() or the driver itself is handed it.
     */
    public function testEveryUnknownKeyIsNamed(): void
    {
        $this->assertRefused(static fn (): mixed => Untyped::call(Database::mariadb(...), ['socket' => '/tmp/x', 'timeout' => 5] + self::BASE), '"socket", "timeout"', 'two keys');
        $this->assertRefused(static fn (): mixed => Untyped::call(Database::mariadb(...), self::BASE + ['x']), '"0"', 'a list entry');
        $this->assertRefused(static fn (): mixed => Untyped::call(Database::mariadb(...), ['driver' => 'mariadb'] + self::BASE), '"driver"', 'mariadb() with a driver');
        $this->assertRefused(static fn (): mixed => Untyped::create(MariaDbDriver::class, ['driver' => 'mariadb'] + self::BASE), '"driver"', 'the driver with a driver');
    }

    /**
     * The driver's name is connect()'s and fromEnv()'s: given in the config or read from DB_DRIVER,
     * it is taken away before the driver sees the rest, and the connection is tried.
     */
    public function testTheDriverNameIsTakenAwayByConnectAndFromEnv(): void
    {
        $_ENV['DB_DRIVER'] = 'mariadb';
        foreach ([
            'connect()' => static fn (): mixed => Untyped::call(Database::connect(...), ['driver' => 'mariadb'] + self::BASE),
            'fromEnv() with the driver passed' => static fn (): mixed => Untyped::call(Database::fromEnv(...), ['driver' => 'mariadb'] + self::BASE),
            'fromEnv() with DB_DRIVER' => static fn (): mixed => Untyped::call(Database::fromEnv(...), self::BASE),
        ] as $how => $call) {
            DsnRefusingPdo::$dsn = null;
            try {
                $call();
                $this->fail('Expected ConnectionException: the PDO class refuses the connection, ' . $how);
            } catch (ConnectionException $e) {
                $this->assertStringStartsWith('MariaDB connection to 127.0.0.1:59994 failed', (string) $e->getDebugMessage(), $how);
                $this->assertSame('mysql:host=127.0.0.1;port=59994;dbname=app;charset=utf8mb4', DsnRefusingPdo::$dsn, $how);
            }
        }
    }

    /** The call throws the refusal of the keys: static message, the keys in the debug message, nothing built or tried */
    private function assertRefused(callable $call, string $keys, string $how): void
    {
        DsnRefusingPdo::$dsn = null;
        try {
            $call();
            $this->fail('Expected ConnectionException: ' . $how);
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage(), $how);
            $this->assertSame(sprintf('Unknown config key %s: the keys are %s (Database::connect() and fromEnv() take driver as well)', $keys, self::KEYS), $e->getDebugMessage(), $how);
            $this->assertNull($e->getPrevious(), $how . ': nothing was tried');
            $this->assertNull(DsnRefusingPdo::$dsn, $how . ': no DSN was built');
        }
    }
}
