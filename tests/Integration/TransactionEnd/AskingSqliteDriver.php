<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\TransactionEnd;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Driver\SqliteDriver;

/**
 * A SQLite driver that remembers every statement failure and is asked about the transaction
 * afterwards, as the MySQL driver asks its server: $answer says what asking finds. A failure that
 * names "fatal_table" settles the matter by itself and is not asked about.
 */
final class AskingSqliteDriver extends SqliteDriver
{
    public int $asked = 0;

    /** What asking finds: 'gone' hides the transaction, 'alive' leaves it, 'unknown' could not find out */
    public string $answer = 'alive';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    protected function failureToRemember(?PDOException $remembered, PDOException $failure): PDOException
    {
        return $failure;
    }

    protected function transactionIsOver(PDOException $failure): bool
    {
        return str_contains($failure->getMessage(), 'fatal_table');
    }

    protected function refreshTransactionState(): bool
    {
        $this->asked++;
        if ($this->answer === 'gone' && $this->pdo instanceof ScenarioPdo) {
            $this->pdo->hideTransaction = true;
        }

        return $this->answer !== 'unknown';
    }
}
