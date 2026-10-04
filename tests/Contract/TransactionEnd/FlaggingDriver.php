<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\TransactionEnd;

use PDOException;

/**
 * A driver that flags statement failures and is asked about them at commit time: a failure
 * that names "fatal_table" has ended the transaction, one that names "ignored_table" is not flagged.
 */
final class FlaggingDriver extends HookDriver
{
    /** @var list<string> The messages of the failures the driver was asked about */
    public array $asked = [];

    protected function failureToRemember(?PDOException $remembered, PDOException $failure): ?PDOException
    {
        return str_contains($failure->getMessage(), 'ignored_table') ? $remembered : $failure;
    }

    protected function transactionEndedBy(PDOException $failure): ?string
    {
        $this->asked[] = $failure->getMessage();

        return str_contains($failure->getMessage(), 'fatal_table') ? 'ended by the server (scenario)' : null;
    }
}
