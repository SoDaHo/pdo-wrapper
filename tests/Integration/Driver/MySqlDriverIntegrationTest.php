<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\TransactionException;

/**
 * @group mysql
 */
class MySqlDriverIntegrationTest extends TestCase
{
    private MySqlDriver $driver;

    private static function getConfig(): array
    {
        return [
            'host' => $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
            'database' => $_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['MYSQL_USERNAME'] ?? 'root',
            'password' => $_ENV['MYSQL_PASSWORD'] ?? 'root',
        ];
    }

    protected function setUp(): void
    {
        $this->driver = new MySqlDriver(self::getConfig());
    }

    public function testImplementsDatabaseInterface(): void
    {
        $this->assertInstanceOf(DatabaseInterface::class, $this->driver);
    }

    public function testConnectsToMySql(): void
    {
        $this->assertInstanceOf(PDO::class, $this->driver->getPdo());
    }

    public function testQueryReturnsStatement(): void
    {
        $stmt = $this->driver->query('SELECT 1 as test');

        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertSame(1, $stmt->fetch()['test']);
    }

    public function testExecuteReturnsAffectedRows(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_mysql (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))');

        $affected = $this->driver->execute('INSERT INTO test_mysql (name) VALUES (?)', ['hello']);

        $this->assertSame(1, $affected);
    }

    public function testLastInsertId(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_mysql2 (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))');
        $this->driver->execute('INSERT INTO test_mysql2 (name) VALUES (?)', ['hello']);

        $id = $this->driver->lastInsertId();

        $this->assertSame('1', $id);
    }

    public function testConnectionUsesUtf8mb4(): void
    {
        $stmt = $this->driver->query("SHOW VARIABLES LIKE 'character_set_client'");
        $result = $stmt->fetch();

        $this->assertSame('utf8mb4', $result['Value']);
    }

    public function testConnectionUsesExceptionErrorMode(): void
    {
        $errorMode = $this->driver->getPdo()->getAttribute(PDO::ATTR_ERRMODE);

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $errorMode);
    }

    public function testConnectionUsesFetchAssoc(): void
    {
        $fetchMode = $this->driver->getPdo()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);

        $this->assertSame(PDO::FETCH_ASSOC, $fetchMode);
    }

    public function testConnectionDisablesEmulatedPrepares(): void
    {
        $emulate = $this->driver->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES);

        $this->assertFalse($emulate);
    }

    public function testTransactionCommitWithoutBeginThrowsException(): void
    {
        $this->expectException(TransactionException::class);

        $this->driver->commit();
    }

    public function testTransactionRollbackWithoutBeginThrowsException(): void
    {
        $this->expectException(TransactionException::class);

        $this->driver->rollback();
    }

    public function testNestedTransactionBeginThrowsException(): void
    {
        $this->driver->beginTransaction();

        $this->expectException(TransactionException::class);

        $this->driver->beginTransaction();
    }

    public function testTransactionExceptionHasDebugMessage(): void
    {
        try {
            $this->driver->commit();
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertNotNull($e->getDebugMessage());
            return;
        }

        $this->fail('Expected TransactionException was not thrown');
    }
}
