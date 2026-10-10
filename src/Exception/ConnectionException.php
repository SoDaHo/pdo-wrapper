<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown when a database connection fails.
 *
 * $refusal tells why the library refused a connection it could open - the server, the client or
 * the connection's NULL mode, see ConnectionRefusal -, so that a caller (a health check) can name
 * the cause without reading the message. It is null for every other failure: a connection that
 * could not be opened (its PDOException is getPrevious(), its codes are $sqlState and
 * $driverCode), a refused configuration, a reconnect() that cannot be done. That PDOException
 * holds the DSN, the username and the options - an INIT_COMMAND among them - in its trace while
 * zend.exception_ignore_args is off (PDO keeps the password out), and serialize() then fails on
 * the connector Closure in it.
 */
class ConnectionException extends DatabaseException
{
    /**
     * The parameters of DatabaseException, then the refusal.
     */
    public function __construct(
        string $message = 'Database error',
        ?Throwable $previous = null,
        ?string $debugMessage = null,
        bool $listenerFailure = false,
        public readonly ?ConnectionRefusal $refusal = null
    ) {
        parent::__construct($message, $previous, $debugMessage, $listenerFailure);
    }
}
