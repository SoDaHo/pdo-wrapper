<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Throwable;

/**
 * Thrown when the transaction was committed, but a 'transaction.commit' or 'transaction.end'
 * listener failed, the connection state could not be verified or cleaned up after a commit
 * listener, or the connection was in a new transaction right after the COMMIT (a session that
 * chains transactions, completion_type=CHAIN: no commit listener ran, the first
 * failure says so).
 *
 * The data is committed; the committed transaction cannot be rolled back (a transaction a commit
 * listener left open is another matter: see $connectionInTransaction). All listeners have run (unless a
 * transaction left open by a commit listener could not be rolled back, the connection state could
 * not be read, or the session chained a new transaction to the COMMIT; the remaining commit
 * listeners - with a chained transaction all of them - are then skipped and listed in $failures,
 * and $connectionInTransaction is true; the end listeners still run). $failures lists the commit
 * listeners' failures first, then the failures of the 'transaction.end' listeners for transactions
 * commit listeners left open, then those of the committed transaction's end. getPrevious() is the
 * first failure.
 *
 * All of this is what the exception says when the library throws it. One that arrives through
 * commit() from elsewhere - thrown by the commit() of a caller's PDO class ('pdoClass'), or by an
 * error handler - is passed on unchanged and says nothing about the transaction: transaction()
 * and updateMultiple() end theirs as after any other failure, and nothing is committed.
 */
class CommitHookException extends DatabaseException
{
    /**
     * @param Throwable $first First failure, used as previous
     * @param list<Throwable> $failures All failures in listener order
     * @param bool $connectionInTransaction True when the connection is, or may still be, in a transaction:
     *                                      one a commit listener left open whose rollback failed or did not
     *                                      end it, one the session chained to the COMMIT
     *                                      (completion_type=CHAIN), or the connection state could not be
     *                                      read (reported as true, fail-closed). Do not run
     *                                      further statements as if the connection were in autocommit then:
     *                                      check inTransaction() and roll back, or discard the connection.
     *                                      Computed before the 'transaction.end' listeners run: what they
     *                                      leave open is not checked and not reflected here.
     */
    public function __construct(Throwable $first, public readonly array $failures, public readonly bool $connectionInTransaction = false)
    {
        parent::__construct(
            message: 'Transaction committed, but a transaction.commit or transaction.end hook failed, the connection state after the commit could not be verified, or the connection is in a new chained transaction',
            previous: $first,
            debugMessage: sprintf(
                '%d failure(s), first: %s: %s',
                count($failures),
                $first::class,
                $first->getMessage()
            ),
            listenerFailure: true // committed: the database did what it was asked
        );
    }
}
