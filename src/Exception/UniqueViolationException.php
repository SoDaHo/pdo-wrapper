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
     * @param string|null $constraint Name of the violated key or constraint as the database reports
     *                                it: the index name on MySQL/MariaDB (`PRIMARY` for the primary
     *                                key), the constraint name on PostgreSQL (`users_email_key`,
     *                                `users_pkey`). Null where the database names none (SQLite
     *                                reports columns only) or the name could not be read: it is
     *                                taken from the server's English error message, so a server
     *                                set to another message language yields null. MySQL since
     *                                8.0.19 prints `table.key`, MariaDB and older MySQL versions
     *                                `key`: all yield the key. Where the table is in front, a
     *                                table or key name that contains a dot itself makes the
     *                                printed name ambiguous: null rather than a wrong name; so
     *                                does a dot wherever the server's version cannot be read. The
     *                                server is told by its version string: behind a proxy (or on
     *                                a MySQL-compatible server) whose version does not match
     *                                its message format, an older version in front of a newer
     *                                MySQL yields `table.key` for every key, and a newer one in
     *                                front of MariaDB or an older MySQL cuts a key name with a dot.
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
