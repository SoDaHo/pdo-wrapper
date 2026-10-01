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
     * @throws Exception\QueryException When the statement fails (also when PDO reports that without an exception), or when a 'query' listener threw a PDOException ('Query hook failed': the statement did run)
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
     * may still be open) and the listener's exception is re-thrown.
     *
     * @throws Exception\TransactionException When the transaction cannot be started (including PDO reporting the failure without throwing), or a listener threw a PDOException
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
     * same exception, in that order. A failed commit fires no 'transaction.end'.
     *
     * @throws Exception\TransactionException When the commit itself failed; it may or may not have taken effect
     * @throws Exception\CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     */
    public function commit(): void;

    /**
     * Roll back the current transaction.
     *
     * After a successful rollback the 'transaction.rollback' listeners run, then 'transaction.end'
     * with outcome 'rolled_back' (error null; not when a 'lost' was already reported for this
     * transaction). A rollback listener's exception takes precedence and passes through unchanged
     * (a PDOException as TransactionException); the end listeners' failures then reach only the
     * 'error' hook. A failed rollback fires nothing.
     *
     * @throws Exception\TransactionException On failure, or when a transaction.end listener failed and no rollback listener did (the first failure; all of them reach the 'error' hook)
     * @throws \Throwable Re-throws a rollback listener's exception
     */
    public function rollback(): void;

    /**
     * Execute a callback within a transaction.
     * Auto-commits on success, auto-rollback on exception.
     *
     * Four outcomes on failure:
     * - the transaction could not be started (BEGIN failed, or a transaction.begin listener threw):
     *   the callback did not run; after a throwing listener a rollback is attempted (best effort);
     *   the exception is re-thrown, a PDOException from the listener as TransactionException;
     * - the callback threw: rollback attempted, the callback's exception is re-thrown
     *   (best effort: if the rollback fails, the transaction may still be open). Measured on MySQL 8.0
     *   and MariaDB 11.4 with mysqlnd: after a deadlock (the server rolled the transaction back) and
     *   after a lock wait timeout (the server rolled back only the statement) PDO still reports the
     *   transaction, so the rollback is sent, the transaction.rollback listeners run and
     *   transaction.end reports 'rolled_back'; after a lost connection the rollback fails, no
     *   rollback listener runs, PDO still reported the transaction, and transaction.end reports 'lost';
     * - the commit failed: a rollback is attempted when PDO still reports the transaction, the
     *   TransactionException is re-thrown; transaction.end reports 'rolled_back' when that rollback
     *   succeeded (nothing was committed) and 'lost' when it failed too (the commit may or may not
     *   have taken effect), with the commit's exception as error. When PDO reports no transaction
     *   after the failed commit, transaction.end reports 'lost' as well, fail-closed: that is what
     *   PostgreSQL leaves behind when COMMIT fails on a deferred constraint (the server rolled back),
     *   but also what a callback leaves behind that committed itself with a raw COMMIT or a MySQL DDL
     *   statement (the data is committed, PDO::commit() then fails with "no active transaction");
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
     * @throws Exception\TransactionException When the transaction could not be started (see beginTransaction()) or the commit failed
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
     *
     * A throwing hook stops the remaining hooks of its event (for 'transaction.begin' a rollback
     * of the new transaction is attempted first, best effort), except for 'transaction.commit' and
     * 'transaction.end': those listeners are independent and all of them run; after a commit their
     * failures arrive together in a CommitHookException (commit listeners' first, then the ends of
     * transactions commit listeners left open, then the committed transaction's end), after a manual
     * rollback() as TransactionException (unless a rollback listener threw: that exception wins and
     * the end failures reach only the 'error' hook), and after the automatic rollback and on a 'lost'
     * reported there only via the 'error' hook. Dependent steps belong in one listener.
     * Only if a transaction left open by a commit listener cannot be rolled back (or the connection
     * state cannot be read) are the remaining commit listeners skipped (listed as failures).
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
     * SECURITY: never pass user input to Database::raw().
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs
     *
     * @throws Exception\QueryException
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
     * Update rows matching WHERE conditions.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs to update
     * @param array<string, mixed> $where WHERE conditions (column => value)
     *
     * @throws Exception\QueryException When $where is empty (safety)
     *
     * @return int Number of affected rows
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
     * @throws Exception\TransactionException When the own transaction's commit failed
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
