<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper;

use Closure;
use PDO;
use PDOStatement;

interface DatabaseInterface
{
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
     * Exception: if a transaction left open by a listener cannot be rolled back (or the connection
     * state cannot be read), the remaining listeners are skipped and reported as failures.
     *
     * @throws Exception\TransactionException When the commit itself failed; it may or may not have taken effect
     * @throws Exception\CommitHookException When committed, but a transaction.commit listener failed or the connection state after it could not be verified
     */
    public function commit(): void;

    /**
     * Roll back the current transaction.
     *
     * @throws Exception\TransactionException
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
     *   (best effort: if the rollback fails, the transaction may still be open);
     * - the commit failed: rollback attempted, TransactionException re-thrown
     *   (the commit may or may not have taken effect);
     * - a transaction.commit listener failed: committed, no rollback, CommitHookException.
     *
     * @param Closure $callback Receives the driver instance
     *
     * @throws Exception\TransactionException When the transaction could not be started (see beginTransaction()) or the commit failed
     * @throws Exception\CommitHookException When committed, but a transaction.commit listener failed or the connection state after it could not be verified
     * @throws \Throwable Re-throws the callback, begin listener or commit exception after rollback
     *
     * @return mixed Return value of the callback
     */
    public function transaction(Closure $callback): mixed;

    /**
     * Register a hook callback for an event.
     *
     * Events: 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback'
     *
     * A throwing hook stops the remaining hooks of its event (for 'transaction.begin' a rollback
     * of the new transaction is attempted first, best effort), except for 'transaction.commit': those listeners are
     * independent, all of them run after the commit, and their failures arrive together in a
     * CommitHookException. Dependent steps belong in one listener.
     * Only if a transaction left open by a listener cannot be rolled back (or the connection
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
     * @throws Exception\CommitHookException When committed, but a transaction.commit listener failed or the connection state after it could not be verified
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
