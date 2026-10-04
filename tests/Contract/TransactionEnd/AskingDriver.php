<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\TransactionEnd;

use PDOException;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;

/**
 * A driver that remembers every statement failure and is asked about the transaction
 * afterwards, as a driver may ask its server: $answer says what asking finds. A failure that
 * names "fatal_table" settles the matter by itself and is not asked about.
 */
final class AskingDriver extends HookDriver
{
    public int $asked = 0;

    /** What asking finds: 'gone' hides the transaction, 'alive' leaves it, 'unknown' could not find out */
    public string $answer = 'alive';

    /** @var list<string> Answers for the next questions, one each, before $answer applies again */
    public array $answers = [];

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
        $answer = array_shift($this->answers) ?? $this->answer;
        if ($answer === 'gone' && $this->pdo instanceof ScenarioPdo) {
            $this->pdo->hideTransaction = true;
        }

        return $answer !== 'unknown';
    }
}
