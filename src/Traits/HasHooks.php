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
 * - one a rollback or end listener leaves open is not checked and stays open: it is that
 *   listener's to end.
 *
 * transaction() and updateMultiple() end only the transaction they began. Once that one has been
 * ended through this library inside the callback or a listener - a commit() or rollback() of
 * theirs -, whatever is open afterwards was begun later (by the callback, by an end listener;
 * through this library or on raw PDO) and is neither committed nor rolled back in its name: a
 * callback that returns gets a CommitFailedException with outcome 'lost' and no COMMIT is sent,
 * one that throws gets its exception back, and the open transaction is left to whoever began it.
 * A transaction ended and begun again on raw PDO alone is not told apart from the first.
 *
 * Not paired and other caveats: a failing explicit commit() or rollback() fires nothing (the
 * transaction is still the caller's to end; the one exception is the failed or refused commit of
 * a transaction PDO no longer reports, see below), and so does the raw rollback after a throwing
 * 'transaction.begin' listener. In a custom driver, a commit()/rollback() override that does not
 * call the parent dispatches no 'transaction.end' for that call, and a beginTransaction() override
 * that does not call the parent leaves this library unaware of the transaction (no 'lost' for it).
 * An override that ends or begins transactions of its own around the parent call is outside of
 * what is told here. One that commits on raw PDO and begins again on raw PDO - a rollback()
 * override before it calls the parent, or a commit() override after the parent failed, before
 * it throws that failure on - gets the rollback of the second transaction told as the end of
 * the one whose commit failed ('rolled_back', also in CommitFailedException::$outcome),
 * although its data is committed. A commit() override that runs another commit() through this
 * library before it throws the parent's failure on leaves that failure without an outcome.
 *
 * A transaction the server has ended although PDO still reports it: on PostgreSQL every statement
 * error aborts the transaction unless a savepoint catches it; on MySQL/MariaDB a deadlock rolls
 * it back (and a lock wait timeout under innodb_rollback_on_timeout). In both cases the server
 * would answer the COMMIT with success. After a statement failed inside the transaction, commit()
 * therefore refuses with a CommitFailedException ('Failed to commit transaction', previous: the
 * statement failure that ended it) instead of sending the COMMIT: after a MySQL/MariaDB deadlock
 * always; otherwise after asking the server with one probe statement on raw PDO, sent only then
 * (PostgreSQL: is the transaction aborted; MySQL/MariaDB: does it still exist). What follows:
 * - PDO still reports the transaction (PostgreSQL always; MySQL/MariaDB unless raw PDO or the
 *   probe told it otherwise): the refusal fires nothing, the transaction is the caller's to roll
 *   back, and a manual commit() stays refused until rollback(). In transaction()/updateMultiple()
 *   the rollback follows and 'transaction.end' reports 'rolled_back' with that exception.
 * - PDO reports no transaction any more (MySQL/MariaDB once a statement on raw PDO, or the probe,
 *   told it): nothing is left to roll back, so the refusal itself tells the end as 'lost' with
 *   that exception - also on a manual commit(), the one failed commit that fires an event. What
 *   ran after the server ended the transaction - on raw PDO after a deadlock, through this
 *   library after a lock wait timeout that ended it - ran outside of it and stays committed,
 *   which is exactly what 'lost' warns of.
 * After a MySQL/MariaDB deadlock this library accepts nothing on that connection but the end of
 * the transaction: a statement would run outside of it and be committed on its own, so query()
 * throws a QueryException instead (previous: the deadlock; neither 'query' nor 'error' fires for
 * it) - as PostgreSQL does by itself in an aborted transaction - and beginTransaction() refuses.
 * That holds for a listener's statements too: an 'error' listener that writes to the database
 * needs its own connection. rollback() is the way out, for a transaction begun through this
 * library also when PDO no longer reports it (a statement on raw PDO told it): nothing is sent
 * then, and the end is told as 'lost' with the deadlock as error instead of failing for want of
 * a transaction (end listener failures reach only the 'error' hook there). A lock wait timeout
 * does not end the transaction by default and holds nothing back.
 * A commit that fails once it was sent (PDO::commit() throws or returns false) is a
 * CommitFailedException as well, and follows the same two cases: while PDO still reports the
 * transaction it fires nothing and the transaction is the caller's to roll back
 * (transaction()/updateMultiple() do that); when PDO reports none any more - PostgreSQL after a
 * COMMIT rejected by a deferred constraint, a commit after a raw COMMIT or a MySQL DDL statement -
 * the failed commit itself tells the end as 'lost', at once, on a manual commit() too.
 * CommitFailedException::$outcome is set in two places only. transaction()/updateMultiple() set
 * it for the commit they run themselves: the outcome they tell with it as error, before the end
 * listeners run - 'rolled_back' (the rollback is confirmed, nothing is committed) or 'lost' -
 * and 'lost' where no end was told with it (the callback had ended the transaction itself: no
 * COMMIT is sent then; in a custom driver also a rollback that told no end); never null. And
 * commit() sets 'lost' when it tells the end itself,
 * as just described. Every other commit() a caller issues - directly, inside a callback, inside
 * a listener - keeps null, whoever ends the transaction afterwards: thrown out of a
 * transaction() callback, such an exception is the error of that transaction's end like any
 * other exception of the callback, and is not written to. A transaction begun on raw PDO whose
 * commit through this library fails and takes it away is told as 'lost' as well (its successful
 * commit would have told 'committed') - not while a 'lost' told for a transaction that may still
 * be open is pending (see above: such a mark stays until this library ends or begins one).
 * A transaction this library began that PDO no longer reports when the next one is begun (ended by
 * an implicit commit, by the server, or on raw PDO) is told as 'lost' by that beginTransaction(),
 * before the new transaction's 'transaction.begin' - except after a deadlock, where
 * beginTransaction() refuses (see above) and rollback() tells the end. A transaction that an end
 * listener of that 'lost' begins and loses in the same way is told next, before the new one
 * begins; when the listeners leave a third one behind, beginTransaction() throws a
 * TransactionException and begins nothing (the next call tells the end that is still owed).
 * All of this is described for autocommit, the default. With autocommit switched off
 * (PDO::ATTR_AUTOCOMMIT, SET autocommit = 0) a statement after the transaction's end is not
 * committed on its own but opens the next transaction: after a deadlock the refusals above hold
 * all the same, and the rollback undoes that statement too.
 * So a callback that swallows such an error and returns no longer gets a 'committed'. Not seen:
 * statements that failed on raw PDO (getPdo()) - on PostgreSQL the next statement through this
 * library fails as a consequence and is remembered in their place - and rows that fail while a
 * result is fetched. After a deadlock, end the transaction through this library (rollback()): a
 * transaction begun on raw PDO after a raw rollback would have its statements and its commit
 * refused for the old deadlock until rollback() is called. With MySQL/MariaDB
 * autocommit switched off, a swallowed failure other than a deadlock that ended the transaction
 * (the lock wait timeout above) is not told apart from a statement-only failure once a later
 * statement has opened the next transaction: that commit goes through.
 * With PDO::ERRMODE_WARNING and an error handler that throws, a failed statement still arrives as
 * QueryException (and is remembered), a failed lastInsertId() likewise; a failing BEGIN, COMMIT or
 * ROLLBACK arrives as the handler's exception (not as TransactionException or
 * CommitFailedException, and without an outcome).
 *
 * A session that chains transactions (MySQL/MariaDB completion_type=CHAIN) is not supported and
 * is reported, because the caller would continue inside a transaction nobody commits. When PDO
 * reports a transaction right after a COMMIT: CommitHookException with a TransactionException
 * 'Connection is in a new transaction' as first failure, every commit listener skipped and listed,
 * connectionInTransaction true, 'transaction.end' 'committed'. Right after a ROLLBACK: the
 * rollback and end listeners run, then that TransactionException reaches the caller. Where another
 * exception reaches the caller instead - a rollback listener's, or on the automatic rollback the
 * one that ended the transaction - the 'error' hook is told about the chained transaction (sql '',
 * params [], error, code, outcome 'rolled_back', exception). The end, rollback and error listeners
 * of such a commit or rollback already run inside the chained transaction: what they write there
 * is not committed, and a beginTransaction() of theirs fails.
 *
 * 'query' and 'error': the payload carries the SQL and the parameters as passed - passwords,
 * tokens and personal data included; redact before logging. 'error' also fires, with code 0, for
 * a parameter that must not be bound (an array, a resource, an object without __toString(), a
 * RawExpression; the statement is not sent). insert() reads the new id before
 * the 'query' listeners run, so a listener may insert on the same connection. A listener that
 * commits a transaction through this library runs every 'transaction.commit' listener again for
 * that inner commit, itself included: guard against the recursion.
 *
 * Events: 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback', 'transaction.end'.
 * on() accepts any name (a custom driver may trigger its own): a misspelled one never fires.
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
