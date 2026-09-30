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
     * @throws Exception\QueryException
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
     * Begin a transaction.
     *
     * @throws Exception\TransactionException
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
     * Three outcomes on failure:
     * - the callback threw: rollback attempted, the callback's exception is re-thrown
     *   (best effort: if the rollback fails, the transaction may still be open);
     * - the commit failed: rollback attempted, TransactionException re-thrown
     *   (the commit may or may not have taken effect);
     * - a transaction.commit listener failed: committed, no rollback, CommitHookException.
     *
     * @param Closure $callback Receives the driver instance
     *
     * @throws Exception\CommitHookException When committed, but a transaction.commit listener failed or the connection state after it could not be verified
     * @throws \Throwable Re-throws the callback or commit exception after rollback
     *
     * @return mixed Return value of the callback
     */
    public function transaction(Closure $callback): mixed;

    /**
     * Register a hook callback for an event.
     *
     * Events: 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback'
     *
     * A throwing hook stops the remaining hooks of its event, except for 'transaction.commit':
     * those listeners are independent, all of them run after the commit, and their failures
     * arrive together in a CommitHookException. Dependent steps belong in one listener.
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
