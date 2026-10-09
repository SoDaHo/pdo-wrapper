<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Traits;

use Sodaho\PdoWrapper\Exception\DatabaseException;

/**
 * Provides event hook functionality for database operations.
 *
 * The transaction events name their transaction: 'transaction.begin' with
 * ['transaction' => int, 'depth' => int], 'transaction.commit' and 'transaction.rollback' with
 * ['transaction' => ?int, 'depth' => ?int], 'transaction.end' with ['outcome', 'error',
 * 'transaction', 'depth']. The number counts the transactions begun through the driver, from 1,
 * for as long as the driver lives; the depth is 1 for one begun while no other owed its end. A
 * transaction begun on raw PDO carries null for both. Every told begin is followed by exactly one
 * end with the same number - also after a throwing begin listener ('rolled_back' after the raw
 * rollback, or 'lost' when it failed; no rollback listener runs) and for a transaction a commit
 * listener began and ended on raw PDO ('lost'). A BEGIN that fails in PDO tells nothing. The
 * numbers are the library's: a 'transaction.begin' or 'transaction.rollback' listener that takes
 * the payload by reference and changes them changes what the listeners after it are told (these
 * two, like 'query' and 'error', hand one payload from listener to listener), not what the library
 * tells later. 'transaction.commit' and 'transaction.end' listeners get an array of their own each
 * and cannot take it by reference: PHP throws an Error, which is that listener's failure.
 *
 * "Fail Hard" implementation for 'query.before', 'query', 'error', 'transaction.begin' and 'transaction.rollback':
 * exceptions in those hooks bubble up to the caller (a PDOException from a 'transaction.begin' or
 * 'transaction.rollback' hook arrives as TransactionException) and the first failing hook stops the
 * remaining ones (after a failing 'transaction.begin' hook a rollback of the new transaction is
 * attempted, best effort, see AbstractDriver - unless the hook ended it itself; a transaction it
 * began afterwards is left open, with its end owed). 'transaction.commit' and 'transaction.end' listeners
 * run after the fact, so all of them run and their failures are collected: a commit listener's in a
 * CommitHookException - unless a transaction left open by a commit listener cannot be rolled back
 * (or the connection state cannot be read); the remaining commit listeners are then skipped and
 * listed as failures (see AbstractDriver) - and an end listener's as described below.
 * 'transaction.rollback' listeners run only after a rollback this library performed and that
 * succeeded (rollback(), or the automatic rollback in transaction()/updateMultiple()). Measured on
 * MariaDB 11.4 with mysqlnd: after a deadlock or a 1020 (transaction rolled back by the server)
 * and after a lock wait timeout (only the statement rolled back) PDO still reports the transaction,
 * the library's ROLLBACK succeeds and the listeners run; after a lost connection the rollback fails,
 * no 'transaction.rollback' listener runs, and 'transaction.end' reports 'lost'. Before a ROLLBACK
 * that follows a failed statement, a driver may be asked whether the transaction still exists
 * (AbstractDriver::refreshTransactionState()). The MariaDB driver is: a statement with an implicit
 * commit (most DDL: CREATE TABLE, ALTER TABLE - not CREATE TEMPORARY TABLE) commits the open
 * transaction even when it fails itself (CREATE TABLE for a table that exists), and does not
 * tell the client - PDO keeps reporting the transaction, and the ROLLBACK would go through over
 * committed rows (measured on MariaDB 10.11 and 11.4). One no-op statement on raw PDO
 * makes the server say; when the transaction is gone, nothing is sent, no rollback listener runs
 * and 'transaction.end' reports 'lost' (error: the exception that ended the transaction, on a
 * manual rollback() the remembered statement failure). A lock wait timeout under
 * innodb_rollback_on_timeout, which ends the whole transaction, is found gone the same way.
 * When the question itself fails while the connection goes on working, nothing is known: the
 * ROLLBACK is sent to clean up, but it confirms nothing - 'lost' as well, without rollback
 * listeners. Asked only while PDO reports the transaction: once a later statement has told PDO
 * that it is gone, a manual rollback() fails as before, and the next beginTransaction() tells
 * the end. Not asked after a deadlock or a 1020, which settles the matter - also when an earlier
 * failure of the same transaction was swallowed: a statement with an implicit commit that
 * failed, followed by a statement that runs into a deadlock, is still told as 'rolled_back'.
 *
 * 'transaction.end' fires exactly once for every transaction this library ends, after the
 * 'transaction.commit' or 'transaction.rollback' listeners, with
 * array{outcome: 'committed'|'rolled_back'|'lost', error: ?Throwable, transaction: ?int, depth: ?int}
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
 *   end it (completion_type=CHAIN), or the commit failed and so did the rollback after it (or
 *   PDO reported no transaction after the failed commit, or the session may chain transactions:
 *   the ROLLBACK after it then confirms nothing) - then the data may be committed,
 *   fail-closed, and error is the commit's exception (where another exception reaches the caller -
 *   transaction(), a throwing begin listener - that one). A callback that committed itself with a
 *   raw COMMIT or a DDL statement leaves that state behind (the data is committed). The connection may be
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
 *   only the 'error' hook - and so they do when that rollback() tells the end as 'lost';
 * - on the automatic rollback in transaction()/updateMultiple() and on a 'lost' reported there (not
 *   the buffered ends of transactions commit listeners left open: those join the CommitHookException):
 *   only via the 'error' hook (sql '', params [], error, code, sqlState, driverCode, plus hook 'transaction.end', outcome
 *   and exception; a throwing 'error' listener is ignored there), so that the exception that ended
 *   the transaction reaches the caller unchanged.
 *
 * Transactions started inside listeners:
 * - inside a 'transaction.commit' listener: it ends, with its own 'transaction.end', before the outer
 *   one is dispatched - also when the listener leaves it open: it is then rolled back without
 *   'transaction.rollback' hooks and, if it was begun through this library (not on raw PDO), gets
 *   'transaction.end' 'rolled_back' with a LogicException as error, or 'lost' - when that rollback
 *   fails, does not end it, or the state cannot be read (a LogicException as error), or when the
 *   listener's commit() of it failed on a session that may chain transactions (that failed commit
 *   as error) -, after all commit listeners ran, and is listed in the CommitHookException; one
 *   the listener ended itself on raw PDO or implicitly (a
 *   DDL statement) gets 'transaction.end' 'lost' (a TransactionException as error: it ended outside
 *   this library) - but do not then begin another transaction on raw PDO in the same listener: this
 *   library cannot tell that one from the first and would give it the first one's end (not supported);
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
 * A 'transaction.begin' listener that ends the transaction it was told about makes
 * beginTransaction() throw a TransactionException, and no further begin listener runs: they and
 * the caller - and with it the callback of transaction() and the batch of updateMultiple() -
 * would go on outside of the transaction that was asked for, or inside one somebody began
 * afterwards. An end behind this library's back (an implicit commit by a DDL statement on
 * MariaDB, raw PDO) is told as 'lost' with that exception as error before it is thrown.
 * After a statement of the listener that failed, the driver is asked before PDO's report is
 * trusted (refreshTransactionState(), as before a ROLLBACK): on MariaDB a DDL statement
 * commits the transaction even when it fails. Gone: 'lost', as above - also when the listener
 * let the failure escape. Not to be found out: the begin fails, a ROLLBACK cleans up, 'lost'
 * (when that ROLLBACK fails too, the transaction may still be open: its later rollback() tells
 * no second end). A failure that ended the transaction for certain (transactionIsOver(): a
 * deadlock or a 1020) fails the begin as well, also when the listener swallowed it: undone, no event.
 * The 'transaction.begin' listeners are called one by one by beginTransaction() itself, as the
 * 'transaction.commit' and 'transaction.end' listeners always were: an overriding trigger() does
 * not see these three events.
 *
 * Not paired and other caveats: a failing explicit commit() or rollback() fires nothing (the
 * transaction is still the caller's to end; the one exception is the failed or refused commit of
 * a transaction PDO no longer reports, see below). The raw rollback after a throwing
 * 'transaction.begin' listener fires no 'transaction.rollback', but tells the end: 'rolled_back',
 * or 'lost' when it failed (when that listener ended the transaction behind this library's back
 * before it threw, nothing is left to roll back and the end is 'lost' as well), with the exception
 * the caller gets as error. Every call into PDO may run foreign code - an error
 * handler for a PDO warning (PDO::ERRMODE_WARNING). When such a handler ends the transaction
 * through this library while a COMMIT, a ROLLBACK or the driver's question to the server is
 * under way, that call tells the end, and the one it interrupted tells none: transaction() and
 * updateMultiple() look again whether the transaction is still theirs, and a failed commit of
 * theirs leaves with outcome 'lost' then. What such a handler begins and ends itself stays its
 * own: the failed commit() of a transaction it began, or of a commit() it issued itself, is not
 * taken for the one transaction() ran. One limit, for a transaction begun on raw PDO: when it
 * vanished with its failed COMMIT and the handler runs more than one transaction of its own in
 * there, the 'lost' of the vanished one is not told (the ends are told apart only up to the
 * first transaction begun since). Likewise, after a failed COMMIT of a transaction begun on raw
 * PDO on a session that may chain: when it is ended on raw PDO and another one is begun there,
 * the rollback() of that one is 'lost' as well (fail-closed).
 * beginTransaction(), commit() and rollback() are final in
 * AbstractDriver: what is told here is decided in them. A custom driver extends them through
 * listeners and through the protected hooks (failureToRemember(), transactionEndedBy(),
 * transactionIsOver(), refreshTransactionState()), not by overriding them. A test that needs a
 * COMMIT to fail names a PDO class whose commit() fails ('pdoClass'): the methods here then take
 * the path they take for a real failure.
 *
 * A transaction the server has ended although PDO still reports it: on MariaDB a deadlock rolls
 * it back, and so does error 1020 under innodb_snapshot_isolation (both called "deadlock" below),
 * and a lock wait timeout under innodb_rollback_on_timeout. The server would then
 * answer the COMMIT with success. After a statement failed inside the transaction, commit()
 * therefore refuses with a CommitFailedException ('Failed to commit transaction', previous: the
 * statement failure that ended it) instead of sending the COMMIT: after a deadlock always;
 * otherwise after asking the server with one probe statement on raw PDO, sent only then (does
 * the transaction still exist). What follows:
 * - PDO still reports the transaction (neither raw PDO nor the probe told it otherwise): the
 *   refusal fires nothing, the transaction is the caller's to roll back, and a manual commit()
 *   stays refused until rollback(). In transaction()/updateMultiple()
 *   the rollback follows and 'transaction.end' reports 'rolled_back' with that exception.
 * - PDO reports no transaction any more (once a statement on raw PDO, or the probe, told it):
 *   nothing is left to roll back, so the refusal itself tells the end as 'lost' with
 *   that exception - also on a manual commit(), the one failed commit that fires an event. What
 *   ran after the server ended the transaction - on raw PDO, after a deadlock or another failure
 *   that ended it - ran outside of it and stays committed, which is exactly what 'lost' warns of.
 * After a MariaDB deadlock this library accepts nothing on that connection but the end of
 * the transaction: a statement would run outside of it and be committed on its own, so query()
 * throws a QueryException instead (previous: the deadlock; neither 'query' nor 'error' fires for
 * it), and beginTransaction() refuses.
 * That holds for a listener's statements too: an 'error' listener that writes to the database
 * needs its own connection. rollback() is the way out, for a transaction begun through this
 * library also when PDO no longer reports it (a statement on raw PDO told it): nothing is sent
 * then, and the end is told as 'lost' with the deadlock as error instead of failing for want of
 * a transaction (end listener failures reach only the 'error' hook there). A lock wait timeout
 * does not end the transaction by default and holds nothing back. After it, and after any other
 * failure that does not settle the matter by itself, the driver asks the server right away
 * inside a transaction this library began: found gone (a failing DDL statement committed it, the
 * timeout under innodb_rollback_on_timeout rolled it back) or not to be found out, nothing more is
 * sent until that transaction is ended here, and its end is 'lost'; the same while PDO reports no
 * transaction at all for it (a DDL statement that succeeded).
 * A commit that fails once it was sent (PDO::commit() throws or returns false) is a
 * CommitFailedException as well, and follows the same two cases: while PDO still reports the
 * transaction it fires nothing and the transaction is the caller's to roll back
 * (transaction()/updateMultiple() do that); when PDO reports none any more - a commit after a raw
 * COMMIT or a DDL statement - the failed commit itself tells the end as 'lost', at once, on a
 * manual commit() too.
 * CommitFailedException::$outcome is set in two places only. transaction()/updateMultiple() set
 * it for the commit they run themselves: the outcome they tell with it as error, before the end
 * listeners run - 'rolled_back' (the rollback is confirmed, nothing is committed) or 'lost' -
 * and 'lost' where no end was told with it (the callback had ended the transaction itself: no
 * COMMIT is sent then); never null. And
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
 * That bound holds for this check at the start of one beginTransaction() call. Listeners that
 * call each other through this library without end - an end listener that answers every end
 * with beginTransaction(), a begin listener that ends every transaction it is told about -
 * recurse like any two functions that call each other: guard against it in the listeners.
 * All of this is described for autocommit, the default. With autocommit switched off
 * (PDO::ATTR_AUTOCOMMIT, SET autocommit = 0) a statement after the transaction's end is not
 * committed on its own but opens the next transaction: after a deadlock the refusals above hold
 * all the same, and the rollback undoes that statement too. That next transaction is not told
 * apart from the one that ended - also not after a 'transaction.begin' listener whose DDL
 * statement committed the transaction just begun and whose next statement opened another: the
 * caller goes on in that one, and its rollback is told as the end of the first.
 * The question before a ROLLBACK after a failed statement holds there too (the no-op statement
 * opens no transaction, measured) - but not once a later statement of the caller has opened the
 * next transaction: that one is found in its place, and its rollback is told as 'rolled_back'
 * although a failing DDL statement committed what came before it.
 * So a callback that swallows such an error and returns no longer gets a 'committed'. Not seen:
 * statements that failed on raw PDO (getPdo()), and rows that fail while a result is fetched.
 * After a deadlock, end the transaction through this library (rollback()): a transaction begun
 * on raw PDO after a raw rollback would have its statements and its commit refused for the old
 * deadlock until rollback() is called. With autocommit switched off, a swallowed failure other
 * than a deadlock that ended the transaction
 * (the lock wait timeout above) is not told apart from a statement-only failure once a later
 * statement has opened the next transaction: that commit goes through.
 * With PDO::ERRMODE_WARNING and an error handler that throws, a failed statement still arrives as
 * QueryException (and is remembered), a failed lastInsertId() likewise; a failing BEGIN, COMMIT or
 * ROLLBACK arrives as the handler's exception (not as TransactionException or
 * CommitFailedException, and without an outcome).
 *
 * The MariaDB driver sets completion_type to NO_CHAIN when it connects (and at reconnect()). A
 * session switched to CHAIN afterwards (SET SESSION) is not supported and
 * is reported, because the caller would continue inside a transaction nobody commits. When PDO
 * reports a transaction right after a COMMIT: CommitHookException with a TransactionException
 * 'Connection is in a new transaction' as first failure, every commit listener skipped and listed,
 * connectionInTransaction true, 'transaction.end' 'committed' - the same with a TransactionException
 * 'Connection state unknown' when PDO's state cannot be read at that moment (a PDO class of the
 * caller's): a chained transaction may be open, fail-closed. Right after a ROLLBACK: the
 * rollback and end listeners run, then that TransactionException reaches the caller. Where another
 * exception reaches the caller instead - a rollback listener's, or on the automatic rollback the
 * one that ended the transaction - the 'error' hook is told about the chained transaction (sql '',
 * params [], error, code, sqlState, driverCode, outcome 'rolled_back', exception). After a ROLLBACK that confirmed
 * nothing (see above: the driver could not find out whether the transaction still existed) only
 * the end listeners run, and the outcome told to them and to the 'error' hook is 'lost'. The end, rollback and error listeners
 * of such a commit or rollback already run inside the chained transaction: what they write there
 * is not committed, and a beginTransaction() of theirs fails.
 *
 * 'query.before', 'query' and 'error': the payload carries the SQL and the parameters unredacted - passwords,
 * tokens and personal data included; redact before logging. 'error' also fires, with code 0, for
 * a parameter that must not be bound (an array, a resource, an object without __toString(), a
 * RawExpression; the statement is not sent). insert() reads the new id before
 * the 'query' listeners run, so a listener may insert on the same connection. A listener that
 * commits a transaction through this library runs every 'transaction.commit' listener again for
 * that inner commit, itself included: guard against the recursion.
 *
 * 'query.before': fires at the start of every query() - before the library's own checks, also for
 * a statement it then refuses -, with 'sql' and 'params' (the parameters as values: references
 * among them are told as what they hold). Changing them changes nothing of the statement; the
 * 'query.before' listeners after it see the change, as with 'query'. A listener that throws
 * stops the statement: nothing is sent, neither 'query' nor 'error' fires, and the exception
 * reaches the caller unchanged (a PDOException as QueryException 'Query hook failed'). For a test
 * that makes a statement fail before it runs. What a listener does counts for the statement: after
 * its commit() or rollback() the statement runs outside the transaction, after its reconnect() on
 * the new connection - as the same call in the callback would. A listener that runs a statement
 * of its own fires 'query.before' again: guard against the recursion.
 *
 * Events: 'query.before', 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback', 'transaction.end'.
 * on() throws for any other name: a misspelled one would never fire. A custom driver that
 * triggers events of its own names them in knownEvents().
 */
trait HasHooks
{
    /** @var array<string, array<callable>> */
    private array $hooks = [];

    /**
     * Register a callback for an event.
     *
     * @param string $event Event name, one of knownEvents()
     * @param callable $callback Callback receiving event data array
     *
     * @throws DatabaseException When the event is not one of knownEvents(): a listener for a name nothing triggers would never run
     */
    public function on(string $event, callable $callback): static
    {
        $known = $this->knownEvents();
        if (!in_array($event, $known, true)) {
            throw new DatabaseException(
                message: 'Unknown hook event',
                debugMessage: sprintf('Unknown event "%s": a listener for it would never run. Known events: %s', $event, implode(', ', $known))
            );
        }

        $this->hooks[$event][] = $callback;

        return $this;
    }

    /**
     * Remove a callback that was registered for an event - every registration of it, if it was
     * registered more than once. Callbacks are told apart by identity (`===`): the same closure
     * object, the same [object, 'method'] pair, the same function name. A listener removed while
     * the event is being told still runs for that telling; the change counts from the next one.
     *
     * @throws DatabaseException When the event is not one of knownEvents(), or the callback is not registered for it (a typo, or a second off())
     */
    public function off(string $event, callable $callback): static
    {
        $known = $this->knownEvents();
        if (!in_array($event, $known, true)) {
            throw new DatabaseException(
                message: 'Unknown hook event',
                debugMessage: sprintf('Unknown event "%s": no listener can be registered for it. Known events: %s', $event, implode(', ', $known))
            );
        }

        $registered = $this->hooks[$event] ?? [];
        $kept = array_values(array_filter($registered, static fn (callable $listener): bool => $listener !== $callback));
        if (count($kept) === count($registered)) {
            throw new DatabaseException(
                message: 'Unknown hook listener',
                debugMessage: sprintf('off(): the callback is not registered for "%s"', $event)
            );
        }
        $this->hooks[$event] = $kept;

        return $this;
    }

    /**
     * The events on() accepts: those this library triggers. A driver that triggers events of
     * its own (trigger()) adds them: `return [...parent::knownEvents(), 'cache.hit'];`
     *
     * @return list<string>
     */
    protected function knownEvents(): array
    {
        return ['query.before', 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback', 'transaction.end'];
    }

    /**
     * Trigger all callbacks for an event. They are handed one variable: a callback may take it by
     * reference, and what it changes there is what the callbacks after it are told.
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
