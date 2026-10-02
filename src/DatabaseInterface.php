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
     * @throws Exception\QueryException When the statement fails (also when PDO reports that without an exception), when a parameter is not null, a scalar or a Stringable object or is a Query\RawExpression (the statement is not sent), when the server has thrown the open transaction away (after a MySQL/MariaDB deadlock nothing is sent until that transaction is ended: by rollback(), by a refused commit() that tells 'lost', and for a transaction begun on raw PDO also once PDO reports none), or when a 'query' listener threw a PDOException ('Query hook failed': the statement did run)
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
     * Get the last inserted ID.
     *
     * @param string|null $name Sequence name (PostgreSQL) or null
     *
     * @return string|false Last insert ID or false on failure
     */
    public function lastInsertId(?string $name = null): string|false;

    /**
     * Get the underlying PDO instance.
     */
    public function getPdo(): PDO;

    /**
     * Whether the connection is inside a transaction (PDO::inTransaction()).
     */
    public function inTransaction(): bool;

    /**
     * Current date and time of the database in local time at statement time, to the second, as a
     * raw SQL expression for insert()/update()/where() values: MySQL `NOW()`, PostgreSQL
     * `CAST(statement_timestamp() AS TIMESTAMP(0))`, SQLite `datetime('now', 'localtime')`.
     * "Local" is the session's or server's time zone (SQLite: the operating system's), not PHP's
     * date.timezone. A zoneless value: meant for DATETIME / TIMESTAMP WITHOUT TIME ZONE / TEXT columns.
     */
    public function now(): Query\RawExpression;

    /**
     * Current UTC date and time at statement time, to the second, as a raw SQL expression:
     * MySQL `UTC_TIMESTAMP()`, PostgreSQL `CAST(statement_timestamp() AT TIME ZONE 'UTC' AS TIMESTAMP(0))`,
     * SQLite `datetime('now')`. A zoneless value: a zone-aware column (PostgreSQL TIMESTAMPTZ,
     * MySQL TIMESTAMP) would interpret it in the session's time zone; use DATETIME / TIMESTAMP
     * WITHOUT TIME ZONE / TEXT columns, or a UTC session.
     */
    public function utcNow(): Query\RawExpression;

    /**
     * Begin a transaction.
     *
     * After a throwing 'transaction.begin' listener a rollback of the new transaction is attempted
     * directly (best effort, without 'transaction.rollback' listeners; if it fails, the transaction
     * may still be open) and the listener's exception is re-thrown - unless the listener ended that
     * transaction itself: one it began afterwards is left open, with its end owed. A listener that
     * ended the transaction it was told about without throwing makes the call fail as well, and no
     * further listener runs: the caller would go on outside of the transaction it asked for (an
     * end behind this library's back - a DDL statement on MySQL/MariaDB, raw PDO - is told as
     * 'lost' first). A transaction begun through
     * this library that PDO no longer reports (an implicit commit by a DDL statement, ended by the
     * server or on raw PDO) is told as 'transaction.end' 'lost' first - except after a MySQL/MariaDB
     * deadlock: then beginTransaction() refuses, and rollback() tells that end.
     *
     * @throws Exception\TransactionException When the transaction cannot be started (including PDO reporting the failure without throwing), when a transaction begun through this library was rolled back by the server (a MySQL/MariaDB deadlock) and has not been ended with rollback() yet, a listener threw a PDOException, or a listener ended the transaction it was told about
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
     * the transaction in a way that ended it on the server: any statement error on PostgreSQL that
     * no savepoint caught, a deadlock on MySQL/MariaDB (or, with autocommit on, a lock wait
     * timeout under innodb_rollback_on_timeout). The server would answer that COMMIT with success. While PDO
     * still reports the transaction, nothing fires and it stays refused until rollback(); when
     * PDO reports none any more (MySQL/MariaDB: a statement on raw PDO, or the question to the
     * server before the commit, told it), nothing is left to roll back and the refusal tells
     * 'transaction.end' 'lost'. When the connection is in a transaction
     * right after the COMMIT (MySQL/MariaDB completion_type=CHAIN, not supported), the commit
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
     * failed inside the transaction, MySQL/MariaDB are asked whether it still exists before the
     * ROLLBACK is sent (a statement with an implicit commit commits it even when it fails) - when
     * it is gone, nothing is sent; when the question fails, the ROLLBACK is sent all the same but
     * proves nothing (the end listeners' failures on such a 'lost' reach only the 'error' hook). A rollback listener's exception takes precedence and passes through unchanged
     * (a PDOException as TransactionException); the end listeners' failures then reach only the
     * 'error' hook. A failed rollback fires nothing. After a MySQL/MariaDB deadlock rollback() also
     * ends a transaction begun through this library that PDO no longer reports (a statement on raw
     * PDO told it): nothing is sent, no rollback listener runs, and 'transaction.end' reports 'lost'
     * with the deadlock as error (its listeners' failures then reach only the 'error' hook).
     * When the connection is in a transaction right
     * after the ROLLBACK (MySQL/MariaDB completion_type=CHAIN, not supported), that is reported as
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
     *   (best effort: if the rollback fails, the transaction may still be open). Measured on MySQL 8.0
     *   and MariaDB 11.4 with mysqlnd: after a deadlock (the server rolled the transaction back) and
     *   after a lock wait timeout (the server rolled back only the statement) PDO still reports the
     *   transaction, so the rollback is sent, the transaction.rollback listeners run and
     *   transaction.end reports 'rolled_back'; after a lost connection the rollback fails, no
     *   rollback listener runs, PDO still reported the transaction, and transaction.end reports 'lost';
     * - the callback swallowed a statement error that ended the transaction on the server
     *   (PostgreSQL: any error no savepoint caught; MySQL/MariaDB: a deadlock, or a lock wait
     *   timeout under innodb_rollback_on_timeout): the commit is refused before it is sent and the
     *   CommitFailedException is thrown, instead of a COMMIT the server answers with success. While
     *   PDO still reports the transaction the rollback follows and transaction.end reports
     *   'rolled_back'; on MySQL/MariaDB, once PDO knows that the transaction is gone - from a
     *   statement on raw PDO after a deadlock, or from the question to the server after another
     *   failure (the lock wait timeout: statements the callback ran after it were committed on
     *   their own) - nothing is left to roll back and transaction.end reports 'lost'. Through
     *   this library nothing is sent between a deadlock and the rollback;
     * - the commit failed: a rollback is attempted when PDO still reports the transaction, the
     *   CommitFailedException is re-thrown; transaction.end reports 'rolled_back' when that rollback
     *   succeeded (nothing was committed) and 'lost' when it failed too (the commit may or may not
     *   have taken effect), with the commit's exception as error. When PDO reports no transaction
     *   after the failed commit, transaction.end reports 'lost' as well, fail-closed: that is what
     *   PostgreSQL leaves behind when COMMIT fails on a deferred constraint (the server rolled back),
     *   but also what a callback leaves behind that committed itself with a raw COMMIT or a MySQL DDL
     *   statement (the data is committed, PDO::commit() then fails with "no active transaction").
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
     * Events: 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback',
     * 'transaction.end' (array{outcome: 'committed'|'rolled_back'|'lost', error: ?Throwable},
     * once per transaction this library ends, after the commit or rollback listeners; see Traits\HasHooks).
     * Any other name is accepted; nothing in this library fires it. The 'query' and 'error' payloads carry the SQL and
     * the parameters as passed, secrets included: redact before logging.
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
     * @param string $event Event name
     * @param callable $callback Callback receiving event data array
     */
    public function on(string $event, callable $callback): static;

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
     * @throws Exception\QueryException On failure; an Exception\UniqueViolationException when the row collides with a unique key or the primary key
     *
     * @return int|string Last insert ID
     */
    public function insert(string $table, array $data): int|string;

    /**
     * Insert a row only when a condition holds, in one statement:
     * `INSERT INTO table (...) SELECT ?, ?, ... WHERE (condition)` (`FROM DUAL` on MySQL/MariaDB).
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
     * in $bindings it is not accepted. On MySQL a TEMPORARY target table cannot be read by its own
     * condition (error 1137). SECURITY: never build the condition from user input; user input
     * belongs in $bindings. After a return of 0, lastInsertId() is meaningless: it reports an older
     * value or 0, depending on the driver, or fails (PostgreSQL, no sequence used in this session).
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param string $condition Trusted condition SQL with ? placeholders (never built from user input)
     * @param array<array-key, mixed> $bindings Values for the condition's placeholders, in order
     *
     * @throws Exception\QueryException When $data or the condition is empty, a binding is a RawExpression, or the query fails
     *
     * @return int Inserted rows: 1 or 0
     */
    public function insertWhen(string $table, array $data, string $condition, array $bindings = []): int;

    /**
     * Insert a row unless it collides with an existing one: on a duplicate of ANY unique key or
     * of the primary key of the table the row is not inserted, and no exception is thrown. On
     * PostgreSQL the same holds for a conflict with an exclusion constraint (the row collides with
     * an existing one there too), and a DEFERRABLE unique constraint cannot be skipped: a
     * duplicate on it throws (SQLSTATE 55000).
     * PostgreSQL and SQLite: `INSERT ... ON CONFLICT DO NOTHING`. MySQL/MariaDB:
     * `INSERT ... ON DUPLICATE KEY UPDATE <first column> = <first column>` (not INSERT IGNORE,
     * which would also swallow other errors); that form locks the existing row until the
     * transaction ends and runs the table's update triggers for it, DO NOTHING does neither. On a
     * MySQL/MariaDB connection opened with the
     * driver's ATTR_FOUND_ROWS option the method throws: the server reports 1 affected row for an
     * existing row as well. Every other failure (NOT NULL, foreign key, unknown column) throws as
     * in insert().
     *
     * Returns the inserted rows, 1 or 0, not an id: after a return of 0, lastInsertId() is
     * meaningless, and on every database the skipped insert may still have used up an
     * auto-increment or sequence value. A RawExpression in $data is inlined as in insert().
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs of the row
     *
     * @throws Exception\QueryException When $data is empty, the query fails for another reason than a duplicate, or the connection counts matched rows (MySQL/MariaDB ATTR_FOUND_ROWS)
     *
     * @return int Inserted rows: 1 or 0
     */
    public function insertIgnore(string $table, array $data): int;

    /**
     * Update rows matching WHERE conditions.
     *
     * The assignments are written in the order of $data. MySQL/MariaDB evaluate them left to
     * right (a later one sees the value an earlier one set), PostgreSQL and SQLite compute all of
     * them from the row as it was. The conditions are equalities joined with AND; for NULL tests,
     * comparisons, IN or LIKE use the query builder (table()).
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs to update
     * @param array<string, mixed> $where WHERE conditions (column => value, equality only, no null)
     *
     * @throws Exception\QueryException When $where is empty (safety), a condition value is null, or the query fails (an Exception\UniqueViolationException for a duplicate key)
     *
     * @return int Number of affected rows as the database counts them: MySQL/MariaDB count the rows actually changed, PostgreSQL and SQLite the rows matched
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

    /**
     * Update multiple rows by their key column.
     *
     * Without an active transaction, the rows are updated in an own transaction
     * with the same outcomes as transaction().
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $rows Array of rows with key column
     * @param string $keyColumn Column to match rows (default: 'id')
     *
     * @throws Exception\QueryException
     * @throws Exception\TransactionException When the own transaction's commit failed, or a listener ended the own transaction while the batch ran (a CommitFailedException with outcome 'lost'; what is open then is left alone)
     * @throws Exception\CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     *
     * @return int Number of affected rows
     */
    public function updateMultiple(string $table, array $rows, string $keyColumn = 'id'): int;

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
