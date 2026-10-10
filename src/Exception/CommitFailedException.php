<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use LogicException;

/**
 * Thrown by commit() when the commit failed or was refused: PDO::commit() failed, or the driver
 * refused to send the COMMIT because the server had already ended the transaction (see
 * AbstractDriver::commit()). What the commit listeners and end listeners of a commit that went
 * through report is a CommitHookException instead.
 *
 * $outcome says what became of the transaction, in the words of the 'transaction.end' hook. Two
 * callers set it, and nothing else does:
 * - transaction() and updateMultiple(), for the commit they run themselves: the outcome they told
 *   their 'transaction.end' listeners with this exception as error, set before those listeners
 *   run - or 'lost' when no end was told with it: the callback had ended the transaction itself
 *   (no COMMIT is sent then; whatever is open afterwards is not theirs and is left alone). Never
 *   null.
 * - commit() itself, when the failed commit left no transaction behind and it therefore told
 *   the end right there: 'lost'.
 * Every other commit() a caller issues - directly, inside a transaction() callback, inside a
 * listener - leaves null: the transaction is the caller's to end, and whoever ends it afterwards
 * (the caller's rollback(), or transaction() when the exception leaves its callback) does not
 * write into the exception. An exception thrown again later, or handed on from another
 * connection, is never written to either.
 *
 * The values:
 * - DatabaseInterface::TRANSACTION_ROLLED_BACK: the rollback after the failed commit is confirmed.
 *   Nothing of the transaction whose commit failed is committed.
 * - DatabaseInterface::TRANSACTION_LOST: no rollback could be confirmed. The commit may or may not
 *   have taken effect; after a refused commit, what ran after the transaction's end on the server
 *   is committed on its own.
 * - null: not ended with this exception by one of the two callers above.
 *
 * Only 'rolled_back' means that nothing is committed; treat every other value as unclear.
 */
class CommitFailedException extends TransactionException
{
    /**
     * Readable by everyone, written through settle() alone - once. No readonly property: the
     * exception exists before the outcome is known, and the caller gets the very object the
     * listeners were handed.
     *
     * @var 'rolled_back'|'lost'|null
     */
    public private(set) ?string $outcome = null;

    /**
     * Says what became of the transaction. Called by the driver when it ends the transaction
     * (see above), before the 'transaction.end' listeners run - and only once: what was told
     * stays told. Private: the driver reaches it through a closure bound to this class, and no
     * listener or application code that is handed the exception can write an outcome into it (a
     * test that needs an exception with an outcome binds a closure the same way).
     *
     * @param 'rolled_back'|'lost' $outcome
     *
     * @throws LogicException When an outcome has been told already
     */
    // @phpstan-ignore method.unused (called through a closure bound to this class: AbstractDriver::settle())
    private function settle(string $outcome): void
    {
        if ($this->outcome !== null) {
            throw new LogicException('The outcome of this failed commit has already been told');
        }

        $this->outcome = $outcome;
    }
}
