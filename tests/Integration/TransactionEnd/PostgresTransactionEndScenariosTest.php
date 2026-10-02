<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\TransactionEnd;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

#[Group('postgres')]
class PostgresTransactionEndScenariosTest extends AbstractTransactionEndScenarios
{
    /** @return array{host: string, port: int, database: string, username: string, password: string} */
    private static function config(): array
    {
        return TestEnvironment::postgres();
    }

    protected function makeScenarioPdo(): ScenarioPdo
    {
        $c = self::config();

        return new ScenarioPdo(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['database']),
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
        return new class ($pdo) extends PostgresDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
    }

    protected function makeObserver(): ?DatabaseInterface
    {
        return new PostgresDriver(self::config());
    }

    protected function createTableSql(): string
    {
        return 'CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, name VARCHAR(50))';
    }

    protected function aFailedStatementAbortsTheTransaction(): bool
    {
        return true; // COMMIT would become a silent ROLLBACK: the library refuses it
    }
}
