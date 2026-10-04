<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown when a statement violates a UNIQUE constraint or a primary key: the row exists already.
 *
 * A QueryException like any other failed statement, so existing catch blocks keep working; catch
 * this class to tell "duplicate" from every other failure without looking at driver error codes.
 */
class UniqueViolationException extends QueryException
{
    /**
     * @param string|null $constraint Name of the violated key as MariaDB reports it: the index
     *                                name (`PRIMARY` for the primary key), without its table, a
     *                                name with a dot included. Null where the name could not be
     *                                read: it is taken from the server's English error message,
     *                                so a server set to another message language yields null.
     */
    public function __construct(
        string $message = 'Query failed',
        ?Throwable $previous = null,
        ?string $debugMessage = null,
        public readonly ?string $constraint = null
    ) {
        parent::__construct($message, $previous, $debugMessage);
    }
}
