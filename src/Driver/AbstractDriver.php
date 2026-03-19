<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
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
     * Triggers 'query' hook on success, 'error' hook on failure.
     *
     * @param string $sql SQL query with placeholders
     * @param array<int|string, mixed> $params Parameters to bind
     *
     * @throws QueryException On query failure
     *
     * @return PDOStatement Executed statement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $start = microtime(true);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $this->trigger('query', [
                'sql' => $sql,
                'params' => $params,
                'duration' => microtime(true) - $start,
                'rows' => $stmt->rowCount(),
            ]);

            return $stmt;
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

    // =========================================================================
    // Transactions
    // =========================================================================

    /**
     * Begin a transaction.
     *
     * Triggers 'transaction.begin' hook on success.
     *
     * @throws TransactionException On failure
     */
    public function beginTransaction(): void
    {
        try {
            $this->pdo->beginTransaction();
            $this->trigger('transaction.begin', []);
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to begin transaction',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
            );
        }
    }

    /**
     * Commit the current transaction.
     *
     * Triggers 'transaction.commit' hook on success.
     *
     * @throws TransactionException On failure
     */
    public function commit(): void
    {
        try {
            $this->pdo->commit();
            $this->trigger('transaction.commit', []);
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to commit transaction',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
            );
        }
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
            $this->pdo->rollBack();
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
     * Auto-commits on success, auto-rollback on exception.
     *
     * @param Closure $callback Receives the driver instance
     *
     * @throws Throwable Re-throws any exception after rollback
     *
     * @return mixed Return value of the callback
     */
    public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            try {
                $this->rollback();
            } catch (Throwable) {
                // Rollback failed, but original exception is more important for debugging
            }
            throw $e;
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

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        $this->query($sql, array_values($data));

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

        $setClauses = [];
        $params = [];

        foreach ($data as $column => $value) {
            $setClauses[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }

        [$whereSql, $whereParams] = $this->buildWhereClause($where);
        $params = array_merge($params, $whereParams);

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $setClauses),
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
     * Each row must contain the key column for matching.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<int, array<string, mixed>> $rows Array of rows, each with key column
     * @param string $keyColumn Column to match rows (default: 'id')
     *
     * @throws QueryException When a row is missing the key column
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

            if ($manageTransaction) {
                $this->commit();
            }

            return $affected;
        } catch (Throwable $e) {
            if ($manageTransaction) {
                try {
                    $this->rollback();
                } catch (Throwable) {
                    // Rollback failed, but original exception is more important for debugging
                }
            }
            throw $e;
        }
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
        // Handle schema.table or table.column format
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            return implode('.', array_map(
                fn ($part) => '"' . str_replace('"', '""', $part) . '"',
                $parts
            ));
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Build WHERE clause from conditions array.
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
            $clauses[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Get the quote character for identifiers.
     *
     * Override in driver for DB-specific quoting.
     * - PostgreSQL/SQLite: " (double quote)
     * - MySQL: ` (backtick)
     *
     * @return string Quote character
     */
    protected function getQuoteChar(): string
    {
        return '"';
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
        return new \Sodaho\PdoWrapper\Query\QueryBuilder($this, $table, $this->getQuoteChar());
    }
}
