<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Exception;
use Throwable;

/**
 * Base exception for all pdo-wrapper exceptions.
 *
 * What the database said stands in two properties, as PDO reports it: $sqlState and $driverCode.
 * getCode() is always 0: PHP's exception code is an integer and cannot hold an SQLSTATE such as
 * '42S02'.
 */
class DatabaseException extends Exception
{
    /**
     * The SQLSTATE of the database failure behind this exception - '42S02', '23000', 'HY000' -
     * or null when no database failure stands behind it (a refused argument) and where this
     * library puts the exception around what a listener threw after the operation went through
     * ('Query hook failed', CommitHookException, a failing rollback or end listener): the codes say
     * how the operation itself failed, and there the database did what it was asked - a listener's
     * deadlock must not look like a reason to run a committed transaction again (its codes are in
     * getPrevious()). A failing begin listener makes the begin fail and hands its codes on -
     * unless the transaction it was told about ended behind the library's back or could not be
     * found out ('lost'): nothing is certain there, and there are none. Five characters, as the
     * database sent them: compare as a string.
     */
    public readonly ?string $sqlState;

    /**
     * The driver's own error code for that failure, or null: MariaDB's error number (1062
     * duplicate entry, 1146 no such table, 1213 deadlock).
     */
    public readonly ?int $driverCode;

    /**
     * @param Throwable|null $previous The cause. A PDOException's errorInfo, or the codes of a DatabaseException, become $sqlState and $driverCode
     * @param bool $listenerFailure True when $previous is what a listener threw and not the failure of the operation this exception is about: $sqlState and $driverCode stay null
     */
    public function __construct(
        string $message = 'Database error',
        ?Throwable $previous = null,
        protected ?string $debugMessage = null,
        bool $listenerFailure = false
    ) {
        parent::__construct($message, 0, $previous);

        [$this->sqlState, $this->driverCode] = $listenerFailure ? [null, null] : Codes::behind($previous);
    }

    /**
     * Get detailed debug message.
     *
     * @return string|null Debug information
     */
    public function getDebugMessage(): ?string
    {
        return $this->debugMessage;
    }

}
