<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown when the transaction was committed, but a 'transaction.commit' listener failed or the
 * connection state could not be verified or cleaned up after a listener.
 *
 * The data is committed; the committed transaction cannot be rolled back (a transaction a
 * listener left open is another matter: see $connectionInTransaction). All listeners have run (unless a
 * transaction left open by a listener could not be rolled back, or the connection state could
 * not be read; the remaining listeners are then skipped and listed in $failures, and
 * $connectionInTransaction is true). getPrevious() is the first failure.
 */
class CommitHookException extends DatabaseException
{
    /**
     * @param Throwable $first First failure, used as previous
     * @param list<Throwable> $failures All failures in listener order
     * @param bool $connectionInTransaction True when the connection is, or may still be, in a transaction
     *                                      a listener left open: its rollback failed or did not end the
     *                                      transaction (MySQL completion_type=CHAIN), or the connection state
     *                                      could not be read (reported as true, fail-closed). Do not run
     *                                      further statements as if the connection were in autocommit then:
     *                                      check inTransaction() and roll back, or discard the connection.
     */
    public function __construct(Throwable $first, public readonly array $failures, public readonly bool $connectionInTransaction = false)
    {
        parent::__construct(
            message: 'Transaction committed, but a transaction.commit hook failed or the connection state after it could not be verified',
            code: (int)$first->getCode(),
            previous: $first,
            debugMessage: sprintf(
                '%d failure(s), first: %s: %s',
                count($failures),
                $first::class,
                $first->getMessage()
            )
        );
    }
}
