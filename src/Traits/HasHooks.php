<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Traits;

/**
 * Provides event hook functionality for database operations.
 *
 * "Fail Hard" implementation for 'query', 'error', 'transaction.begin' and 'transaction.rollback':
 * exceptions in those hooks bubble up to the caller (a PDOException from a 'transaction.begin' or
 * 'transaction.rollback' hook arrives as TransactionException) and the first failing hook stops the
 * remaining ones (after a failing 'transaction.begin' hook a rollback of the new transaction is
 * attempted, best effort, see AbstractDriver). 'transaction.commit' and 'transaction.end' listeners
 * run after the fact, so all of them run and their failures are collected: a commit listener's in a
 * CommitHookException - unless a transaction left open by a commit listener cannot be rolled back
 * (or the connection state cannot be read); the remaining commit listeners are then skipped and
 * listed as failures (see AbstractDriver) - and an end listener's as described below.
 * 'transaction.rollback' listeners run only after a rollback this library performed and that
 * succeeded (rollback(), or the automatic rollback in transaction()/updateMultiple()). Measured on
 * MySQL 8.0 and MariaDB 11.4 with mysqlnd: after a deadlock (transaction rolled back by the server)
 * and after a lock wait timeout (only the statement rolled back) PDO still reports the transaction,
 * the library's ROLLBACK succeeds and the listeners run; after a lost connection the rollback fails,
 * no 'transaction.rollback' listener runs, and 'transaction.end' reports 'lost'.
 *
 * 'transaction.end' fires exactly once for every transaction this library ends, after the
 * 'transaction.commit' or 'transaction.rollback' listeners, with
 * array{outcome: 'committed'|'rolled_back'|'lost', error: ?Throwable}
 * (DatabaseInterface::TRANSACTION_COMMITTED, TRANSACTION_ROLLED_BACK, TRANSACTION_LOST).
 *
 * Outcomes:
 * - 'committed': PDO::commit() succeeded; fires even when commit listeners failed or were skipped;
 *   error is null.
 * - 'rolled_back': the library's ROLLBACK succeeded - rollback() (error null), or the automatic
 *   rollback in transaction()/updateMultiple() (error: the exception that ended the transaction;
 *   after a failed commit the commit's exception - nothing was committed).
 * - 'lost': the transaction ended without a commit by this library and no rollback could be
 *   confirmed: the rollback failed (lost connection), PDO no longer reported the transaction, the
 *   connection state could not be read, the raw cleanup of a commit listener's transaction did not
 *   end it (MySQL completion_type=CHAIN), or the commit failed and so did the rollback after it (or
 *   PDO reported no transaction after the failed commit) - then the data may be committed,
 *   fail-closed, and error is the commit's exception: PostgreSQL leaves that state behind when COMMIT
 *   fails on a deferred constraint (the server rolled back), but so does a callback that committed
 *   itself with a raw COMMIT or a MySQL DDL statement (the data is committed). The connection may be
 *   gone: listeners must not expect queries to work. If the transaction may in fact still be open
 *   (the rollback failed, the raw cleanup of a commit listener's transaction did not end it, or the
 *   state could not be read), end it with rollback() or discard the connection: that rollback() (or
 *   a commit()) runs its listeners but tells no second end. Ending it on raw PDO instead leaves that
 *   mark in place: a transaction then begun on raw PDO and ended through this library tells no end
 *   (beginTransaction() clears the mark; inside commit listeners the check after each listener
 *   clears it too once PDO reports no transaction).
 *
 * Failures of 'transaction.end' listeners (all of them run):
 * - after a commit: in CommitHookException::$failures, behind the commit listeners' failures - the
 *   ends of transactions commit listeners left open first, then the committed transaction's end;
 * - after an explicit rollback(): as TransactionException (the first failure; all of them also reach
 *   the 'error' hook); a rollback listener's exception takes precedence, the end failures then reach
 *   only the 'error' hook;
 * - on the automatic rollback in transaction()/updateMultiple() and on a 'lost' reported there (not
 *   the buffered ends of transactions commit listeners left open: those join the CommitHookException):
 *   only via the 'error' hook (sql '', params [], error, code, plus hook 'transaction.end', outcome
 *   and exception; a throwing 'error' listener is ignored there), so that the exception that ended
 *   the transaction reaches the caller unchanged.
 *
 * Transactions started inside listeners:
 * - inside a 'transaction.commit' listener: it ends, with its own 'transaction.end', before the outer
 *   one is dispatched - also when the listener leaves it open: it is then rolled back without
 *   'transaction.rollback' hooks and, if it was begun through this library (not on raw PDO), gets
 *   'transaction.end' 'rolled_back' (or 'lost' when that rollback fails, does not end it, or the
 *   state cannot be read) with a LogicException as error, after all commit listeners ran, and is
 *   listed in the CommitHookException; one the listener ended itself on raw PDO or implicitly (a
 *   DDL statement) is simply gone, without an event - but do not then begin another transaction on
 *   raw PDO in the same listener: this library cannot tell that one from the first and would give it
 *   the first one's end (not supported);
 * - inside a 'transaction.end' listener: it ends inside that listener, before the remaining outer
 *   'transaction.end' listeners run;
 * - one a rollback or end listener leaves open is not checked and stays open.
 *
 * Not paired and other caveats: a failing explicit commit() or rollback() fires nothing (the
 * transaction is still the caller's to end), and so does the raw rollback after a throwing
 * 'transaction.begin' listener. In a custom driver, a commit()/rollback() override that does not
 * call the parent dispatches no 'transaction.end' for that call, and a beginTransaction() override
 * that does not call the parent leaves this library unaware of the transaction (no 'lost' for it).
 * A callback that swallows a database
 * error and returns commits nothing on PostgreSQL (COMMIT of an aborted transaction is a silent
 * ROLLBACK) while 'transaction.commit' and 'transaction.end' report 'committed': do not swallow
 * errors inside transaction() without a savepoint.
 * Events: 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback', 'transaction.end'
 */
trait HasHooks
{
    /** @var array<string, array<callable>> */
    private array $hooks = [];

    /**
     * Register a callback for an event.
     *
     * @param string $event Event name
     * @param callable $callback Callback receiving event data array
     */
    public function on(string $event, callable $callback): static
    {
        $this->hooks[$event][] = $callback;

        return $this;
    }

    /**
     * Trigger all callbacks for an event.
     *
     * @param string $event Event name
     * @param array<string, mixed> $data Event data to pass to callbacks
     */
    protected function trigger(string $event, array $data): void
    {
        foreach ($this->hooks[$event] ?? [] as $callback) {
            $callback($data);
        }
    }
}
