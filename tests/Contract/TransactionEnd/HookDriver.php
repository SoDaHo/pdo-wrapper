<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\TransactionEnd;

use PDO;
use Sodaho\PdoWrapper\Driver\AbstractDriver;

/**
 * A custom driver on a PDO object it is given, with the base class's defaults for every hook:
 * the scenarios override the hooks they are about (failureToRemember(), transactionEndedBy(),
 * transactionIsOver(), refreshTransactionState()).
 */
class HookDriver extends AbstractDriver
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
}
