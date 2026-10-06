<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown by reconnect() while this driver holds named locks it took with namedLock(): the old
 * session would take them along, and whoever relies on them would no longer be protected. Nothing
 * has changed when it is thrown - the old connection is still in place.
 *
 * Release the locks first, or call reconnect(dropNamedLocks: true) to give them up knowingly (on a
 * connection that is gone, releaseNamedLock() cannot reach the server any more). A
 * ConnectionException like reconnect()'s other failures; catch this class to tell "locks held"
 * apart without reading the message.
 */
class NamedLocksHeldException extends ConnectionException
{
    /**
     * @param list<string> $lockNames The locks this driver holds, as passed to namedLock(), without the prefix
     */
    public function __construct(
        string $message = 'Database connection failed',
        ?Throwable $previous = null,
        ?string $debugMessage = null,
        public readonly array $lockNames = []
    ) {
        parent::__construct($message, $previous, $debugMessage);
    }
}
