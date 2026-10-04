<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Pdo\Mysql;
use Pdo\Sqlite;
use PDOException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

/**
 * 'pdoClass' on MariaDB: the driver's own class and a class of another PDO driver.
 */
class PdoClassTest extends ContractTestCase
{
    /**
     * A class of another driver passes the check - it extends PDO - and fails where PDO refuses
     * it: as the failed connection it is. The other driver has to be loaded for its class to exist.
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAnotherDriversClassFailsAsAFailedConnection(): void
    {
        try {
            Database::mariadb(TestEnvironment::mariadb() + ['pdoClass' => Sqlite::class]);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
            $this->assertStringContainsString('cannot be used for connecting to the "mysql" driver', (string) $e->getDebugMessage());
        }
    }

    /**
     * What the driver's own class is for: its methods, here the warnings of the last statement.
     */
    public function testTheDriversOwnClassBringsItsMethods(): void
    {
        $db = $this->connect(['pdoClass' => Mysql::class]);
        $pdo = $db->getPdo();
        $this->assertInstanceOf(Mysql::class, $pdo);

        $db->query("SELECT CAST('12abc' AS SIGNED) AS n");

        $this->assertSame(1, $pdo->getWarningCount());
    }
}
