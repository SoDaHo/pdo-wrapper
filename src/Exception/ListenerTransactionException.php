<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown by beginTransaction(), commit() and rollback() - and so by transaction() and
 * updateMultiple() - when called from inside a listener of this driver that runs in the middle of
 * a transaction: a 'transaction.begin', 'transaction.commit' or 'transaction.rollback' listener,
 * or a 'query.before', 'query' or 'error' listener entered while a transaction was open (also from
 * a listener running inside such a one). It runs on the caller's connection, in the caller's
 * transaction: a commit() there committed what the caller was still building, a rollback() undid
 * it, a transaction begun there ran inside the caller's statement. Nothing is done; the exception
 * reaches the listener's caller like any listener exception (see Traits\HasHooks). Not refused: a
 * 'transaction.end' listener - the transaction has ended - and a statement listener outside of a
 * transaction run a transaction of their own, with its own number and end.
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
