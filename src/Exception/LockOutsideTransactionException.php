<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown by the query builder for a locking read - lockForUpdate() or sharedLock() with get(),
 * first(), exists() or an aggregate - while no transaction is open: the lock would end with its own
 * statement, and whatever the caller does with the rows afterwards would not be protected (two
 * requests both read "not used yet" and both redeem). Refused before anything is sent.
 *
 * A QueryException, so existing catch blocks keep working; catch this class to tell the refusal
 * apart without reading the message. No database failure stands behind it: $sqlState and
 * $driverCode are null. getPrevious() is what PDO threw when its transaction state could not be
 * read (refused as well, fail-closed), null otherwise.
 */
class LockOutsideTransactionException extends QueryException
{
    public function __construct(
        string $message = 'Query refused: a row lock outside of a transaction',
        ?Throwable $previous = null,
        ?string $debugMessage = null
    ) {
        // No database failure stands behind the refusal - nothing was sent: the codes stay null, also
        // when the previous exception is what PDO threw while its state was read
        parent::__construct($message, $previous, $debugMessage, listenerFailure: true);
    }
}
