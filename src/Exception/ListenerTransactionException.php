<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown by beginTransaction(), commit() and rollback() - and so by transaction() and
 * updateMultiple() - when called from inside a listener of this driver other than a
 * 'transaction.end' listener (also from an end listener running inside such a one). A
 * 'query.before', 'query' or 'error' listener runs in the middle of the caller's statement, a
 * 'transaction.begin', 'transaction.commit' or 'transaction.rollback' listener in the middle of the
 * caller's transaction, on the caller's connection: a commit() there committed what the caller was
 * still building, a rollback() undid it, a transaction begun around a statement in autocommit took
 * that statement in. Nothing is done; the exception reaches the listener's caller like any listener
 * exception (see Traits\HasHooks). Not refused: a 'transaction.end' listener - the transaction
 * has ended - runs a transaction of its own, with its own number and end.
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
