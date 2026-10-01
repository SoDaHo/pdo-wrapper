<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\TransactionEnd;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\TransactionException;

#[Group('mysql')]
class MySqlTransactionEndScenariosTest extends AbstractTransactionEndScenarios
{
    /** @return array{host: string, port: int, database: string, username: string, password: string} */
    private static function config(): array
    {
        return [
            'host' => (string) ($_ENV['MYSQL_HOST'] ?? '127.0.0.1'),
            'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
            'database' => (string) ($_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test'),
            'username' => (string) ($_ENV['MYSQL_USERNAME'] ?? 'root'),
            'password' => (string) ($_ENV['MYSQL_PASSWORD'] ?? 'root'),
        ];
    }

    protected function makeScenarioPdo(): ScenarioPdo
    {
        $c = self::config();

        return new ScenarioPdo(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']),
            $c['username'],
            $c['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    protected function makeDriver(ScenarioPdo $pdo): DatabaseInterface
    {
        return new class ($pdo) extends MySqlDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
    }

    protected function makeObserver(): ?DatabaseInterface
    {
        return new MySqlDriver(self::config());
    }

    protected function createTableSql(): string
    {
        return 'CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, name VARCHAR(50))';
    }

    protected function swallowedStatementErrorKeepsEarlierRows(): bool
    {
        return true; // InnoDB rolls back only the failed statement
    }

    /**
     * A DDL statement inside the callback commits the transaction implicitly (MySQL/MariaDB). The
     * library's own COMMIT then fails ("no active transaction"), the caller gets a
     * TransactionException, 'transaction.end' reports 'lost' - and the data is committed: that is why
     * 'lost' means "may be committed", fail-closed.
     */
    public function testADdlStatementInsideTheCallbackCommitsImplicitlyAndEndsAsLost(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS end_scenarios_ddl');

        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
                $db->execute('CREATE TABLE end_scenarios_ddl (id INT PRIMARY KEY)'); // implicit COMMIT
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $e]], $this->ends, 'no rollback could be confirmed');
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS end_scenarios_ddl');
        }

        $this->assertSame(['end'], $this->events, 'no rollback listener');
        $this->assertVisible([1], 'the row is committed although the end says lost');
    }
}
