<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown by beginTransaction(), commit(), rollback(), transaction() and updateMultiple() (where it
 * would begin its own transaction) when called from inside a listener of this driver that may not
 * steer a transaction - the rule in three sentences: a 'transaction.end' listener may (the
 * transaction has ended); a 'query.before', 'query' or 'error' listener may run transaction() and
 * updateMultiple() when no transaction was open as it was entered, never beginTransaction(),
 * commit() or rollback(); a 'transaction.begin', 'transaction.commit' or 'transaction.rollback'
 * listener may do none of it, nor may a listener of any other event (a custom driver's own,
 * knownEvents()). A listener that may not refuses for every listener inside it, an
 * end listener included. A statement listener runs in the middle of the caller's statement, a
 * begin, commit or rollback listener in the middle of the caller's transaction, on the caller's
 * connection: a commit() there committed what the caller was still building, a rollback() undid
 * it, a transaction begun around a statement in autocommit and left open took that statement in.
 * Nothing is done; the exception reaches the listener's caller like any listener exception (see
 * Traits\HasHooks).
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
