<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown by beginTransaction(), commit(), rollback(), transaction() and updateMultiple() (where it
 * would begin its own transaction) when called from inside a listener of this driver that may not
 * steer a transaction - the rule is Traits\HasHooks. Nothing is done; the exception reaches the
 * listener's caller like any listener exception.
 *
 * A TransactionException, so existing catch blocks keep working; catch this class to tell the
 * refusal apart without reading the message. No database failure stands behind it: $sqlState and
 * $driverCode are null.
 */
class ListenerTransactionException extends TransactionException
{
    public function __construct(
        string $message = 'Transaction control refused inside a listener',
        ?Throwable $previous = null,
        ?string $debugMessage = null
    ) {
        parent::__construct($message, $previous, $debugMessage);
    }
}
