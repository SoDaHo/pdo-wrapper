<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract;

use PDO;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Tests\Support\Binding\DriverBinding;
use Sodaho\PdoWrapper\Tests\Support\Binding\MariaDbBinding;
use UnexpectedValueException;

/**
 * The base of the contract tests: what every driver of this library must do the same way. The
 * tests reach the database only through the binding of the driver under test
 * (PDO_WRAPPER_TEST_DRIVER, default `mariadb`) - they name no engine and write no DDL.
 *
 * $db is a driver on the test database, opened before each test. Tables made with create() are
 * dropped after it, on a connection of their own, once $db is closed: an open transaction of the
 * test must not make the DROP wait for its locks. A test that keeps further connections in
 * properties closes them in closeConnections().
 */
abstract class ContractTestCase extends TestCase
{
    private static ?DriverBinding $binding = null;

    private static ?DatabaseInterface $janitor = null;

    final protected AbstractDriver $db;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->db = $this->connect();
    }

    protected function tearDown(): void
    {
        unset($this->db);
        $this->closeConnections();
        // A listener that uses a driver forms a cycle with it: collected now, its connection closes
        // before the DROP below, and does not stay open for the rest of the run
        gc_collect_cycles();
        foreach (array_reverse($this->created) as $table) {
            self::binding()->drop(self::janitor(), $table);
        }
        $this->created = [];
    }

    /** Close what the test keeps open besides $db, before its tables are dropped */
    protected function closeConnections(): void
    {
    }

    protected static function binding(): DriverBinding
    {
        return self::$binding ??= match ($_ENV['PDO_WRAPPER_TEST_DRIVER'] ?? 'mariadb') {
            'mariadb' => new MariaDbBinding(),
            default => throw new UnexpectedValueException('PDO_WRAPPER_TEST_DRIVER names no binding'),
        };
    }

    /**
     * A further driver on the test database.
     *
     * @param array{pdoClass?: class-string<PDO>, options?: array<int, mixed>} $extra
     */
    protected function connect(array $extra = []): AbstractDriver
    {
        return self::binding()->connect($extra);
    }

    /**
     * A table for this test (see DriverBinding for the column types), dropped after it.
     *
     * @param array<int|string, string> $columns
     */
    protected function create(string $table, array $columns): void
    {
        self::binding()->create(self::janitor(), $table, $columns);
        $this->created[] = $table;
    }

    /** The connection that creates and drops the tables: never one a test works on */
    private static function janitor(): DatabaseInterface
    {
        return self::$janitor ??= self::binding()->connect();
    }
}
