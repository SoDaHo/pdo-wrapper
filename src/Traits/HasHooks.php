<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Traits;

use Closure;
use Sodaho\PdoWrapper\Exception\DatabaseException;

/**
 * Event hooks of a driver: on(), off() and the events it fires. The hook contract stands here;
 * DatabaseInterface states when each transaction outcome arises and refers here for the rest.
 *
 * Events and payloads. on() refuses any other name - a misspelled one would never fire -; a
 * custom driver that triggers events of its own names them in knownEvents().
 * - 'query.before': sql, params - at the start of every query(), before the library's own checks,
 *   also for a statement it then refuses.
 * - 'query': sql, params, duration (seconds), rows - after the statement ran.
 * - 'error': sql, params, error (the message), code (of the reported exception), sqlState and
 *   driverCode (null where no database failure stands behind it) - after a failed statement, and
 *   with code 0 for a parameter that must not be bound (an array, a resource, an object without
 *   __toString(), a RawExpression, INF, NAN: the statement is not sent).
 * - 'transaction.begin': transaction (int), depth (int).
 * - 'transaction.commit', 'transaction.rollback': transaction (?int), depth (?int).
 * - 'transaction.end': outcome ('committed', 'rolled_back' or 'lost', DatabaseInterface::
 *   TRANSACTION_*), error (?Throwable), transaction (?int), depth (?int).
 * 'transaction' counts the transactions begun through the driver, from 1, for the driver's life.
 * 'depth' is 1 for one begun while no other owed its end - more only for one an error handler
 * begins inside a PDO call while another one's end is owed. A transaction begun on raw PDO carries
 * null for both. 'query.before', 'query', 'error', 'transaction.begin' and 'transaction.rollback'
 * hand one payload from listener to listener: a listener that takes it by reference changes what
 * the listeners after it are told, not the statement and not what the library tells later.
 * 'transaction.commit' and 'transaction.end' listeners get an array of their own each and cannot
 * take it by reference: PHP throws an Error, which is that listener's failure.
 *
 * The 'query.before', 'query' and 'error' payloads carry the SQL and the parameters as passed
 * (references as the values they hold) - passwords, tokens and personal data included. Redact
 * before logging, or open the driver with redactParameters: every value '[redacted]', and the
 * 'error' payload's error the codes instead of the database's message.
 *
 * Failing listeners:
 * - 'query.before', 'query', 'error', 'transaction.begin', 'transaction.rollback': the first one
 *   that throws stops the remaining ones of its event, and its exception reaches the caller - a
 *   PDOException from 'query.before' or 'query' as QueryException 'Query hook failed' (from
 *   'query.before' the statement was not sent, neither 'query' nor 'error' fires; from 'query' it
 *   ran, and 'error' does not fire), from 'transaction.begin' or 'transaction.rollback' as
 *   TransactionException. An 'error' listener's exception replaces the failed statement's. Two
 *   exceptions: on the automatic rollback of transaction()/updateMultiple() a rollback listener's
 *   exception is dropped, and the exception that ended the transaction reaches the caller; and an
 *   'error' listener that throws while the library reports a failure the caller does not get as the
 *   thrown one (below) is ignored.
 * - After a throwing 'transaction.begin' listener the new transaction is rolled back on raw PDO
 *   (best effort, no 'transaction.rollback' listener) and its end is told: 'rolled_back', or 'lost'
 *   when that rollback failed or the listener had ended the transaction behind the library's back;
 *   error is the exception the caller gets. Not when the transaction was ended meanwhile (a
 *   reconnect(dropTransaction: true) of the listener): that call told its end.
 * - 'transaction.commit' and 'transaction.end' listeners run after the fact, independent of each
 *   other: a failing one does not stop the next. The remaining commit listeners are skipped - and
 *   listed as failures - when a transaction a commit listener left open (on raw PDO) cannot be
 *   rolled back, the connection state cannot be read, or the session chained a new transaction to
 *   the COMMIT (then every commit listener). After a commit their failures arrive together in a
 *   CommitHookException (the commit listeners' first), not at 'error'. After a manual rollback() an
 *   end listener's failure arrives as TransactionException (the first; all reach the 'error' hook,
 *   but one whose codes cannot be read), unless a rollback listener threw or the session chained a
 *   transaction to the ROLLBACK: that exception wins, and the end failures reach only the 'error'
 *   hook. On the automatic rollback of transaction()/updateMultiple() and on a 'lost' they reach
 *   only the 'error' hook - sql '', params [], error, code, sqlState, driverCode, then hook
 *   'transaction.end', outcome and exception; a throwing 'error' listener is ignored there -, so
 *   that the exception that ended the transaction reaches the caller unchanged. Dependent steps
 *   belong in one listener.
 *
 * 'transaction.end' fires exactly once for every transaction this library ends - for every told
 * begin -, after the 'transaction.commit' or 'transaction.rollback' listeners. A BEGIN that fails
 * in PDO tells nothing. The outcomes (when each arises: DatabaseInterface::commit(), rollback(),
 * transaction()):
 * - 'committed': PDO::commit() succeeded - also when commit listeners failed or were skipped; error
 *   is null.
 * - 'rolled_back': the library's ROLLBACK succeeded - rollback() (error null), or the automatic
 *   rollback in transaction()/updateMultiple() (error: the exception that ended the transaction;
 *   after a failed commit the commit's exception, and nothing is committed). 'transaction.rollback'
 *   listeners run only after such a confirmed rollback.
 * - 'lost': ended without a commit by this library and without a confirmed rollback - the rollback
 *   failed, PDO no longer reported the transaction, the state could not be read or found out, or a
 *   commit failed on a session that may chain transactions. The data may be committed
 *   (fail-closed); error is the exception behind it, where another one reaches the caller that one.
 *   The connection may be gone: listeners must not expect queries to work. If the transaction may
 *   still be open, end it with rollback() or discard the connection: that rollback() (or a commit())
 *   runs its listeners but tells no second end. Ending it on raw PDO instead leaves that mark: a
 *   transaction then begun on raw PDO and ended through this library tells no end, until
 *   beginTransaction() clears the mark.
 *
 * Transaction control from inside a listener - the rule, judged against every listener running,
 * one inside the other:
 * - a 'transaction.end' listener may steer transactions: it runs once the transaction has ended;
 * - a 'query.before', 'query' or 'error' listener runs in the middle of the caller's statement. It
 *   may run transaction() and updateMultiple() when no transaction was open as it was entered (one
 *   begun through the driver that still owes its end, or one PDO reports; a state PDO cannot tell
 *   counts as open): their transaction begins and ends inside the listener. Never beginTransaction(),
 *   commit() or rollback(): a transaction begun there and left open would take the caller's
 *   statement in, in autocommit, and its rollback in 'query' would take it back out;
 * - a 'transaction.begin', 'transaction.commit' or 'transaction.rollback' listener runs in the middle
 *   of the caller's transaction, on the caller's connection: none of it - its commit() would commit
 *   what the caller is still building, its rollback() undo it. Nor a listener of any other event (a
 *   custom driver's own), which may run in the middle of anything.
 * A refused call - also from any listener running inside a refusing one, an end listener included -
 * throws a ListenerTransactionException and does nothing (updateMultiple() counts as transaction
 * control only where it would begin its own transaction). The exception is that listener's like any
 * other. A listener that needs a transaction otherwise uses a connection of its own. A transaction a
 * listener runs through the driver has its own number, depth 1 and its own end, inside the
 * listener (the remaining end listeners of the first transaction run after it).
 * transaction.end listeners that each begin a transaction whose end runs them again are stopped
 * after 32 levels: beginTransaction() throws a LogicException. A transaction a listener begins on
 * raw PDO (getPdo()) is not told begun and gets no end: inside a 'transaction.commit' listener it is
 * rolled back raw before the next listener runs, without 'transaction.rollback' listeners, and
 * listed as a LogicException in the CommitHookException (when that rollback fails, does not end it
 * or the state cannot be read, the remaining commit listeners are skipped); one a rollback or end
 * listener leaves open stays open, that listener's to end.
 *
 * Statement listeners: a listener that runs a statement of its own fires 'query.before' again -
 * guard against the recursion. 32 levels of 'query.before', 'query' and 'error' listeners inside
 * each other end in a LogicException that passes through query() unchanged (transaction listeners
 * do not count). What a 'query.before' listener does counts for the statement: after its
 * reconnect() the statement runs on the new connection. insert() reads the new id before the
 * 'query' listeners run, so a listener may insert on the same connection. Where the driver sends
 * nothing more (a transaction the server ended, see DatabaseInterface::query()), it sends nothing
 * for a listener either: an 'error' listener that writes (an audit row) needs a connection of its own.
 *
 * For a custom driver: the 'transaction.begin', 'transaction.commit' and 'transaction.end' listeners
 * are called one by one by the driver itself, not through trigger() - an overriding trigger() does
 * not see these three events. beginTransaction(), commit() and rollback() are final in
 * AbstractDriver, where what is told is decided; a driver extends them through listeners and the
 * protected hooks failureToRemember(), transactionEndedBy(), transactionIsOver() and
 * refreshTransactionState(). A test that needs a COMMIT to fail names a PDO class whose commit()
 * fails ('pdoClass'): the driver then takes the path of a real failure.
 */
trait HasHooks
{
    /** @var array<string, array<callable>> */
    private array $hooks = [];

    /**
     * The listeners of this object running right now, one inside the other, outermost first (see
     * asListener()): each with its event and whether a transaction was open when it was entered.
     *
     * @var list<array{string, bool}>
     */
    private array $listenerFrames = [];

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
    protected function trigger(string $event, #[\SensitiveParameter] array $data): void
    {
        foreach ($this->hooks[$event] ?? [] as $callback) {
            // by reference into the closure: what a listener changes in $data reaches the next one
            $this->asListener($event, static function () use ($callback, &$data): void {
                $callback($data);
            });
        }
    }

    /**
     * Run one listener's call, kept as a frame while it runs: listenerFrames() tells the driver
     * which listeners the call comes from, and whether a transaction was open when each was
     * entered - inside some it refuses to begin, commit or roll back a transaction. Every listener
     * of this object is run through here.
     *
     * @param Closure(): void $call
     */
    private function asListener(string $event, Closure $call): void
    {
        $this->listenerFrames[] = [$event, $this->transactionOpenAtListener($event)];
        try {
            $call();
        } finally {
            array_pop($this->listenerFrames); // its own frame, the last one: the inner ones have gone already
        }
    }

    /**
     * The listeners of this object running right now, outermost first: event, and whether a
     * transaction was open when the listener was entered.
     *
     * @return list<array{string, bool}>
     */
    private function listenerFrames(): array
    {
        return $this->listenerFrames;
    }

    /**
     * Whether a transaction is open as a listener of $event is entered. Nothing here knows of
     * transactions: the driver that uses this trait answers for itself (a method of the class
     * takes the place of this one).
     */
    private function transactionOpenAtListener(string $event): bool
    {
        return false;
    }
}
