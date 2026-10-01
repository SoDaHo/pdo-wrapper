<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use Closure;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Query\RawExpression;
use Sodaho\PdoWrapper\Traits\HasHooks;
use Throwable;

/**
 * Abstract base driver implementing common database operations.
 *
 * Provides PDO wrapper functionality, CRUD helpers, transactions,
 * and hooks. Extend this class for database-specific drivers.
 */
abstract class AbstractDriver implements DatabaseInterface
{
    use HasHooks;

    protected PDO $pdo;

    // =========================================================================
    // Query Execution
    // =========================================================================

    /**
     * Execute a SQL query and return the statement.
     *
     * Triggers 'query' hook on success, 'error' hook on failure. A failure that PDO reports by
     * returning false (non-exception error mode) counts as a failure. A PDOException thrown by a
     * 'query' hook is not a failed query: the statement ran, no 'error' hook fires, and it arrives
     * as QueryException with the message 'Query hook failed'; other hook exceptions pass unchanged.
     *
     * @param string $sql SQL query with placeholders
     * @param array<int|string, mixed> $params Parameters to bind
     *
     * @throws QueryException On query failure, or when a 'query' hook threw a PDOException
     *
     * @return PDOStatement Executed statement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $start = microtime(true);

        try {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt === false) {
                throw $this->silentFailure('PDO::prepare() returned false', $this->pdo->errorInfo());
            }
            if ($this->bindAndExecute($stmt, $params) === false) {
                throw $this->silentFailure('PDOStatement::execute() returned false', $stmt->errorInfo());
            }
        } catch (PDOException $e) {
            $this->trigger('error', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            throw new QueryException(
                message: 'Query failed',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: sprintf('%s | SQL: %s | Params: %s', $e->getMessage(), $sql, json_encode($params))
            );
        }

        $rows = $stmt->rowCount();

        // The statement ran: a hook failure must not look like a failed query, and 'error' must not fire.
        try {
            $this->trigger('query', [
                'sql' => $sql,
                'params' => $params,
                'duration' => microtime(true) - $start,
                'rows' => $rows,
            ]);
        } catch (PDOException $e) {
            throw new QueryException(
                message: 'Query hook failed',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: sprintf('%s | SQL: %s | Params: %s', $e->getMessage(), $sql, json_encode($params))
            );
        }

        return $stmt;
    }

    /**
     * Bind the parameters and execute the prepared statement: PDOStatement::execute($params), every
     * value bound as text. A driver overrides this when its database needs typed bindings.
     *
     * @param array<int|string, mixed> $params Positional (0-based) or named parameters
     *
     * @return bool False when PDO reports the failure without an exception
     */
    protected function bindAndExecute(PDOStatement $stmt, array $params): bool
    {
        return $stmt->execute($params);
    }

    /**
     * The exception for a statement that PDO reported as failed by returning false (non-exception
     * error mode): carries the driver's error code and the full errorInfo, like a thrown PDOException.
     *
     * @param array<int, mixed> $errorInfo PDO::errorInfo() or PDOStatement::errorInfo()
     */
    private function silentFailure(string $what, array $errorInfo): PDOException
    {
        $reason = is_string($errorInfo[2] ?? null) ? $errorInfo[2] : 'unknown error';
        $state = is_string($errorInfo[0] ?? null) ? $errorInfo[0] : '';
        $driverCode = is_int($errorInfo[1] ?? null) ? $errorInfo[1] : 0;

        $e = new PDOException(sprintf('%s: %s (SQLSTATE %s)', $what, $reason, $state), $driverCode);
        $e->errorInfo = $errorInfo;

        return $e;
    }

    /**
     * Execute a SQL statement and return affected rows.
     *
     * @param string $sql SQL statement with placeholders
     * @param array<int|string, mixed> $params Parameters to bind
     *
     * @throws QueryException On query failure
     *
     * @return int Number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Get the last inserted ID.
     *
     * @param string|null $name Sequence name (PostgreSQL) or null
     *
     * @throws QueryException If PDO fails to retrieve the ID
     *
     * @return string|false Last insert ID or false on failure
     */
    public function lastInsertId(?string $name = null): string|false
    {
        try {
            return $this->pdo->lastInsertId($name);
        } catch (PDOException $e) {
            throw new QueryException(
                message: 'Failed to get last insert ID',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
            );
        }
    }

    /**
     * Get the underlying PDO instance.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Whether the connection is inside a transaction.
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    // =========================================================================
    // Transactions
    // =========================================================================

    /**
     * Begin a transaction.
     *
     * Triggers 'transaction.begin' hook on success. A throwing hook must not leave the transaction
     * it was told about open: a rollback is attempted on raw PDO (best effort, no 'transaction.rollback'
     * hooks; if it fails, the transaction may still be open) and the hook's exception reaches the
     * caller, a PDOException as TransactionException.
     *
     * @throws TransactionException On failure, including PDO::beginTransaction() returning false (non-exception error mode)
     */
    public function beginTransaction(): void
    {
        try {
            $begun = $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to begin transaction',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
            );
        }

        // Only reachable with a non-exception error mode (allowed via 'options').
        if ($begun === false) {
            throw new TransactionException(
                message: 'Failed to begin transaction',
                debugMessage: 'PDO::beginTransaction() returned false'
            );
        }

        try {
            $this->trigger('transaction.begin', []);
        } catch (PDOException $e) {
            $this->rollbackRawQuietly();
            throw new TransactionException(
                message: 'Failed to begin transaction',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
            );
        } catch (Throwable $e) {
            $this->rollbackRawQuietly();
            throw $e;
        }
    }

    /**
     * Roll back on raw PDO if a transaction is open, without 'transaction.rollback' hooks and ignoring
     * failures: the exception that caused this is more important for debugging.
     */
    private function rollbackRawQuietly(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable) {
            // Rollback failed, but the original exception is more important for debugging
        }
    }

    /**
     * Commit the current transaction.
     *
     * Triggers 'transaction.commit' listeners after a successful commit. They cannot undo
     * the commit, so every listener runs and their failures are reported together - unless
     * a transaction left open by a listener cannot be rolled back (or the connection state
     * cannot be read): the remaining listeners are then skipped and reported as failures.
     *
     * @throws TransactionException When the commit itself failed; it may or may not have taken effect
     * @throws CommitHookException When committed, but a transaction.commit listener failed or the connection state after it could not be verified
     */
    public function commit(): void
    {
        try {
            $committed = $this->pdo->commit();
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to commit transaction',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
            );
        }

        // Only reachable with a non-exception error mode (allowed via 'options').
        if ($committed === false) {
            throw new TransactionException(
                message: 'Failed to commit transaction',
                debugMessage: 'PDO::commit() returned false'
            );
        }

        // Committed from here on: a listener error must not look like a failed commit.
        [$failures, $connectionInTransaction] = $this->runCommitListeners();

        if ($failures !== []) {
            throw new CommitHookException($failures[0], $failures, $connectionInTransaction);
        }
    }

    /**
     * Run every transaction.commit listener and collect the failures in listener order.
     *
     * Listeners are independent: a failing listener does not stop the next one. A transaction
     * a listener left open is rolled back before the next listener runs (best effort) - directly
     * on PDO, because dispatching transaction.rollback here would tell rollback listeners that
     * the committed transaction was rolled back. If that rollback fails or leaves the connection
     * in a transaction (MySQL completion_type=CHAIN opens the next one), or inTransaction() itself
     * fails, the connection state is unknown: the remaining listeners are skipped and the
     * transaction may still be open. Nothing here throws: the commit has happened.
     *
     * Per listener: its own exception, then a LogicException if it left a transaction open
     * (previous: the rollback error, if any) or if the state could not be read (previous: that
     * error), then one LogicException per skipped listener.
     *
     * The second element is true when the connection is, or may still be, in a transaction
     * afterwards: the rollback failed or did not end the transaction, or the state could not be
     * read (fail-closed).
     *
     * @return array{list<Throwable>, bool}
     */
    private function runCommitListeners(): array
    {
        $failures = [];
        $cleanupError = null;

        foreach ($this->hooks['transaction.commit'] ?? [] as $listener) {
            if ($cleanupError !== null) {
                $failures[] = new LogicException('listener skipped: connection left in transaction', previous: $cleanupError);
                continue;
            }

            try {
                $listener([]);
            } catch (Throwable $e) {
                $failures[] = $e;
            }

            try {
                $open = $this->pdo->inTransaction();
            } catch (Throwable $e) {
                $cleanupError = $e;
                $failures[] = new LogicException('connection state unknown after listener', previous: $e);
                continue;
            }

            if (!$open) {
                continue;
            }

            try {
                if ($this->pdo->rollBack() === false) {
                    $cleanupError = new TransactionException(
                        message: 'Failed to rollback transaction',
                        debugMessage: 'PDO::rollBack() returned false'
                    );
                } elseif ($this->pdo->inTransaction()) {
                    // e.g. MySQL completion_type=CHAIN: the rollback opened the next transaction
                    $cleanupError = new TransactionException(
                        message: 'Failed to rollback transaction',
                        debugMessage: 'connection still in a transaction after PDO::rollBack()'
                    );
                }
            } catch (Throwable $e) {
                $cleanupError = $e;
            }

            $failures[] = new LogicException('listener left a transaction open', previous: $cleanupError);
        }

        return [$failures, $cleanupError !== null];
    }

    /**
     * Roll back the current transaction.
     *
     * Triggers 'transaction.rollback' hook on success.
     *
     * @throws TransactionException On failure
     */
    public function rollback(): void
    {
        try {
            $rolledBack = $this->pdo->rollBack();
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to rollback transaction',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
            );
        }

        // Only reachable with a non-exception error mode (allowed via 'options').
        if ($rolledBack === false) {
            throw new TransactionException(
                message: 'Failed to rollback transaction',
                debugMessage: 'PDO::rollBack() returned false'
            );
        }

        // A PDOException from a hook keeps arriving as TransactionException (unchanged contract).
        try {
            $this->trigger('transaction.rollback', []);
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to rollback transaction',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
            );
        }
    }

    /**
     * Execute a callback within a transaction.
     *
     * Auto-commits on success, auto-rollback on exception. Four outcomes on failure:
     * - the transaction could not be started (BEGIN failed, or a transaction.begin listener threw):
     *   the callback did not run; after a throwing listener a rollback is attempted (best effort);
     *   the exception is re-thrown, a PDOException from the listener as TransactionException;
     * - the callback threw: rollback attempted, the callback's exception is re-thrown
     *   (best effort: if the rollback fails, the transaction may still be open). Measured on MySQL 8.0
     *   and MariaDB 11.4 with mysqlnd: after a deadlock (the server rolled the transaction back) and
     *   after a lock wait timeout (the server rolled back only the statement) PDO still reports the
     *   transaction, so the rollback is sent and the transaction.rollback listeners run; after a
     *   lost connection the rollback fails, no listener runs, and PDO still reported the transaction;
     * - the commit failed: rollback attempted, the TransactionException is re-thrown
     *   (the commit may or may not have taken effect);
     * - a transaction.commit listener failed: committed, no rollback, CommitHookException.
     *
     * @param Closure $callback Receives the driver instance
     *
     * @throws TransactionException When the transaction could not be started (see beginTransaction()) or the commit failed
     * @throws CommitHookException When committed, but a transaction.commit listener failed or the connection state after it could not be verified
     * @throws Throwable Re-throws the callback, begin listener or commit exception after rollback
     *
     * @return mixed Return value of the callback
     */
    public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            $this->rollbackQuietly();
            throw $e;
        }

        $this->commitOwnTransaction();

        return $result;
    }

    /**
     * Commit a transaction this driver began, rolling back only if the commit itself failed.
     *
     * @throws CommitHookException When committed, but a transaction.commit listener failed or the connection state after it could not be verified
     * @throws Throwable Re-throws the commit exception after rollback
     */
    private function commitOwnTransaction(): void
    {
        try {
            $this->commit();
        } catch (CommitHookException $e) {
            // Committed: nothing to roll back. commit() already rolled back (best effort) what a listener left open.
            throw $e;
        } catch (Throwable $e) {
            // The commit itself failed; some drivers (e.g. SQLite) keep the transaction open.
            $this->rollbackQuietly();
            throw $e;
        }
    }

    /**
     * Roll back if a transaction is open, ignoring failures: the original exception is more important for debugging.
     */
    private function rollbackQuietly(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->rollback();
            }
        } catch (Throwable) {
            // Rollback failed, but original exception is more important for debugging
        }
    }

    // =========================================================================
    // CRUD Helper
    // =========================================================================

    /**
     * Insert a row and return the last insert ID.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $data Column => value pairs
     *
     * @throws QueryException When $data is empty or query fails
     *
     * @return int|string Last insert ID
     */
    public function insert(string $table, array $data): int|string
    {
        if (empty($data)) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: 'Cannot insert empty data'
            );
        }

        [$columns, $values, $params] = $this->buildInsertParts($data);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            $columns,
            $values
        );

        $this->query($sql, $params);

        $lastId = $this->lastInsertId();

        if ($lastId === false) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: sprintf('Failed to retrieve last insert ID | SQL: %s | Params: %s', $sql, json_encode(array_values($data)))
            );
        }

        return $lastId;
    }

    /**
     * Insert a row only when a condition holds, in one statement.
     *
     * Renders `INSERT INTO table (...) SELECT ?, ?, ... WHERE (condition)`; MySQL/MariaDB need
     * `FROM DUAL` before a WHERE without a table. The row's values are bound first, then the
     * condition's bindings (see DatabaseInterface::insertWhen()).
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param string $condition Trusted condition SQL with ? placeholders (never built from user input)
     * @param array<array-key, mixed> $bindings Values for the condition's placeholders, in order
     *
     * @throws QueryException When $data or the condition is empty, a binding is a RawExpression, or the query fails
     *
     * @return int Inserted rows: 1 or 0
     */
    public function insertWhen(string $table, array $data, string $condition, array $bindings = []): int
    {
        if (empty($data)) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: 'Cannot insert empty data'
            );
        }
        if (trim($condition) === '') {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: 'insertWhen() needs a condition'
            );
        }
        foreach ($bindings as $binding) {
            if ($binding instanceof RawExpression) {
                throw new QueryException(
                    message: 'Insert failed',
                    debugMessage: 'insertWhen() binds the condition values; write a raw expression into the condition instead'
                );
            }
        }

        [$columns, $values, $params] = $this->buildInsertParts($data);

        $sql = sprintf(
            'INSERT INTO %s (%s) SELECT %s%s WHERE (%s)',
            $this->quoteIdentifier($table),
            $columns,
            $values,
            $this->getDialect() === \Sodaho\PdoWrapper\Query\QueryBuilder::DIALECT_MYSQL ? ' FROM DUAL' : '',
            trim($condition)
        );

        return $this->execute($sql, [...$params, ...array_values($bindings)]);
    }

    /**
     * Update rows matching WHERE conditions.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $data Column => value pairs to update
     * @param array<string, mixed> $where WHERE conditions (column => value)
     *
     * @throws QueryException When $data or $where is empty (safety)
     *
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, array $where): int
    {
        if (empty($data)) {
            throw new QueryException(
                message: 'Update failed',
                debugMessage: 'Cannot update with empty data'
            );
        }

        if (empty($where)) {
            throw new QueryException(
                message: 'Update failed',
                debugMessage: 'Cannot update without WHERE conditions (safety check)'
            );
        }

        [$setSql, $params] = $this->buildSetClause($data);
        [$whereSql, $whereParams] = $this->buildWhereClause($where);
        $params = array_merge($params, $whereParams);

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            $setSql,
            $whereSql
        );

        return $this->execute($sql, $params);
    }

    /**
     * Delete rows matching WHERE conditions.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $where WHERE conditions (column => value)
     *
     * @throws QueryException When $where is empty (safety)
     *
     * @return int Number of affected rows
     */
    public function delete(string $table, array $where): int
    {
        if (empty($where)) {
            throw new QueryException(
                message: 'Delete failed',
                debugMessage: 'Cannot delete without WHERE conditions (safety check)'
            );
        }

        [$whereSql, $params] = $this->buildWhereClause($where);

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            $whereSql
        );

        return $this->execute($sql, $params);
    }

    /**
     * Find a single row by WHERE conditions.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $where WHERE conditions (column => value)
     *
     * @throws QueryException When $where is empty
     *
     * @return array<string, mixed>|null Row as associative array or null if not found
     */
    public function findOne(string $table, array $where): ?array
    {
        if (empty($where)) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'findOne requires WHERE conditions. Use findAll() without WHERE to get all rows.'
            );
        }

        [$whereSql, $params] = $this->buildWhereClause($where);

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s LIMIT 1',
            $this->quoteIdentifier($table),
            $whereSql
        );

        $stmt = $this->query($sql, $params);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    /**
     * Find all rows matching WHERE conditions.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $where WHERE conditions (optional, empty = all rows)
     *
     * @throws QueryException On query failure
     *
     * @return array<int, array<string, mixed>> Array of rows as associative arrays
     */
    public function findAll(string $table, array $where = []): array
    {
        if (empty($where)) {
            $sql = sprintf('SELECT * FROM %s', $this->quoteIdentifier($table));
            $params = [];
        } else {
            [$whereSql, $params] = $this->buildWhereClause($where);
            $sql = sprintf(
                'SELECT * FROM %s WHERE %s',
                $this->quoteIdentifier($table),
                $whereSql
            );
        }

        $stmt = $this->query($sql, $params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update multiple rows by their key column.
     *
     * Each row must contain the key column for matching. Without an active transaction, the
     * rows are updated in an own transaction with the same outcomes as transaction().
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<int, array<string, mixed>> $rows Array of rows, each with key column
     * @param string $keyColumn Column to match rows (default: 'id')
     *
     * @throws QueryException When a row is missing the key column
     * @throws TransactionException When the own transaction's commit failed
     * @throws CommitHookException When committed, but a transaction.commit listener failed or the connection state after it could not be verified
     *
     * @return int Total number of affected rows
     */
    public function updateMultiple(string $table, array $rows, string $keyColumn = 'id'): int
    {
        if (empty($rows)) {
            return 0;
        }

        $manageTransaction = !$this->pdo->inTransaction();

        if ($manageTransaction) {
            $this->beginTransaction();
        }

        try {
            $affected = 0;

            foreach ($rows as $row) {
                if (!array_key_exists($keyColumn, $row)) {
                    throw new QueryException(
                        message: 'Update failed',
                        debugMessage: sprintf('Missing key column "%s" in row', $keyColumn)
                    );
                }

                $keyValue = $row[$keyColumn];
                $data = array_diff_key($row, [$keyColumn => null]);

                if (!empty($data)) {
                    $affected += $this->update($table, $data, [$keyColumn => $keyValue]);
                }
            }
        } catch (Throwable $e) {
            if ($manageTransaction) {
                $this->rollbackQuietly();
            }
            throw $e;
        }

        if ($manageTransaction) {
            $this->commitOwnTransaction();
        }

        return $affected;
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Quote an identifier (table/column name).
     *
     * Handles schema.table and table.column format:
     * - "users" -> "users"
     * - "public.users" -> "public"."users"
     *
     * Override in driver for DB-specific quoting (e.g., backticks for MySQL).
     *
     * @param string $identifier Table or column name
     *
     * @return string Quoted identifier
     */
    protected function quoteIdentifier(string $identifier): string
    {
        $quote = $this->getQuoteChar();
        $escape = $quote . $quote;

        // Handle schema.table or table.column format
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            return implode('.', array_map(
                static fn ($part) => $quote . str_replace($quote, $escape, $part) . $quote,
                $parts
            ));
        }

        return $quote . str_replace($quote, $escape, $identifier) . $quote;
    }

    /**
     * Build the column list, the VALUES list and the params of an INSERT.
     *
     * A RawExpression value is inlined into the VALUES list instead of being bound
     * (SECURITY: never pass user input to Database::raw()).
     *
     * @param array<string, mixed> $data Column => value pairs
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>} [columns sql, values sql, params]
     */
    protected function buildInsertParts(array $data): array
    {
        $columns = [];
        $values = [];
        $params = [];

        foreach ($data as $column => $value) {
            $columns[] = $this->quoteIdentifier($column);
            if ($value instanceof RawExpression) {
                $values[] = (string) $value;
                continue;
            }
            $values[] = '?';
            $params[] = $value;
        }

        return [implode(', ', $columns), implode(', ', $values), $params];
    }

    /**
     * Build the SET clause of an UPDATE and its params.
     *
     * A RawExpression value is inlined instead of being bound (SECURITY: never pass user input to Database::raw()).
     *
     * @param array<string, mixed> $data Column => value pairs
     *
     * @return array{0: string, 1: array<int, mixed>} [sql, params]
     */
    protected function buildSetClause(array $data): array
    {
        $clauses = [];
        $params = [];

        foreach ($data as $column => $value) {
            if ($value instanceof RawExpression) {
                $clauses[] = $this->quoteIdentifier($column) . ' = ' . $value;
                continue;
            }
            $clauses[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }

        return [implode(', ', $clauses), $params];
    }

    /**
     * Build WHERE clause from conditions array.
     *
     * A RawExpression value is inlined instead of being bound (SECURITY: never pass user input to Database::raw()).
     *
     * @param array<string, mixed> $where Column => value pairs
     *
     * @return array{0: string, 1: array<int, mixed>} [sql, params] - SQL string and parameter values
     */
    protected function buildWhereClause(array $where): array
    {
        $clauses = [];
        $params = [];

        foreach ($where as $column => $value) {
            if ($value === null) {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: sprintf(
                        'NULL value for column "%s" in WHERE condition. Use whereNull() via the query builder, or a raw query with IS NULL.',
                        $column
                    )
                );
            }
            if ($value instanceof RawExpression) {
                $clauses[] = $this->quoteIdentifier($column) . ' = ' . $value;
                continue;
            }
            $clauses[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Current date and time as a raw SQL expression for insert()/update()/where() values.
     *
     * The shipped drivers return their dialect's statement-time expression (MySQL `NOW()`,
     * PostgreSQL `CAST(statement_timestamp() AS TIMESTAMP(0))`, SQLite `datetime('now', 'localtime')`);
     * this default is the SQL standard `CURRENT_TIMESTAMP`. Override in a custom driver.
     */
    public function now(): RawExpression
    {
        return new RawExpression('CURRENT_TIMESTAMP');
    }

    /**
     * Current UTC date and time as a raw SQL expression (a zoneless value).
     *
     * The shipped drivers return their dialect's expression (MySQL `UTC_TIMESTAMP()`, PostgreSQL
     * `CAST(statement_timestamp() AT TIME ZONE 'UTC' AS TIMESTAMP(0))`, SQLite `datetime('now')`);
     * this default is `CURRENT_TIMESTAMP`, which is UTC only when the dialect evaluates it in UTC
     * and the session's time zone is UTC. Override in a custom driver.
     */
    public function utcNow(): RawExpression
    {
        return new RawExpression('CURRENT_TIMESTAMP');
    }

    /**
     * Get the quote character for identifiers.
     *
     * Override in driver for DB-specific quoting.
     * - PostgreSQL: " (double quote)
     * - MySQL, SQLite: ` (backtick; SQLite would read an unknown double-quoted name as a string)
     *
     * @return string Quote character
     */
    protected function getQuoteChar(): string
    {
        return '"';
    }

    /**
     * Get the SQL dialect the query builder renders for (one of QueryBuilder::DIALECT_*).
     *
     * The bundled drivers override it. The default derives it from the quote character, as the
     * builder did before it knew dialects: a backtick means MySQL (a custom driver that only
     * overrides getQuoteChar() keeps MySQL's LIKE escaping and lock syntax), anything else ANSI,
     * which renders FOR UPDATE / FOR SHARE, IS [NOT] DISTINCT FROM and OFFSET without LIMIT as
     * PostgreSQL does.
     */
    protected function getDialect(): string
    {
        return $this->getQuoteChar() === '`'
            ? \Sodaho\PdoWrapper\Query\QueryBuilder::DIALECT_MYSQL
            : \Sodaho\PdoWrapper\Query\QueryBuilder::DIALECT_ANSI;
    }

    // =========================================================================
    // Query Builder
    // =========================================================================

    /**
     * Create a query builder for the given table.
     *
     * @param string $table Table name (supports schema.table format)
     */
    public function table(string $table): \Sodaho\PdoWrapper\Query\QueryBuilder
    {
        return new \Sodaho\PdoWrapper\Query\QueryBuilder($this, $table, $this->getQuoteChar(), $this->getDialect());
    }
}
