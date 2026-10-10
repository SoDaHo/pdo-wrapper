<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown by query() (and everything that goes through it) for a statement that would commit the
 * open transaction implicitly - on MariaDB DDL such as CREATE TABLE, LOCK TABLES, GRANT (see
 * Driver\ImplicitCommit) - inside a transaction begun through this library. MariaDB commits before
 * such a statement runs, also when it then fails, and what the transaction does afterwards would run
 * in autocommit, each statement committed on its own: the statement is refused before it is sent,
 * the transaction stays open and intact.
 *
 * A QueryException, so existing catch blocks keep working; catch this class to tell the refusal
 * apart without reading the message. Run such a statement outside of transactions. No database
 * failure stands behind it: $sqlState and $driverCode are null. $statement names its leading
 * keywords ("CREATE", "LOCK"), never a value - or "VERSIONED COMMENTS" for a statement with an
 * executable comment (`/*!...*\/`, `/*M!...*\/`) before its leading keywords are decided, which is
 * not judged (fail-closed, see Driver\ImplicitCommit).
 */
class ImplicitCommitException extends QueryException
{
    /**
     * @param string $statement The leading keywords that commit implicitly, upper case ("VERSIONED COMMENTS": an executable comment before they are decided, not judged)
     */
    public function __construct(
        string $message = 'Query refused: the statement would commit the transaction implicitly',
        ?Throwable $previous = null,
        ?string $debugMessage = null,
        public readonly string $statement = ''
    ) {
        parent::__construct($message, $previous, $debugMessage);
    }
}
