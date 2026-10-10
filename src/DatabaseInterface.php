<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper;

use Closure;
use PDO;
use PDOStatement;

/**
 * What a driver of this library does - the whole API: statements and their hooks, the query
 * builder and the CRUD methods, transactions with their three outcomes (committed, rolled_back,
 * lost), named locks, reconnect() and the schema. Driver\AbstractDriver implements it; type against
 * this interface. Invariants: a statement that would run outside the transaction the caller
 * believes to be in (after the server ended it, in autocommit) is refused rather than sent - unless
 * the caller begins the next one itself: beginTransaction() and transaction() tell an end PDO
 * reports 'lost' and begin anew (after a deadlock or a 1020 they refuse), so a helper asks
 * currentTransaction() !== null || inTransaction() before it opens one (README, Transactions);
 * only 'rolled_back' means that nothing of a transaction is committed.
 */
interface DatabaseInterface
{
    /** Outcomes reported by the 'transaction.end' hook, see Traits\HasHooks */
    public const TRANSACTION_COMMITTED = 'committed';
    public const TRANSACTION_ROLLED_BACK = 'rolled_back';
    public const TRANSACTION_LOST = 'lost';

    /**
     * Execute a SQL query and return the statement.
     *
     * @param string $sql SQL query with placeholders
     * @param array<int|string, mixed> $params Parameters to bind
     *
     * Inside a transaction begun through this library, a statement that commits implicitly (DDL,
     * LOCK TABLES, an account statement - see Driver\ImplicitCommit) is refused before it is sent:
     * the transaction stays open. Statements that steer transactions themselves (BEGIN, COMMIT,
     * ROLLBACK, SET autocommit, XA) are not refused and not seen - the transaction's outcome is
     * then not to be relied on (see the README, Transactions).
     *
     * @throws Exception\ImplicitCommitException When the statement would commit the open transaction implicitly (inside a transaction begun through this library; nothing is sent, the transaction stays open)
     * @throws Exception\QueryException When the statement fails (also when PDO reports that without an exception), when a parameter is not null, a scalar or a Stringable object, is a float INF or NAN, or is a Query\RawExpression (the statement is not sent), when the server has thrown the open transaction away (after a deadlock or a 1020 - see MariaDbDriver - nothing is sent until that transaction is ended: by rollback(), by a refused commit() that tells 'lost', and for a transaction begun on raw PDO also once PDO reports none), when the transaction begun through this library is gone or may be - the driver, asked right after an earlier failure, found it gone or could not find out, or PDO reports no transaction any more (raw PDO ended it): nothing is sent until rollback() tells its end -, or when a 'query' listener threw a PDOException ('Query hook failed': the statement did run) or a 'query.before' listener did (the statement was not sent)
     * @throws \Throwable What a 'query.before', 'query' or 'error' listener throws otherwise (a LogicException after 32 of them inside each other), and what an error handler throws that is not about a PDO failure: both pass unchanged
     */
    public function query(string $sql, #[\SensitiveParameter] array $params = []): PDOStatement;

    /**
     * Execute a SQL statement and return affected rows.
     *
     * @param string $sql SQL statement with placeholders
     * @param array<int|string, mixed> $params Parameters to bind
     *
     * @throws Exception\QueryException
     *
     * @return int Number of affected rows
     */
    public function execute(string $sql, #[\SensitiveParameter] array $params = []): int;

    /**
     * Get the underlying PDO instance.
     */
    public function getPdo(): PDO;

    /**
     * Whether the connection is inside a transaction (PDO::inTransaction()).
     */
    public function inTransaction(): bool;

    /**
     * The number of the open transaction begun through this driver - the 'transaction' of its
     * events -, or null when none is open (also after its end was told as 'lost'; a transaction
     * begun on raw PDO has no number).
     */
    public function currentTransaction(): ?int;

    /**
     * Discard the connection and continue on a new one, opened with the settings the driver was
     * created with: the new connection first, then a ROLLBACK on the old one, then the swap. A
     * transaction whose end was still owed ends as 'lost'. While named locks taken with namedLock()
     * are held, nothing happens unless $dropNamedLocks gives them up; while a transaction begun
     * through this library is open (currentTransaction() is not null), nothing happens unless
     * $dropTransaction gives it up; after a named-lock statement ran, before its method returned (a
     * 'query' listener of it calls this), nothing happens either. See
     * Driver\AbstractDriver::reconnect() for what goes with the old session and what may still
     * hold it.
     *
     * @param bool $dropNamedLocks Give up the named locks this driver holds, with the old session
     * @param bool $dropTransaction Give up the open transaction begun through this library, with the old session: its end is told as 'lost'
     *
     * @throws Exception\NamedLocksHeldException When named locks are held and $dropNamedLocks is false (nothing has changed)
     * @throws Exception\TransactionOpenException When a transaction begun through this library is open and $dropTransaction is false (nothing has changed)
     * @throws Exception\ConnectionException When called after a named-lock statement ran, before its method returned, the new connection cannot be opened (the old one stays), the driver was not created with its connection settings, or the connection is persistent
     */
    public function reconnect(bool $dropNamedLocks = false, bool $dropTransaction = false): void;

    /**
     * Take a named lock (GET_LOCK()), held by this connection until releaseNamedLock() or the end of
     * the connection - not by a transaction. The name is prefixed on the server (MariaDbDriver: the
     * configured database and ":"). See Driver\AbstractDriver::namedLock().
     *
     * @param string $name The lock's name, without the prefix
     * @param int $timeout Seconds to wait while another connection holds it (0: do not wait)
     *
     * @throws Exception\NamedLockReentryException When this connection holds the lock already
     * @throws Exception\QueryException When the name is empty or holds a NUL byte, the timeout is negative, the driver names no lock prefix, the server answers NULL, its answer cannot be read or is none of 1, 0, -1 and NULL (the name then counts as held), or the connection was replaced while the statement ran
     * @throws \Throwable What an error handler throws for reading the answer that is not about a failure PDO recorded (passed on unchanged)
     *
     * @return bool True when taken, false when another connection held it beyond the timeout
     */
    public function namedLock(#[\SensitiveParameter] string $name, int $timeout = 0): bool;

    /**
     * Release a named lock this connection holds (RELEASE_LOCK()).
     *
     * @throws Exception\QueryException When the name is empty or holds a NUL byte, the driver names no lock prefix, the query fails, its answer cannot be read or is none of 1, 0 and NULL, or the connection was replaced while the statement ran
     * @throws \Throwable What an error handler throws for reading the answer that is not about a failure PDO recorded (passed on unchanged)
     *
     * @return bool True when released, false when this connection did not hold it
     */
    public function releaseNamedLock(#[\SensitiveParameter] string $name): bool;

    /**
     * Whether this connection holds the named lock, asked on the server.
     *
     * @throws Exception\QueryException When the name is empty or holds a NUL byte, the driver names no lock prefix, the query fails, its answer cannot be read or is none of 1, 0 and NULL, or the connection was replaced while the statement ran
     * @throws \Throwable What an error handler throws for reading the answer that is not about a failure PDO recorded (passed on unchanged)
     */
    public function isNamedLockHeld(#[\SensitiveParameter] string $name): bool;

    /**
     * The connection id of the connection that holds the named lock, this one included, or null
     * when nobody holds it - asked on the server.
     *
     * @throws Exception\QueryException When the name is empty or holds a NUL byte, the driver names no lock prefix, the query fails, the server answers something else, its answer cannot be read, or the connection was replaced while the statement ran
     * @throws \Throwable What an error handler throws for reading the answer that is not about a failure PDO recorded (passed on unchanged)
     */
    public function namedLockHolder(#[\SensitiveParameter] string $name): ?int;

    /**
     * The named locks this driver holds as far as it knows - what the answers of namedLock() and
     * releaseNamedLock() said (an answer of namedLock() that could not be read counts the name;
     * releaseNamedLock() drops it whatever it answers) -, without the prefix, in the order taken.
     * Not asked on the server.
     *
     * @return list<string>
     */
    public function heldNamedLocks(): array;

    /**
     * What the current database holds - tables, columns, indexes, constraints -, read from
     * information_schema. Read only.
     */
    public function schema(): Schema\Schema;

    /**
     * Current date and time of the database in local time at statement time, to the second, as a
     * raw SQL expression for insert()/update()/where() values: `NOW()`. "Local" is the session's
     * or server's time zone, not PHP's date.timezone. A zoneless value: meant for DATETIME columns.
     */
    public function now(): Query\RawExpression;

    /**
     * Begin a transaction.
     *
     * After a throwing 'transaction.begin' listener a rollback of the new transaction is attempted
     * directly (best effort, without 'transaction.rollback' listeners; if it fails, the transaction
     * may still be open) and the listener's exception is re-thrown - unless the transaction was
     * ended meanwhile (a reconnect(dropTransaction: true) of the listener). A listener that ended the transaction it was
     * told about without throwing (reconnect(dropTransaction: true), raw PDO) makes the call fail as well, and no
     * further listener runs: the caller would go on outside of the transaction it asked for (an
     * end behind this library's back is told as 'lost' first). A transaction begun through this
     * library that PDO no longer reports (ended by the server or on raw PDO) is told as
     * 'transaction.end' 'lost' first - except after a MariaDB deadlock or a 1020: then
     * beginTransaction() refuses, and rollback() tells that end. Called from inside a listener of
     * this library other than a transaction.end listener (query.before, query, error - in the middle
     * of the caller's statement -, transaction.begin, transaction.commit, transaction.rollback - in
     * the middle of the caller's transaction), it refuses and begins nothing, and so do commit() and
     * rollback(). A transaction.end listener (the transaction has ended) may run one of its own,
     * with its own number and end; a query.before, query or error listener entered while no
     * transaction was open may run one through transaction() or updateMultiple(), which end it
     * inside the listener; any other listener that needs a transaction uses a connection of its own.
     * transaction.end listeners that each begin one whose end runs them again are
     * stopped after 32 levels with a LogicException.
     *
     * @throws Exception\ListenerTransactionException When called from inside a listener of this library other than a transaction.end listener (nothing is begun)
     * @throws \LogicException When 32 transaction.end listeners run one inside the other (nothing is begun)
     * @throws Exception\TransactionException When the transaction cannot be started (including PDO reporting the failure without throwing), when a transaction begun through this library was rolled back by the server (a deadlock or a 1020) and has not been ended with rollback() yet, a listener threw a PDOException, or a listener ended the transaction it was told about
     * @throws \Throwable Re-throws any other exception of a 'transaction.begin' listener
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction.
     *
     * After a successful commit, all 'transaction.commit' listeners run; their failures are
     * reported together in a CommitHookException (not a TransactionException: the data is committed).
     * Exception: if a transaction left open by a commit listener (on raw PDO: a commit listener
     * cannot begin one through this library) cannot be rolled back (the rollback fails or does not end it, or the
     * connection state cannot be read), the remaining listeners are skipped and reported as
     * failures, and CommitHookException::$connectionInTransaction is true (fail-closed: also when
     * the state is unknown). Then 'transaction.end' fires
     * with outcome 'committed' (also after skipped commit listeners; not when a 'lost' was already
     * reported for this transaction); the end listeners' failures follow the commit listeners' in the
     * same exception, in that order. A failed commit fires no 'transaction.end' (one exception: a
     * failed or refused commit of a transaction PDO no longer reports, see below).
     *
     * Every TransactionException the commit itself throws is an Exception\CommitFailedException -
     * but for the refusal inside a listener (all but transaction.end), an
     * Exception\ListenerTransactionException, where nothing is tried;
     * its $outcome is 'lost' when the commit told the end itself (PDO reported no transaction any
     * more: nothing could end it afterwards), and null otherwise: after a commit() you call
     * yourself the transaction is yours to end, and nothing writes into the exception later
     * (transaction() and updateMultiple() do write the outcome into the failure of the commit
     * they run, see transaction()). With PDO::ERRMODE_WARNING and an error handler that throws, a failing COMMIT
     * leaves as the handler's exception instead (see Traits\HasHooks).
     *
     * The commit is refused (CommitFailedException, no COMMIT sent) when a statement failed inside
     * the transaction in a way that ended it on the server: a deadlock or a 1020 on MariaDB, a lock
     * wait timeout under innodb_rollback_on_timeout - with autocommit off as well -, or a DDL statement
     * on raw PDO that committed it implicitly before a statement through this library failed (the
     * driver asks right after such a failure, and holds what it finds). The server would answer that
     * COMMIT with success. While PDO still reports the transaction, nothing fires and
     * it stays refused until rollback(); when PDO reports none any more (a statement on raw PDO,
     * or the question to the server before the commit, told it), nothing is left to roll back
     * and the refusal tells 'transaction.end' 'lost'. When the connection is in a transaction
     * right after the COMMIT (completion_type=CHAIN, not supported), the commit
     * listeners are skipped and a CommitHookException reports it. A COMMIT that failed - also with
     * what a PDO class or an error handler threw besides a PDOException, which passes unchanged -
     * may have taken effect on a session that may chain transactions (on MariaDB a completion_type
     * other than NO_CHAIN: the driver sets NO_CHAIN when it connects, a SET SESSION afterwards
     * changes it). Under CHAIN PDO then reports the next transaction: it stays the caller's to end,
     * but its rollback() confirms nothing and tells 'lost'. Under RELEASE the server closes the
     * connection after a COMMIT that took effect: where PDO learned that, the commit tells 'lost'
     * at once, as every failed commit after which PDO reports none; where the answer was lost on the
     * way, the rollback fails as on every lost connection. See Traits\HasHooks.
     *
     * @throws Exception\ListenerTransactionException When called from inside a listener of this library other than a transaction.end listener (nothing is sent)
     * @throws Exception\CommitFailedException When the commit itself failed (it may or may not have taken effect), or was refused because the server had already ended the transaction - rolled back, or committed implicitly by a DDL statement on raw PDO: no promise either way; only $outcome 'rolled_back' says that nothing is committed. A TransactionException
     * @throws Exception\CommitHookException When committed, but a transaction.commit or transaction.end listener failed, the connection state after a commit listener could not be verified, or the connection is in a new, chained transaction
     */
    public function commit(): void;

    /**
     * Roll back the current transaction.
     *
     * After a confirmed rollback the 'transaction.rollback' listeners run, then 'transaction.end'
     * with outcome 'rolled_back' (error null; not when a 'lost' was already reported for this
     * transaction). Not confirmed, and told as 'lost' without rollback listeners: after a statement
     * failed inside the transaction, MariaDB is asked whether it still exists before the
     * ROLLBACK is sent (a statement with an implicit commit commits it even when it fails) - when
     * it is gone, nothing is sent; when the question fails, the ROLLBACK is sent all the same but
     * proves nothing (the end listeners' failures on such a 'lost' reach only the 'error' hook). A rollback listener's exception takes precedence and passes through unchanged
     * (a PDOException as TransactionException); the end listeners' failures then reach only the
     * 'error' hook. A failed rollback fires nothing. After a MariaDB deadlock or a 1020 rollback() also
     * ends a transaction begun through this library that PDO no longer reports (a statement on raw
     * PDO told it): nothing is sent, no rollback listener runs, and 'transaction.end' reports 'lost'
     * with that failure as error (its listeners' failures then reach only the 'error' hook). So
     * does a transaction begun through this library that PDO no longer reports for any other
     * reason - a DDL statement that went through committed it, raw PDO ended it -, with a
     * TransactionException 'Transaction ended outside this library' as error: rollback() is the
     * way out of every state in which the library holds a transaction PDO does not report (ask
     * currentTransaction() !== null || inTransaction() before it).
     * Not confirmed either after a commit() of this transaction that failed on a session that may
     * chain transactions (see commit()): the ROLLBACK is sent to clean up, no rollback listener
     * runs, and 'transaction.end' reports 'lost' with the failed commit as error.
     * When the connection is in a transaction right
     * after the ROLLBACK (completion_type=CHAIN, not supported), that is reported as
     * TransactionException after the listeners ran, unless a rollback listener threw.
     *
     * @throws Exception\ListenerTransactionException When called from inside a listener of this library other than a transaction.end listener (nothing is sent)
     * @throws Exception\TransactionException On failure, when the connection is in a new, chained transaction afterwards, or when a transaction.end listener failed after a confirmed rollback and no rollback listener did (the first failure; all of them reach the 'error' hook)
     * @throws \Throwable Re-throws a rollback listener's exception
     */
    public function rollback(): void;

    /**
     * Execute a callback within a transaction.
     * Auto-commits on success, auto-rollback on exception.
     *
     * From inside a listener it runs where a transaction of its own is the listener's alone: in a
     * transaction.end listener, and in a query.before, query or error listener entered while no
     * transaction was open (the transaction begins and ends inside the listener); anywhere else it
     * refuses and begins nothing (Exception\ListenerTransactionException, see Traits\HasHooks).
     * The callback runs inside the listener all the same: its own commit() or rollback() is refused
     * where the listener's would be.
     *
     * What can go wrong:
     * - the transaction could not be started (BEGIN failed, a transaction.begin listener threw - a
     *   ListenerTransactionException among others, when it tried to steer the transaction -, or such
     *   a listener ended the transaction it was told about without throwing: reconnect(dropTransaction: true), raw PDO):
     *   the callback did not run; after a throwing listener a rollback is attempted (best effort);
     *   the exception is re-thrown, a PDOException from the listener as TransactionException;
     * - the callback threw: rollback attempted, the callback's exception is re-thrown
     *   (best effort: if the rollback fails, the transaction may still be open). Measured on
     *   MariaDB 11.4 with mysqlnd: after a deadlock or a 1020 (the server rolled the transaction back) and
     *   after a lock wait timeout (the server rolled back only the statement) PDO still reports the
     *   transaction, so the rollback is sent, the transaction.rollback listeners run and
     *   transaction.end reports 'rolled_back'; after a lost connection the rollback fails, no
     *   rollback listener runs, PDO still reported the transaction, and transaction.end reports 'lost';
     * - the callback swallowed a statement error that ended the transaction on the server
     *   (a deadlock or a 1020, or a lock wait timeout under innodb_rollback_on_timeout): the commit is
     *   refused before it is sent and the CommitFailedException is thrown, instead of a COMMIT the
     *   server answers with success. While PDO still reports the transaction the rollback follows
     *   and transaction.end reports 'rolled_back'; once PDO knows that the transaction is gone - from
     *   a statement on raw PDO after a deadlock or a 1020, or from the question the driver asks right
     *   after any other failure (the lock wait timeout) - nothing is left to roll back and
     *   transaction.end reports 'lost'. Through this library nothing is sent between a deadlock or a
     *   1020 and the rollback, nor after a failure that the question found had ended the transaction
     *   or could not settle, nor while PDO reports no transaction (a DDL statement committed it
     *   implicitly, failing or not): the statement would run in autocommit and be committed on its
     *   own, it throws a QueryException instead;
     * - the commit failed: a rollback is attempted when PDO still reports the transaction, the
     *   CommitFailedException is re-thrown; transaction.end reports 'rolled_back' when that rollback
     *   succeeded (nothing was committed) and 'lost' when it failed too (the commit may or may not
     *   have taken effect), with the commit's exception as error. On a session that may chain
     *   transactions (see commit()) the rollback confirms nothing: 'lost' as well, no rollback listener. When PDO reports no transaction
     *   after the failed commit, transaction.end reports 'lost' as well, fail-closed: that is what
     *   a callback leaves behind that committed itself with a raw COMMIT or a DDL statement (the
     *   data is committed, PDO::commit() then fails with "no active transaction").
     *   In both commit cases the exception's $outcome is the outcome transaction.end reported:
     *   only 'rolled_back' says that nothing is committed;
     * - the callback ended the transaction itself through this library (commit() or rollback()), or
     *   an error handler inside a PDO call did (a listener inside the transaction cannot: refused
     *   there): this method ends only the transaction it began. Whatever is open afterwards - begun
     *   by the callback through this library or on raw PDO, by an end listener through this library,
     *   or by any listener on raw PDO - is neither
     *   committed nor rolled back here. A callback that returns gets a CommitFailedException with
     *   outcome 'lost' and no COMMIT is sent; one that throws gets its exception re-thrown;
     * - the callback calls transaction() or beginTransaction() again: there are no nested
     *   transactions (no savepoints) - the inner call throws a TransactionException 'Failed to begin
     *   transaction' ("There is already an active transaction") before its callback runs; left to
     *   escape, it rolls the outer transaction back like any other exception of the callback;
     * - a transaction.commit or transaction.end listener failed, or the connection state after a
     *   commit listener could not be verified or cleaned up: committed, the committed transaction is
     *   not rolled back, CommitHookException (getPrevious() is the first failure, which need not be
     *   a listener's own exception).
     * Once the transaction was started, transaction.end fires exactly once: after the commit
     * listeners ('committed'), or after the rollback listeners ('rolled_back'), or as 'lost'; on the
     * rollback and lost paths its listeners' failures reach the 'error' hook, never the caller.
     *
     * @param Closure $callback Receives the driver instance
     *
     * @throws Exception\TransactionException When the transaction could not be started (see beginTransaction()), and when called from inside a listener that may not run it (ListenerTransactionException, nothing is begun)
     * @throws Exception\CommitFailedException When the commit failed or was refused; for the commit this method runs itself $outcome is 'rolled_back' or 'lost' (a failed commit() the callback called itself and let escape keeps what that commit gave it: null, or 'lost' if it told the end itself). A TransactionException
     * @throws Exception\CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     * @throws \Throwable Re-throws the callback, begin listener or commit exception after rollback
     *
     * @return mixed Return value of the callback
     */
    public function transaction(Closure $callback): mixed;

    /**
     * Register a hook callback for an event.
     *
     * Events: 'query.before' (array{sql: string, params: array}, before every statement - a listener
     * that throws stops it), 'query', 'error', 'transaction.begin' (array{transaction: int, depth: int}),
     * 'transaction.commit' and 'transaction.rollback' (array{transaction: ?int, depth: ?int}),
     * 'transaction.end' (array{outcome: 'committed'|'rolled_back'|'lost', error: ?Throwable,
     * transaction: ?int, depth: ?int}, once per transaction this library ends - exactly one for
     * every told begin -, after the commit or rollback listeners; see Traits\HasHooks).
     * Any other name is refused (see @throws): a listener for it would never run. The 'query.before', 'query' and 'error'
     * payloads carry the SQL and the parameters as passed, secrets included - unless the option redactParameters is on,
     * then every value is '[redacted]': redact before logging otherwise (README, "Parameters are secrets", names every
     * channel that carries them). 'error'
     * carries sql, params, error (the message), code (the code of the reported exception), sqlState
     * and driverCode (what the database said, read by the rule of DatabaseException::$sqlState and
     * $driverCode: null where no database failure stands behind the reported error).
     *
     * A throwing hook stops the remaining hooks of its event (for 'transaction.begin' a rollback
     * of the new transaction is attempted first, best effort), except for 'transaction.commit' and
     * 'transaction.end': those listeners are independent and all of them run; after a commit their
     * failures arrive together in a CommitHookException (commit listeners' first, then the
     * committed transaction's end listeners'), after a manual
     * rollback() as TransactionException (unless a rollback listener threw: that exception wins and
     * the end failures reach only the 'error' hook), and after the automatic rollback and on a 'lost'
     * reported there only via the 'error' hook. Dependent steps belong in one listener.
     * Only if a transaction left open by a commit listener cannot be rolled back, the connection
     * state cannot be read, or the session chained a new transaction to the COMMIT (then all of
     * them) are the remaining commit listeners skipped (listed as failures).
     *
     * @param string $event Event name: 'query.before', 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback' or 'transaction.end' (a driver may know more)
     * @param callable $callback Callback receiving event data array
     *
     * @throws Exception\DatabaseException When the event is unknown: a listener for a misspelled name would never run
     */
    public function on(string $event, callable $callback): static;

    /**
     * Remove a callback registered with on() - every registration of it. Told apart by identity
     * (`===`); a listener removed while its event is told still runs that once.
     *
     * @throws Exception\DatabaseException When the event is unknown, or the callback is not registered for it
     */
    public function off(string $event, callable $callback): static;

    // =========================================================================
    // CRUD Helper
    // =========================================================================

    /**
     * Insert a row and return the last insert ID.
     *
     * A Query\RawExpression value (Database::raw(), now(), utcNow()) is inlined into the SQL
     * instead of being bound; the same applies to update() data and to WHERE condition values.
     * The expression's own bindings (Database::raw('col + ?', [$n])) are bound exactly where it
     * stands among the other values. SECURITY: never pass user input as the SQL of Database::raw().
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs
     *
     * @throws Exception\QueryException On failure; an Exception\UniqueViolationException when the row collides with a unique key or the primary key,
     *                                  a QueryException after the insert when the ID the database reports is no integer of PHP
     *
     * @return int Last insert ID, 0 when the database generated none (a table without
     *             AUTO_INCREMENT)
     */
    public function insert(string $table, #[\SensitiveParameter] array $data): int;

    /**
     * Update rows matching WHERE conditions.
     *
     * The assignments are written in the order of $data. MariaDB evaluates them left to right (a
     * later one sees the value an earlier one set). The conditions are equalities joined with AND;
     * for NULL tests, comparisons, IN or LIKE use the query builder (table()).
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs to update
     * @param array<string, mixed> $where WHERE conditions (column => value, equality only, no null)
     *
     * @throws Exception\QueryException When $where is empty (safety), a condition value is null, or the query fails (an Exception\UniqueViolationException for a duplicate key)
     *
     * @return int Number of affected rows as the database counts them: MariaDB counts the rows actually changed - the rows matched with ATTR_FOUND_ROWS, and possibly on a persistent connection an earlier request opened with it
     */
    public function update(string $table, #[\SensitiveParameter] array $data, #[\SensitiveParameter] array $where): int;

    /**
     * Delete rows matching WHERE conditions.
     *
     * @param string $table Table name
     * @param array<string, mixed> $where WHERE conditions (column => value)
     *
     * @throws Exception\QueryException When $where is empty (safety)
     *
     * @return int Number of affected rows
     */
    public function delete(string $table, #[\SensitiveParameter] array $where): int;

    /**
     * Find a single row by WHERE conditions.
     *
     * @param string $table Table name
     * @param array<string, mixed> $where WHERE conditions (column => value)
     *
     * @throws Exception\QueryException
     *
     * @return array<string, mixed>|null Row as associative array or null
     */
    public function findOne(string $table, #[\SensitiveParameter] array $where): ?array;

    /**
     * Find all rows matching WHERE conditions.
     *
     * @param string $table Table name
     * @param array<string, mixed> $where WHERE conditions (optional)
     *
     * @throws Exception\QueryException
     *
     * @return array<int, array<string, mixed>> Array of rows
     */
    public function findAll(string $table, #[\SensitiveParameter] array $where = []): array;

    // =========================================================================
    // Query Builder
    // =========================================================================

    /**
     * Create a query builder for the given table.
     *
     * @param string $table Table name
     */
    public function table(string $table): Query\QueryBuilder;

    /**
     * Get the last inserted ID.
     *
     * @param string|null $name Ignored by MariaDB (PDO's sequence name)
     *
     * @return string|false Last insert ID or false on failure
     */
    public function lastInsertId(?string $name = null): string|false;

    /**
     * Current UTC date and time at statement time, to the second, as a raw SQL expression:
     * `UTC_TIMESTAMP()`. A zoneless value: a TIMESTAMP column would interpret it in the session's
     * time zone; use DATETIME, or a UTC session.
     */
    public function utcNow(): Query\RawExpression;

    /**
     * Insert a row only when a condition holds, in one statement:
     * `INSERT INTO table (...) SELECT ?, ?, ... FROM DUAL WHERE (condition)`.
     *
     * Check and insert see the same snapshot, but two concurrent statements can still both see
     * the condition true and both insert (READ COMMITTED / REPEATABLE READ): an invariant such as
     * "one open code per user" needs a UNIQUE constraint, a row lock (lockForUpdate()) or
     * SERIALIZABLE on top.
     *
     * The condition is trusted developer SQL with ? placeholders, like whereRaw(); it may look at
     * the target table itself (`NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL)`).
     * Binding order: the row's values first (in column order), then $bindings. A RawExpression in
     * $data is inlined as in insert() (but inside a SELECT list: `raw('DEFAULT')` is not valid there);
     * in $bindings it is not accepted. A TEMPORARY target table cannot be read by its own
     * condition (error 1137). SECURITY: never build the condition from user input; user input
     * belongs in $bindings. After a return of 0, lastInsertId() is meaningless: it reports an
     * older value or 0.
     *
     * With $update, a row that collides with an existing one on any unique key changes that row
     * instead (`... WHERE (condition) ON DUPLICATE KEY UPDATE ...`, see upsert()); the update's
     * values are bound after the condition's. The return is then MariaDB's count: 1 inserted,
     * 2 updated, 0 neither - the condition was false, or the row already held those values
     * (insertWhenReturning() tells them apart). On a connection opened with ATTR_FOUND_ROWS an
     * unchanged row counts 1 like an insert, and the method throws when $update is given; so it
     * does on a persistent connection, which an earlier request may have opened with that option.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param string $condition Trusted condition SQL with ? placeholders (never built from user input)
     * @param array<array-key, mixed> $bindings Values for the condition's placeholders, in order
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     *
     * @throws Exception\QueryException When $data or the condition is empty, a binding is a RawExpression, $update is given on a connection with ATTR_FOUND_ROWS or a persistent one, or the query fails
     *
     * @return int Inserted rows, 1 or 0; with $update MariaDB's count (1 inserted, 2 updated, 0 neither)
     */
    public function insertWhen(string $table, #[\SensitiveParameter] array $data, string $condition, #[\SensitiveParameter] array $bindings = [], #[\SensitiveParameter] array $update = []): int;

    /**
     * insertWhen() that returns the row: `... RETURNING <columns>`. Null when the condition was
     * false (nothing inserted, nothing updated); otherwise the inserted row, or with $update the
     * existing row after the update (also when it already held those values).
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param string $condition Trusted condition SQL with ? placeholders (never built from user input)
     * @param array<array-key, mixed> $bindings Values for the condition's placeholders, in order
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     * @param list<string|Query\RawExpression> $columns What to return: column names, '*', or expressions without bindings (Database::raw('n * 2 AS twice'))
     *
     * @throws Exception\QueryException As insertWhen() (not for ATTR_FOUND_ROWS or a persistent connection), and when $columns is empty or holds an expression with bindings
     *
     * @return array<string, mixed>|null The row, or null when the condition was false
     */
    public function insertWhenReturning(string $table, #[\SensitiveParameter] array $data, string $condition, #[\SensitiveParameter] array $bindings = [], #[\SensitiveParameter] array $update = [], #[\SensitiveParameter] array $columns = ['*']): ?array;

    /**
     * Insert a row, or change the row it collides with: `INSERT INTO table (...) VALUES (...)
     * ON DUPLICATE KEY UPDATE col = ?, ...`.
     *
     * MariaDB takes a collision on ANY unique key or the primary key for the duplicate - there is
     * no conflict target to name. The update's assignments are rendered in the order of $update,
     * and MariaDB applies them from left to right: a later one sees what an earlier one set
     * (`n = n + 1, m = n` gives m the new n). A value may be Database::raw() with bindings, and
     * Database::value('col') is the value the row would have been inserted with. Binding order:
     * the row's values, then the update's. The update runs the table's update triggers and locks
     * the existing row until the transaction ends; a BEFORE UPDATE trigger that changes the row
     * makes an unchanged upsert count 2.
     *
     * Returns MariaDB's count: 1 inserted, 2 updated, 0 the existing row already held those
     * values. On a connection opened with ATTR_FOUND_ROWS the server reports 1 for an unchanged
     * row as well, and the method throws - also on a persistent connection, which PDO may hand
     * back opened with that option by an earlier request.
     *
     * @param string $table Table name
     * @param array<string, mixed> $row Column => value pairs of the row
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     *
     * @throws Exception\QueryException When $row or $update is empty, the connection counts matched rows (ATTR_FOUND_ROWS) or may (a persistent one), or the query fails
     *
     * @return int 1 inserted, 2 updated, 0 unchanged
     */
    public function upsert(string $table, #[\SensitiveParameter] array $row, #[\SensitiveParameter] array $update): int;

    /**
     * upsert() that returns the row after the statement: `... RETURNING <columns>` - the inserted
     * row, or the existing one after the update (also when it already held those values; MariaDB
     * returns it in every case, measured on 10.11, 11.4 and 12.3).
     *
     * @param string $table Table name
     * @param array<string, mixed> $row Column => value pairs of the row
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     * @param list<string|Query\RawExpression> $columns What to return: column names, '*', or expressions without bindings
     *
     * @throws Exception\QueryException When $row, $update or $columns is empty, a column is an expression with bindings, or the query fails
     *
     * @return array<string, mixed> The row
     */
    public function upsertReturning(string $table, #[\SensitiveParameter] array $row, #[\SensitiveParameter] array $update, #[\SensitiveParameter] array $columns = ['*']): array;

    /**
     * Insert a row unless it collides with an existing one: on a duplicate of ANY unique key or
     * of the primary key of the table the row is not inserted, and no exception is thrown.
     * `INSERT ... ON DUPLICATE KEY UPDATE <first column> = <first column>` (not INSERT IGNORE,
     * which would also swallow other errors); that form locks the existing row until the
     * transaction ends and runs the table's update triggers for it. On a connection opened with
     * the driver's ATTR_FOUND_ROWS option the method throws: the server reports 1 affected row for
     * an existing row as well; so it does on a persistent connection, which an earlier request
     * may have opened with that option. Every other failure (NOT NULL, foreign key, unknown column) throws
     * as in insert().
     *
     * Returns the inserted rows, 1 or 0, not an id: after a return of 0, lastInsertId() is
     * meaningless, and the skipped insert may still have used up an auto-increment value. A
     * RawExpression in $data is inlined as in insert().
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs of the row
     *
     * @throws Exception\QueryException When $data is empty, the query fails for another reason than a duplicate, or the connection counts matched rows (ATTR_FOUND_ROWS) or may (a persistent one)
     *
     * @return int Inserted rows: 1 or 0
     */
    public function insertIgnore(string $table, #[\SensitiveParameter] array $data): int;

    /**
     * Update multiple rows by their key column.
     *
     * The key column must be a plain column name, also for a batch without rows. Every row is
     * checked before the first is sent - each row an array, the key column in each row, every key a
     * plain column name, every key value (not null, also in a row with nothing to set), every value
     * one that can be bound; no SQL is written for it -: a refused row leaves nothing written, also inside a transaction of
     * the caller, and no hook fires for it. Without an open transaction, the rows are updated in an
     * own transaction with the same outcomes as transaction(). Open counts one begun through the
     * library that ended behind its back (a DDL statement or raw PDO ended it): the batch is then
     * refused like any other statement there, and no transaction of its own takes the old one's
     * place. A state PDO cannot tell is refused before anything is sent. Where it would begin its
     * own transaction it is transaction control: from inside a listener it runs only where
     * transaction() may (Exception\ListenerTransactionException otherwise, nothing is sent).
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $rows Array of rows with key column
     * @param string $keyColumn Column to match rows (default: 'id')
     *
     * @throws Exception\QueryException When the key column or a row is refused by the check above (nothing is sent), when an update fails, or is refused because the transaction begun through the library has ended behind its back
     * @throws Exception\TransactionException When the own transaction's commit failed, or the own transaction was ended while the batch ran - a listener's reconnect(), an error handler inside a PDO call (a CommitFailedException with outcome 'lost'; what is open then is left alone) -, when PDO cannot tell whether a transaction is open ('Connection state unknown', nothing is sent), and where it would begin its own transaction from inside a listener that may not run one (ListenerTransactionException, nothing is sent; see transaction())
     * @throws Exception\CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     *
     * @return int Number of affected rows
     */
    public function updateMultiple(string $table, #[\SensitiveParameter] array $rows, string $keyColumn = 'id'): int;
}
