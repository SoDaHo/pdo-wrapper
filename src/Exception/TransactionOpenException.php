<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown by reconnect() while a transaction begun through the library is open
 * (currentTransaction() is not null): the old session would take it along - nothing of it is
 * committed - and what the caller does next would run in autocommit on the new connection,
 * outside of the transaction it believes to be in. Nothing has changed when it is thrown - the
 * old connection and the transaction are still in place.
 *
 * End the transaction first (commit() or rollback()), or call reconnect(dropTransaction: true) to
 * give it up knowingly: it then ends as 'lost'. A ConnectionException like reconnect()'s other
 * failures; catch this class to tell "transaction open" apart without reading the message. No
 * database failure stands behind it: $sqlState and $driverCode are null.
 */
class TransactionOpenException extends ConnectionException
{
    public function __construct(
        string $message = 'Database connection failed',
        ?Throwable $previous = null,
        ?string $debugMessage = null
    ) {
        parent::__construct($message, $previous, $debugMessage);
    }
}
