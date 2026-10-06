<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\TransactionEnd;

use PDOException;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;

/**
 * A driver that remembers every statement failure: $refusal is what it says at commit time (null:
 * still committable), $stateKnown what asking before a rollback finds out, $goneWhenAsked whether
 * asking makes PDO learn that the transaction is gone, $over whether a failure ended the
 * transaction for certain.
 */
final class RememberingDriver extends HookDriver
{
    public ?string $refusal = null;

    public bool $stateKnown = true;

    public bool $goneWhenAsked = false;

    public bool $over = false;

    protected function failureToRemember(?PDOException $remembered, PDOException $failure): PDOException
    {
        return $failure;
    }

    protected function transactionEndedBy(PDOException $failure): ?string
    {
        return $this->refusal;
    }

    protected function refreshTransactionState(): bool
    {
        if ($this->goneWhenAsked && $this->pdo instanceof ScenarioPdo) {
            $this->pdo->hideTransaction = true;
        }

        return $this->stateKnown;
    }

    protected function transactionIsOver(PDOException $failure): bool
    {
        return $this->over;
    }
}
