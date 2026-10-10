<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown by beginTransaction(), commit() and rollback() - and so by transaction() and
 * updateMultiple() - when called from inside a listener of this driver (any event: 'query.before',
 * 'query', 'error', 'transaction.*'). A listener runs in the middle of an operation of the caller,
 * on the caller's connection: a commit() there committed what the caller was still building, a
 * rollback() undid it, a transaction begun there ran inside the caller's statement. Nothing is done;
 * the exception reaches the listener's caller like any listener exception (see Traits\HasHooks). A
 * listener that needs a transaction of its own uses a connection of its own.
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
