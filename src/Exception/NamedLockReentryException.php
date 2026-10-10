<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown by namedLock() (DatabaseInterface) for a named lock this connection holds already: MariaDB
 * would count a second hold, and one release would not free the lock.
 *
 * A QueryException like the method's other failures, so existing catch blocks keep working;
 * catch this class to tell "held here already" - often a matter of the application (a second
 * step of the same request) - from a failure of the server, without reading the message.
 * $lockName and the debug message carry the name, which may identify a person ("login:<user id>"):
 * do not log it (with redactParameters the debug message does not name it; $lockName does).
 */
class NamedLockReentryException extends QueryException
{
    /**
     * @param string $lockName The lock's name as passed to namedLock(), without the prefix
     */
    public function __construct(
        string $message = 'Query failed',
        ?Throwable $previous = null,
        ?string $debugMessage = null,
        public readonly string $lockName = ''
    ) {
        parent::__construct($message, $previous, $debugMessage);
    }
}
