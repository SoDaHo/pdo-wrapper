<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper;

use Closure;
use PDO;
use PDOStatement;

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
     * @throws Exception\QueryException When the statement fails (also when PDO reports that without an exception), when a parameter is not null, a scalar or a Stringable object, is a float INF or NAN, or is a Query\RawExpression (the statement is not sent), when the server has thrown the open transaction away (after a deadlock or a 1020 - see MariaDbDriver - nothing is sent until that transaction is ended: by rollback(), by a refused commit() that tells 'lost', and for a transaction begun on raw PDO also once PDO reports none), or when a 'query' listener threw a PDOException ('Query hook failed': the statement did run)
     */
    public function query(string $sql, array $params = []): PDOStatement;

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
    public function execute(string $sql, array $params = []): int;

    /**
     * Get the underlying PDO instance.
     */
    public function getPdo(): PDO;

    /**
     * Whether the connection is inside a transaction (PDO::inTransaction()).
     */
    public function inTransaction(): bool;

    /**
     * Discard the connection and continue on a new one, opened with the settings the driver was
     * created with: the new connection first, then a ROLLBACK on the old one, then the swap. A
     * transaction whose end was still owed ends as 'lost'. See Driver\AbstractDriver::reconnect()
     * for what goes with the old session and what may still hold it.
     *
     * @throws Exception\ConnectionException When the new connection cannot be opened (the old one stays), the driver was not created with its connection settings, or the connection is persistent
     */
    public function reconnect(): void;

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
     * may still be open) and the listener's exception is re-thrown - unless the listener ended that
     * transaction itself: one it began afterwards is left open, with its end owed. A listener that
     * ended the transaction it was told about without throwing makes the call fail as well, and no
     * further listener runs: the caller would go on outside of the transaction it asked for (an
     * end behind this library's back - a DDL statement on MariaDB, raw PDO - is told as
     * 'lost' first). A transaction begun through
     * this library that PDO no longer reports (an implicit commit by a DDL statement, ended by the
     * server or on raw PDO) is told as 'transaction.end' 'lost' first - except after a MariaDB
     * deadlock or a 1020: then beginTransaction() refuses, and rollback() tells that end.
     *
     * @throws Exception\TransactionException When the transaction cannot be started (including PDO reporting the failure without throwing), when a transaction begun through this library was rolled back by the server (a deadlock or a 1020) and has not been ended with rollback() yet, a listener threw a PDOException, or a listener ended the transaction it was told about
     * @throws \Throwable Re-throws any other exception of a 'transaction.begin' listener
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction.
     *
     * After a successful commit, all 'transaction.commit' listeners run; their failures are
     * reported together in a CommitHookException (not a TransactionException: the data is committed).
     * Exception: if a transaction left open by a listener cannot be rolled back (the rollback fails
     * or does not end it, or the connection state cannot be read), the remaining listeners are
     * skipped and reported as failures, and CommitHookException::$connectionInTransaction is true
     * (fail-closed: also when the state is unknown). Then the ends of the transactions commit
     * listeners began through the library and left open are dispatched, then 'transaction.end' fires
     * with outcome 'committed' (also after skipped commit listeners; not when a 'lost' was already
     * reported for this transaction); the end listeners' failures follow the commit listeners' in the
     * same exception, in that order. A failed commit fires no 'transaction.end' (one exception: a
     * failed or refused commit of a transaction PDO no longer reports, see below).
     *
     * Every TransactionException the commit itself throws is an Exception\CommitFailedException;
     * its $outcome is 'lost' when the commit told the end itself (PDO reported no transaction any
     * more: nothing could end it afterwards), and null otherwise: after a commit() you call
     * yourself the transaction is yours to end, and nothing writes into the exception later
     * (transaction() and updateMultiple() do write the outcome into the failure of the commit
     * they run, see transaction()). With PDO::ERRMODE_WARNING and an error handler that throws, a failing COMMIT
     * leaves as the handler's exception instead (see Traits\HasHooks).
     *
     * The commit is refused (CommitFailedException, no COMMIT sent) when a statement failed inside
     * the transaction in a way that ended it on the server: a deadlock or a 1020 on MariaDB (or, with
     * autocommit on, a lock wait timeout under innodb_rollback_on_timeout). The server would
     * answer that COMMIT with success. While PDO still reports the transaction, nothing fires and
     * it stays refused until rollback(); when PDO reports none any more (a statement on raw PDO,
     * or the question to the server before the commit, told it), nothing is left to roll back
     * and the refusal tells 'transaction.end' 'lost'. When the connection is in a transaction
     * right after the COMMIT (completion_type=CHAIN, not supported), the commit
     * listeners are skipped and a CommitHookException reports it. See Traits\HasHooks.
     *
     * @throws Exception\CommitFailedException When the commit itself failed (it may or may not have taken effect), or was refused because the server had already ended the transaction (nothing of that transaction is committed; statements run on raw PDO after its end are). A TransactionException
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
     * with that failure as error (its listeners' failures then reach only the 'error' hook).
     * When the connection is in a transaction right
     * after the ROLLBACK (completion_type=CHAIN, not supported), that is reported as
     * TransactionException after the listeners ran, unless a rollback listener threw.
     *
     * @throws Exception\TransactionException On failure, when the connection is in a new, chained transaction afterwards, or when a transaction.end listener failed after a confirmed rollback and no rollback listener did (the first failure; all of them reach the 'error' hook)
     * @throws \Throwable Re-throws a rollback listener's exception
     */
    public function rollback(): void;

    /**
     * Execute a callback within a transaction.
     * Auto-commits on success, auto-rollback on exception.
     *
     * What can go wrong:
     * - the transaction could not be started (BEGIN failed, a transaction.begin listener threw, or
     *   such a listener ended the transaction it was told about):
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
     *   a statement on raw PDO after a deadlock or a 1020, or from the question to the server after another
     *   failure (the lock wait timeout: statements the callback ran after it were committed on
     *   their own) - nothing is left to roll back and transaction.end reports 'lost'. Through
     *   this library nothing is sent between a deadlock or a 1020 and the rollback;
     * - the commit failed: a rollback is attempted when PDO still reports the transaction, the
     *   CommitFailedException is re-thrown; transaction.end reports 'rolled_back' when that rollback
     *   succeeded (nothing was committed) and 'lost' when it failed too (the commit may or may not
     *   have taken effect), with the commit's exception as error. When PDO reports no transaction
     *   after the failed commit, transaction.end reports 'lost' as well, fail-closed: that is what
     *   a callback leaves behind that committed itself with a raw COMMIT or a DDL statement (the
     *   data is committed, PDO::commit() then fails with "no active transaction").
     *   In both commit cases the exception's $outcome is the outcome transaction.end reported:
     *   only 'rolled_back' says that nothing is committed;
     * - the callback ended the transaction itself through this library (commit() or rollback()), or a
     *   listener did: this method ends only the transaction it began. Whatever is open afterwards -
     *   begun by the callback or by a listener, through this library or on raw PDO - is neither
     *   committed nor rolled back here. A callback that returns gets a CommitFailedException with
     *   outcome 'lost' and no COMMIT is sent; one that throws gets its exception re-thrown;
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
     * @throws Exception\TransactionException When the transaction could not be started (see beginTransaction())
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
     * Events: 'query', 'error', 'transaction.begin' (array{transaction: int, depth: int}),
     * 'transaction.commit' and 'transaction.rollback' (array{transaction: ?int, depth: ?int}),
     * 'transaction.end' (array{outcome: 'committed'|'rolled_back'|'lost', error: ?Throwable,
     * transaction: ?int, depth: ?int}, once per transaction this library ends - exactly one for
     * every told begin -, after the commit or rollback listeners; see Traits\HasHooks).
     * Any other name is refused (see @throws): a listener for it would never run. The 'query' and 'error' payloads
     * carry the SQL and the parameters as passed, secrets included: redact before logging. 'error'
     * carries sql, params, error (the message), code (the code of the reported exception), sqlState
     * and driverCode (what the database said, read by the rule of DatabaseException::$sqlState and
     * $driverCode: null where no database failure stands behind the reported error).
     *
     * A throwing hook stops the remaining hooks of its event (for 'transaction.begin' a rollback
     * of the new transaction is attempted first, best effort), except for 'transaction.commit' and
     * 'transaction.end': those listeners are independent and all of them run; after a commit their
     * failures arrive together in a CommitHookException (commit listeners' first, then the ends of
     * transactions commit listeners left open, then the committed transaction's end), after a manual
     * rollback() as TransactionException (unless a rollback listener threw: that exception wins and
     * the end failures reach only the 'error' hook), and after the automatic rollback and on a 'lost'
     * reported there only via the 'error' hook. Dependent steps belong in one listener.
     * Only if a transaction left open by a commit listener cannot be rolled back, the connection
     * state cannot be read, or the session chained a new transaction to the COMMIT (then all of
     * them) are the remaining commit listeners skipped (listed as failures).
     *
     * @param string $event Event name: 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback' or 'transaction.end' (a driver may know more)
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
    public function insert(string $table, array $data): int;

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
     * @return int Number of affected rows as the database counts them: MariaDB counts the rows actually changed
     */
    public function update(string $table, array $data, array $where): int;

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
    public function delete(string $table, array $where): int;

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
    public function findOne(string $table, array $where): ?array;

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
    public function findAll(string $table, array $where = []): array;

    // =========================================================================
    // Query Builder
    // =========================================================================

    /**
     * Create a query builder for the given table.
     *
     * @param string $table Table name
     */
    public function table(string $table): Query\QueryBuilder;
}
