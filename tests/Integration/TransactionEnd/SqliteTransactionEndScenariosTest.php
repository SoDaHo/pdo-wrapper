<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\TransactionEnd;

use PDO;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\SqliteDriver;

class SqliteTransactionEndScenariosTest extends AbstractTransactionEndScenarios
{
    protected function makeScenarioPdo(): ScenarioPdo
    {
        return new ScenarioPdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    protected function makeDriver(ScenarioPdo $pdo): DatabaseInterface
    {
        return new class ($pdo) extends SqliteDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
    }

    protected function makeObserver(): ?DatabaseInterface
    {
        return null; // an in-memory database has no second connection
    }

    protected function createTableSql(): string
    {
        return 'CREATE TABLE ' . self::TABLE . ' (id INTEGER PRIMARY KEY, name TEXT)';
    }

    protected function swallowedStatementErrorKeepsEarlierRows(): bool
    {
        return true; // a failed statement does not abort the transaction
    }
}
