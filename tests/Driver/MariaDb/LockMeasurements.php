<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Throwable;

/**
 * What a lock scenario of MySqlDriverIntegrationTest measured on its connection.
 */
final class LockMeasurements
{
    /** @var list<array<string, mixed>> What the 'transaction.end' listener was handed */
    public array $ends = [];

    /** The cause of the failure the callback swallowed */
    public ?Throwable $swallowed = null;

    public ?bool $inTransactionAfterError = null;

    public ?bool $inTransactionAfterwards = null;

    public string $user1Name = '';

    public string $user2Name = '';
}
