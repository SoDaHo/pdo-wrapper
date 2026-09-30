<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Query;

use PDO;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * Fluent query builder for constructing SQL queries.
 *
 * Supports SELECT, INSERT, UPDATE, DELETE with WHERE conditions,
 * JOINs, ORDER BY, GROUP BY, HAVING, LIMIT, and OFFSET.
 */
class QueryBuilder
{
    /**
     * Allowed comparison operators (whitelist for security).
     */
    private const ALLOWED_OPERATORS = [
        '=', '!=', '<>', '<', '>', '<=', '>=',
        'LIKE', 'NOT LIKE',
        'IS', 'IS NOT', // null-safe equality, rendered per dialect (see comparison())
    ];

    /**
     * SQL dialects the builder renders for: row locks, IS / IS NOT and OFFSET without LIMIT differ.
     */
    public const DIALECT_ANSI = 'ansi';
    public const DIALECT_MYSQL = 'mysql';
    public const DIALECT_PGSQL = 'pgsql';
    public const DIALECT_SQLITE = 'sqlite';

    private DatabaseInterface $db;
    private string $table;
    private string $quoteChar;
    private string $dialect;

    /** @var array<int, string|RawExpression> */
    private array $columns = ['*'];

    /** @var array<int, array<string, mixed>> */
    private array $wheres = [];

    /** @var array<int, array<string, string>> */
    private array $joins = [];

    /** @var array<int, array{column: string, direction: string}> */
    private array $orderBy = [];

    private ?int $limit = null;
    private ?int $offset = null;

    /** @var array<int, string> */
    private array $groupBy = [];

    /** @var array<int, array{column: string|RawExpression, operator: string, value: mixed}> */
    private array $having = [];

    private bool $distinct = false;

    /** Row lock requested for the SELECT: 'update', 'share' or null */
    private ?string $lock = null;

    /**
     * Create a new query builder instance.
     *
     * @param DatabaseInterface $db Database connection
     * @param string $table Table name
     * @param string $quoteChar Quote character for identifiers (" or `)
     * @param string|null $dialect One of the DIALECT_* constants; null derives it from the quote character (` = MySQL, otherwise ANSI)
     *
     * @throws \InvalidArgumentException When the dialect is unknown
     */
    public function __construct(DatabaseInterface $db, string $table, string $quoteChar = '"', ?string $dialect = null)
    {
        $dialect ??= $quoteChar === '`' ? self::DIALECT_MYSQL : self::DIALECT_ANSI;
        if (!in_array($dialect, [self::DIALECT_ANSI, self::DIALECT_MYSQL, self::DIALECT_PGSQL, self::DIALECT_SQLITE], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown SQL dialect "%s"', $dialect));
        }

        $this->db = $db;
        $this->table = $table;
        $this->quoteChar = $quoteChar;
        $this->dialect = $dialect;
    }

    // =========================================================================
    // SELECT
    // =========================================================================

    /**
     * Set columns to select.
     *
     * Usage:
     * - select('*')
     * - select('id, name')
     * - select(['id', 'name'])
     * - select(['users.id', 'users.name as username'])
     * - select([Database::raw('COUNT(*) as total')]) - for aggregates
     *
     * For aggregate functions or raw SQL expressions, use Database::raw():
     * - select([Database::raw('COUNT(*)'), Database::raw('AVG(price)')])
     *
     * @param string|array<int, string|RawExpression> $columns Column(s) to select
     */
    public function select(string|array $columns = '*'): self
    {
        if ($columns === '*') {
            $this->columns = ['*'];
        } elseif (is_string($columns)) {
            $this->columns = array_map('trim', explode(',', $columns));
        } else {
            $this->columns = $columns;
        }

        return $this;
    }

    /**
     * Add DISTINCT to the query.
     */
    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    // =========================================================================
    // ROW LOCKS
    // =========================================================================

    /**
     * Lock the selected rows for update (SELECT ... FOR UPDATE) until the transaction ends.
     *
     * MySQL/MariaDB and PostgreSQL render the clause; SQLite has no row locks and omits it (its
     * write lock covers the whole database file). Aggregates (count() etc.) drop the lock, as
     * PostgreSQL rejects FOR UPDATE with aggregates; exists() keeps it. Not allowed together with
     * distinct(), groupBy() or having() (QueryException). Use inside a transaction, otherwise the
     * lock ends with the statement.
     */
    public function lockForUpdate(): self
    {
        $this->lock = 'update';
        return $this;
    }

    /**
     * Lock the selected rows against updates by others while allowing concurrent reads:
     * MySQL/MariaDB `LOCK IN SHARE MODE`, PostgreSQL `FOR SHARE`, SQLite omitted (see lockForUpdate()).
     */
    public function sharedLock(): self
    {
        $this->lock = 'share';
        return $this;
    }

    // =========================================================================
    // WHERE
    // =========================================================================

    /**
     * Add a WHERE condition.
     *
     * Usage:
     * - where('id', 5)           → id = 5
     * - where('id', '=', 5)      → id = 5
     * - where('age', '>', 18)    → age > 18
     * - where(['active' => 1])   → active = 1
     * - where('expires_at', '<', $db->now()) → expires_at < NOW()  (a RawExpression value is inlined, not bound)
     *
     * The argument count decides the form: with two arguments the second one is always the value
     * (so 'IS' for Iceland or 'LIKE' as a value is fine), with three it is the operator.
     *
     * @param string|array<string, mixed> $column Column name or array of conditions
     * @param mixed $operatorOrValue Operator or value (if 2 args)
     * @param mixed $value Value (if 3 args)
     *
     * @throws QueryException When the value is null (use whereNull()/whereNotNull()) or the operator is not allowed
     */
    public function where(string|array $column, mixed $operatorOrValue = null, mixed $value = null): self
    {
        // Array syntax: where(['active' => 1, 'role' => 'admin'])
        if (is_array($column)) {
            foreach ($column as $col => $val) {
                if ($val === null) {
                    throw new QueryException(
                        message: 'Query failed',
                        debugMessage: sprintf(
                            'Cannot use null value for column "%s" in where(). Use whereNull(\'%s\') or whereNotNull(\'%s\') instead.',
                            $col,
                            $col,
                            $col
                        )
                    );
                }
                $this->where($col, '=', $val);
            }
            return $this;
        }

        // Two arguments: where('id', 5) means equality, whatever the value looks like.
        // where(column: 'id', value: 5) leaves the operator null: equality as well.
        if (func_num_args() < 3) {
            $value = $operatorOrValue;
            $operator = '=';
        } elseif ($operatorOrValue === null) {
            $operator = '=';
        } else {
            $operator = $this->validateOperator((string) $operatorOrValue);
        }

        // where('col', null) and where('col', '=', null): a NULL comparison never matches by accident
        if ($value === null) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf(
                    'Cannot use a null value in where() (operator "%s"). Use whereNull(\'%s\') or whereNotNull(\'%s\') instead.',
                    $operator,
                    $column,
                    $column
                )
            );
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a WHERE IN condition.
     *
     * @param string $column Column name
     * @param array<array-key, mixed> $values Values to match (a RawExpression element is inlined)
     *
     * @throws QueryException When $values is empty
     */
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'whereIn requires a non-empty array'
            );
        }

        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'not' => false,
        ];

        return $this;
    }

    /**
     * Add a WHERE NOT IN condition.
     *
     * @param string $column Column name
     * @param array<array-key, mixed> $values Values to exclude (a RawExpression element is inlined)
     *
     * @throws QueryException When $values is empty
     */
    public function whereNotIn(string $column, array $values): self
    {
        if (empty($values)) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'whereNotIn requires a non-empty array'
            );
        }

        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'not' => true,
        ];

        return $this;
    }

    /**
     * Add a WHERE BETWEEN condition.
     *
     * @param string $column Column name
     * @param array<array-key, mixed> $values [min, max] values (a RawExpression bound is inlined)
     *
     * @throws QueryException When $values doesn't have exactly 2 elements
     */
    public function whereBetween(string $column, array $values): self
    {
        if (count($values) !== 2) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'whereBetween requires exactly 2 values'
            );
        }

        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'values' => $values,
            'not' => false,
        ];

        return $this;
    }

    /**
     * Add a WHERE NOT BETWEEN condition.
     *
     * @param string $column Column name
     * @param array<array-key, mixed> $values [min, max] values to exclude (a RawExpression bound is inlined)
     *
     * @throws QueryException When $values doesn't have exactly 2 elements
     */
    public function whereNotBetween(string $column, array $values): self
    {
        if (count($values) !== 2) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'whereNotBetween requires exactly 2 values'
            );
        }

        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'values' => $values,
            'not' => true,
        ];

        return $this;
    }

    /**
     * Add a WHERE IS NULL condition.
     *
     * @param string $column Column name
     */
    public function whereNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'not' => false,
        ];

        return $this;
    }

    /**
     * Add a WHERE IS NOT NULL condition.
     *
     * @param string $column Column name
     */
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'not' => true,
        ];

        return $this;
    }

    /**
     * Add a WHERE LIKE condition.
     *
     * @param string $column Column name
     * @param string $pattern LIKE pattern (use % for wildcards)
     */
    public function whereLike(string $column, string $pattern): self
    {
        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => 'LIKE',
            'value' => $pattern,
        ];

        return $this;
    }

    /**
     * Add a WHERE NOT LIKE condition.
     *
     * @param string $column Column name
     * @param string $pattern LIKE pattern to exclude
     */
    public function whereNotLike(string $column, string $pattern): self
    {
        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => 'NOT LIKE',
            'value' => $pattern,
        ];

        return $this;
    }

    // =========================================================================
    // JOINS
    // =========================================================================

    /**
     * Add an INNER JOIN.
     *
     * @param string $table Table to join
     * @param string $first First column (left side)
     * @param string $operator Comparison operator (=, <, >, etc.)
     * @param string $second Second column (right side)
     */
    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'INNER',
            'table' => $table,
            'first' => $first,
            'operator' => $this->validateOperator($operator),
            'second' => $second,
        ];

        return $this;
    }

    /**
     * Add a LEFT JOIN.
     *
     * @param string $table Table to join
     * @param string $first First column (left side)
     * @param string $operator Comparison operator
     * @param string $second Second column (right side)
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'LEFT',
            'table' => $table,
            'first' => $first,
            'operator' => $this->validateOperator($operator),
            'second' => $second,
        ];

        return $this;
    }

    /**
     * Add a RIGHT JOIN.
     *
     * @param string $table Table to join
     * @param string $first First column (left side)
     * @param string $operator Comparison operator
     * @param string $second Second column (right side)
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'RIGHT',
            'table' => $table,
            'first' => $first,
            'operator' => $this->validateOperator($operator),
            'second' => $second,
        ];

        return $this;
    }

    // =========================================================================
    // ORDER BY, LIMIT, OFFSET
    // =========================================================================

    /**
     * Add an ORDER BY clause.
     *
     * @param string $column Column to order by
     * @param string $direction ASC or DESC (default: ASC)
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }

        $this->orderBy[] = [
            'column' => $column,
            'direction' => $direction,
        ];

        return $this;
    }

    /**
     * Set the LIMIT clause.
     *
     * @param int $limit Maximum number of rows
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the OFFSET clause.
     *
     * @param int $offset Number of rows to skip
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    // =========================================================================
    // GROUP BY, HAVING
    // =========================================================================

    /**
     * Add a GROUP BY clause.
     *
     * @param string|array<int, string> $columns Column(s) to group by
     */
    public function groupBy(string|array $columns): self
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }

        $this->groupBy = array_merge($this->groupBy, $columns);

        return $this;
    }

    /**
     * Add a HAVING condition.
     *
     * Used with GROUP BY for aggregate conditions.
     *
     * @param string|RawExpression $column Column or aggregate function (use Database::raw() for aggregates)
     * @param string $operator Comparison operator
     * @param mixed $value Value to compare
     */
    public function having(string|RawExpression $column, string $operator, mixed $value): self
    {
        $this->having[] = [
            'column' => $column,
            'operator' => $this->validateOperator($operator),
            'value' => $value,
        ];

        return $this;
    }

    // =========================================================================
    // EXECUTE
    // =========================================================================

    /**
     * Execute the query and get all results.
     *
     * @throws QueryException On query failure
     *
     * @return array<int, array<string, mixed>> Array of rows as associative arrays
     */
    public function get(): array
    {
        [$sql, $params] = $this->toSql();
        $stmt = $this->db->query($sql, $params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Execute the query and get the first result.
     *
     * @throws QueryException On query failure
     *
     * @return array<string, mixed>|null First row or null if none found
     */
    public function first(): ?array
    {
        $query = clone $this;
        $results = $query->limit(1)->get();

        return $results[0] ?? null;
    }

    /**
     * Check if any records exist matching the query.
     *
     * Runs `SELECT 1 ... LIMIT 1` and keeps a requested row lock (unlike count(), which must drop it)
     * and an offset(), so `offset(50)->exists()` answers "is there a next page?". With distinct() the
     * selected columns stay in place, so the answer refers to the distinct result rows (an aggregate
     * projection yields a row even over an empty table). With having() but no groupBy() it is
     * evaluated as `count() > 0` instead, which drops the offset as before.
     *
     * @throws QueryException When a row lock is combined with distinct(), groupBy() or having()
     *
     * @return bool True if at least one record exists
     */
    public function exists(): bool
    {
        $this->assertLockIsPortable();

        // HAVING without GROUP BY needs an aggregate projection (SQLite rejects "SELECT 1 ... HAVING",
        // PostgreSQL would judge an empty overall group): keep the COUNT(*) evaluation there.
        if (!empty($this->having) && empty($this->groupBy)) {
            return $this->count() > 0;
        }

        $query = clone $this;
        // DISTINCT keeps the original projection: "SELECT DISTINCT 1" would collapse every row into one
        $query->columns = $this->distinct ? $this->columns : [new RawExpression('1')];
        $query->orderBy = [];
        $query->limit = 1;

        [$sql, $params] = $query->toSql();

        return $this->db->query($sql, $params)->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * Get the count of matching records.
     *
     * @param string $column Column to count (default: *)
     *
     * @return int Number of records
     */
    public function count(string $column = '*'): int
    {
        $result = $this->aggregate('COUNT', $column);
        return is_numeric($result) ? (int)$result : 0;
    }

    /**
     * Get the sum of a column.
     *
     * @param string $column Column to sum
     *
     * @return float|int|null Sum or null if no rows
     */
    public function sum(string $column): float|int|null
    {
        $result = $this->aggregate('SUM', $column);
        return is_numeric($result) ? (float)$result : null;
    }

    /**
     * Get the average of a column.
     *
     * @param string $column Column to average
     *
     * @return float|int|null Average or null if no rows
     */
    public function avg(string $column): float|int|null
    {
        $result = $this->aggregate('AVG', $column);
        return is_numeric($result) ? (float)$result : null;
    }

    /**
     * Get the minimum value of a column.
     *
     * @param string $column Column to check
     *
     * @return mixed Minimum value or null if no rows
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Get the maximum value of a column.
     *
     * @param string $column Column to check
     *
     * @return mixed Maximum value or null if no rows
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Execute an aggregate function.
     *
     * Uses clone to avoid mutating the original builder state.
     */
    private function aggregate(string $function, string $column): mixed
    {
        $query = clone $this;
        $query->limit = null;
        $query->offset = null;
        $query->orderBy = [];
        $query->lock = null; // PostgreSQL rejects FOR UPDATE with aggregates

        if ($column === '*') {
            $query->columns = [new RawExpression("{$function}(*) as aggregate")];
        } else {
            $query->columns = [new RawExpression("{$function}({$query->quoteIdentifier($column)}) as aggregate")];
        }

        [$sql, $params] = $query->toSql();
        $stmt = $this->db->query($sql, $params);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // @codeCoverageIgnoreStart
        // Defensive: fetch() never returns false for aggregates (they always return one row)
        if ($result === false) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        return $result['aggregate'] ?? null;
    }

    // =========================================================================
    // INSERT, UPDATE, DELETE
    // =========================================================================

    /**
     * Insert a row via the query builder.
     *
     * @param array<string, mixed> $data Column => value pairs
     *
     * @throws QueryException On failure
     *
     * @return int|string Last insert ID
     */
    public function insert(array $data): int|string
    {
        return $this->db->insert($this->table, $data);
    }

    /**
     * Update rows matching the WHERE conditions.
     *
     * Requires at least one WHERE condition for safety.
     * Does not support LIMIT, OFFSET, ORDER BY, JOIN, GROUP BY or HAVING (not part of the generated statement).
     *
     * @param array<string, mixed> $data Column => value pairs to update
     *
     * @throws QueryException When no WHERE conditions set (safety)
     * @throws QueryException When limit(), offset(), orderBy(), join(), groupBy() or having() is set (not supported)
     *
     * @return int Number of affected rows
     */
    public function update(array $data): int
    {
        $this->guardAgainstSelectClauses('update');

        if (empty($this->wheres)) {
            throw new QueryException(
                message: 'Update failed',
                debugMessage: 'Cannot update without WHERE conditions (safety check). Use raw execute() if you really want to update all rows.'
            );
        }

        if (empty($data)) {
            throw new QueryException(
                message: 'Update failed',
                debugMessage: 'Cannot update with empty data'
            );
        }

        [$whereSql, $whereParams] = $this->buildWhere();

        $setClauses = [];
        $params = [];

        foreach ($data as $column => $value) {
            // A RawExpression value is inlined, never bound (SECURITY: never pass user input to Database::raw())
            if ($value instanceof RawExpression) {
                $setClauses[] = $this->quoteIdentifier($column) . ' = ' . $value;
                continue;
            }
            $setClauses[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }

        $params = array_merge($params, $whereParams);

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($this->table),
            implode(', ', $setClauses),
            $whereSql
        );

        return $this->db->execute($sql, $params);
    }

    /**
     * Delete rows matching the WHERE conditions.
     *
     * Requires at least one WHERE condition for safety.
     * Does not support LIMIT, OFFSET, ORDER BY, JOIN, GROUP BY or HAVING (not part of the generated statement).
     *
     * @throws QueryException When no WHERE conditions set (safety)
     * @throws QueryException When limit(), offset(), orderBy(), join(), groupBy() or having() is set (not supported)
     *
     * @return int Number of affected rows
     */
    public function delete(): int
    {
        $this->guardAgainstSelectClauses('delete');

        if (empty($this->wheres)) {
            throw new QueryException(
                message: 'Delete failed',
                debugMessage: 'Cannot delete without WHERE conditions (safety check). Use raw execute() if you really want to delete all rows.'
            );
        }

        [$whereSql, $whereParams] = $this->buildWhere();

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($this->table),
            $whereSql
        );

        return $this->db->execute($sql, $whereParams);
    }

    // =========================================================================
    // DEBUG
    // =========================================================================

    /**
     * Get the SQL query and parameters without executing.
     *
     * Useful for debugging or logging.
     *
     * @return array{0: string, 1: array<int, mixed>} [sql, params]
     */
    public function toSql(): array
    {
        [$sql, $params] = $this->buildSelect();

        return [$sql, $params];
    }

    // =========================================================================
    // BUILDER METHODS
    // =========================================================================

    /**
     * Build the SELECT statement and collect params in correct SQL order.
     *
     * Parameters are collected in SQL clause order:
     * WHERE params first, then HAVING params.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildSelect(): array
    {
        $sql = 'SELECT ';
        /** @var array<int, mixed> $params */
        $params = [];

        if ($this->distinct) {
            $sql .= 'DISTINCT ';
        }

        // Columns
        if ($this->columns === ['*']) {
            $sql .= '*';
        } else {
            $quotedColumns = array_map(function ($col) {
                // RawExpression bypasses quoting (for aggregates, etc.)
                if ($col instanceof RawExpression) {
                    return (string) $col;
                }
                // Wildcard doesn't need quoting
                if ($col === '*') {
                    return '*';
                }
                return $this->quoteIdentifier($col);
            }, $this->columns);
            $sql .= implode(', ', $quotedColumns);
        }

        // FROM
        $sql .= ' FROM ' . $this->quoteIdentifier($this->table);

        // JOINS
        foreach ($this->joins as $join) {
            $sql .= sprintf(
                ' %s JOIN %s ON %s',
                $join['type'],
                $this->quoteIdentifier($join['table']),
                $this->comparison($this->quoteIdentifier($join['first']), $join['operator'], $this->quoteIdentifier($join['second']))
            );
        }

        // WHERE - params collected in SQL order
        if (!empty($this->wheres)) {
            [$whereSql, $whereParams] = $this->buildWhere();
            $sql .= ' WHERE ' . $whereSql;
            $params = array_merge($params, $whereParams);
        }

        // GROUP BY
        if (!empty($this->groupBy)) {
            $quotedGroupBy = array_map([$this, 'quoteIdentifier'], $this->groupBy);
            $sql .= ' GROUP BY ' . implode(', ', $quotedGroupBy);
        }

        // HAVING - params collected AFTER where params (SQL order)
        if (!empty($this->having)) {
            [$havingSql, $havingParams] = $this->buildHaving();
            $sql .= ' HAVING ' . $havingSql;
            $params = array_merge($params, $havingParams);
        }

        // ORDER BY
        if (!empty($this->orderBy)) {
            $orderClauses = [];
            foreach ($this->orderBy as $order) {
                $orderClauses[] = $this->quoteIdentifier($order['column']) . ' ' . $order['direction'];
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderClauses);
        }

        // LIMIT / OFFSET (typed as ?int, enforced by PHP's type system); MySQL and SQLite need a
        // LIMIT before an OFFSET, so an offset() without limit() gets the dialect's "no limit" value
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        } elseif ($this->offset !== null) {
            $sql .= $this->unlimitedLimit();
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        if ($this->lock !== null) {
            $this->assertLockIsPortable();
            $sql .= $this->lockClause();
        }

        return [$sql, $params];
    }

    /**
     * PostgreSQL rejects row locks with DISTINCT, GROUP BY and HAVING; keep the builder portable.
     *
     * @throws QueryException When a row lock is combined with distinct(), groupBy() or having()
     */
    private function assertLockIsPortable(): void
    {
        if ($this->lock !== null && ($this->distinct || !empty($this->groupBy) || !empty($this->having))) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'lockForUpdate()/sharedLock() cannot be combined with distinct(), groupBy() or having() (not portable across databases). Lock the rows with a plain select first.'
            );
        }
    }

    /**
     * LIMIT that MySQL and SQLite need before an OFFSET without LIMIT (PostgreSQL needs none).
     */
    private function unlimitedLimit(): string
    {
        return match ($this->dialect) {
            self::DIALECT_MYSQL => ' LIMIT 18446744073709551615',
            self::DIALECT_SQLITE => ' LIMIT -1',
            default => '',
        };
    }

    /**
     * Row lock clause for the SELECT in the dialect's syntax; SQLite has no row locks.
     */
    private function lockClause(): string
    {
        if ($this->dialect === self::DIALECT_SQLITE) {
            return '';
        }
        if ($this->lock === 'share') {
            return $this->dialect === self::DIALECT_MYSQL ? ' LOCK IN SHARE MODE' : ' FOR SHARE';
        }

        return ' FOR UPDATE';
    }

    /**
     * Render a comparison. IS / IS NOT with a bound value mean null-safe equality and are rendered
     * in the dialect's own syntax: SQLite `IS`, MySQL `<=>`, PostgreSQL/ANSI `IS NOT DISTINCT FROM`.
     * With a raw right side (Database::raw('TRUE'), raw('NULL'), raw('UNKNOWN')) they are passed
     * through unchanged: those truth tests were valid SQL before and keep their semantics.
     *
     * @param bool $rawRight True when $right is a RawExpression, not a placeholder
     */
    private function comparison(string $left, string $operator, string $right, bool $rawRight = false): string
    {
        if (($operator !== 'IS' && $operator !== 'IS NOT') || $rawRight) {
            return sprintf('%s %s %s', $left, $operator, $right);
        }

        $negated = $operator === 'IS NOT';

        return match ($this->dialect) {
            self::DIALECT_SQLITE => sprintf('%s %s %s', $left, $operator, $right),
            self::DIALECT_MYSQL => $negated ? sprintf('NOT (%s <=> %s)', $left, $right) : sprintf('%s <=> %s', $left, $right),
            default => sprintf('%s %s %s', $left, $negated ? 'IS DISTINCT FROM' : 'IS NOT DISTINCT FROM', $right),
        };
    }

    /**
     * Build WHERE clause and extract params.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhere(): array
    {
        $clauses = [];
        /** @var array<int, mixed> $params */
        $params = [];

        foreach ($this->wheres as $where) {
            $type = (string)($where['type'] ?? '');
            $column = (string)($where['column'] ?? '');

            switch ($type) {
                case 'basic':
                    $operator = (string)($where['operator'] ?? '=');
                    $value = $where['value'] ?? null;
                    // A RawExpression value is inlined, never bound (SECURITY: never pass user input to Database::raw())
                    if ($value instanceof RawExpression) {
                        $right = (string) $value;
                    } else {
                        $right = '?';
                        $params[] = $value;
                    }
                    $clause = $this->comparison($this->quoteIdentifier($column), $operator, $right, $value instanceof RawExpression);
                    // MySQL uses \ as default LIKE escape character, no ESCAPE clause needed.
                    // PostgreSQL and SQLite need an explicit ESCAPE clause - for raw patterns too, so the
                    // pattern semantics do not depend on how the value was given.
                    if (($operator === 'LIKE' || $operator === 'NOT LIKE') && $this->quoteChar !== '`') {
                        $clause .= " ESCAPE '\\'";
                    }
                    $clauses[] = $clause;
                    break;

                case 'in':
                    // array_values(): string keys would be renumbered by the later merge and could shadow each other
                    /** @var array<int, mixed> $values */
                    $values = is_array($where['values'] ?? null) ? array_values($where['values']) : [];
                    $slots = [];
                    foreach ($values as $item) {
                        if ($item instanceof RawExpression) {
                            $slots[] = (string) $item;
                            continue;
                        }
                        $slots[] = '?';
                        $params[] = $item;
                    }
                    $inOperator = ($where['not'] ?? false) ? 'NOT IN' : 'IN';
                    $clauses[] = $this->quoteIdentifier($column) . " {$inOperator} (" . implode(', ', $slots) . ')';
                    break;

                case 'between':
                    $betweenOperator = ($where['not'] ?? false) ? 'NOT BETWEEN' : 'BETWEEN';
                    // array_values(): ['min' => 1, 'max' => 2] must not silently become NULL AND NULL
                    /** @var array<int, mixed> $betweenValues */
                    $betweenValues = is_array($where['values'] ?? null) ? array_values($where['values']) : [null, null];
                    $bounds = [];
                    foreach ([$betweenValues[0] ?? null, $betweenValues[1] ?? null] as $bound) {
                        if ($bound instanceof RawExpression) {
                            $bounds[] = (string) $bound;
                            continue;
                        }
                        $bounds[] = '?';
                        $params[] = $bound;
                    }
                    $clauses[] = $this->quoteIdentifier($column) . " {$betweenOperator} {$bounds[0]} AND {$bounds[1]}";
                    break;

                case 'null':
                    $nullOperator = ($where['not'] ?? false) ? 'IS NOT NULL' : 'IS NULL';
                    $clauses[] = $this->quoteIdentifier($column) . ' ' . $nullOperator;
                    break;
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Build HAVING clause and extract params.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildHaving(): array
    {
        $clauses = [];
        /** @var array<int, mixed> $params */
        $params = [];

        foreach ($this->having as $h) {
            // RawExpression bypasses quoting (for aggregates)
            $column = $h['column'] instanceof RawExpression
                ? (string) $h['column']
                : $this->quoteIdentifier($h['column']);
            if ($h['value'] instanceof RawExpression) {
                $clauses[] = $this->comparison($column, $h['operator'], (string) $h['value'], true);
                continue;
            }
            $clauses[] = $this->comparison($column, $h['operator'], '?');
            $params[] = $h['value'];
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Quote an identifier (table/column name).
     *
     * Handles simple, dotted (table.column), and alias (column as alias) formats.
     * Escapes the quote character within identifiers to prevent SQL injection.
     */
    private function quoteIdentifier(string $identifier): string
    {
        // Handle alias: "column as alias" or "table.column as alias"
        if (preg_match('/^(.+)\s+as\s+(\w+)$/i', $identifier, $matches)) {
            return $this->quoteIdentifier(trim($matches[1])) . ' as ' . $matches[2];
        }

        // Escape character: double the quote char (standard SQL escaping)
        $escape = $this->quoteChar . $this->quoteChar;

        // Handle table.column format; "users.*" keeps its wildcard: "users".*
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            return implode('.', array_map(
                fn ($p) => $p === '*' ? '*' : $this->quoteChar . str_replace($this->quoteChar, $escape, $p) . $this->quoteChar,
                $parts
            ));
        }

        return $this->quoteChar . str_replace($this->quoteChar, $escape, $identifier) . $this->quoteChar;
    }

    /**
     * Guard against SELECT-only clauses (LIMIT, OFFSET, ORDER BY, JOIN, GROUP BY, HAVING) in update/delete.
     *
     * None of these clauses is part of the SQL that update()/delete() generate. Ignoring them
     * silently would change which rows are affected (a delete narrowed down by a join would hit
     * every matching row of the base table). This guard makes the error explicit.
     *
     * @param string $operation Operation name for error message ('update' or 'delete')
     *
     * @throws QueryException When limit, offset, orderBy, join, groupBy or having is set
     */
    private function guardAgainstSelectClauses(string $operation): void
    {
        $unsupported = [];

        if ($this->limit !== null) {
            $unsupported[] = 'limit()';
        }
        if ($this->offset !== null) {
            $unsupported[] = 'offset()';
        }
        if (!empty($this->orderBy)) {
            $unsupported[] = 'orderBy()';
        }
        if (!empty($this->joins)) {
            $unsupported[] = 'join()';
        }
        if (!empty($this->groupBy)) {
            $unsupported[] = 'groupBy()';
        }
        if (!empty($this->having)) {
            $unsupported[] = 'having()';
        }

        if (!empty($unsupported)) {
            throw new QueryException(
                message: ucfirst($operation) . ' failed',
                debugMessage: sprintf(
                    '%s does not support %s (not part of the generated statement; the affected rows could silently differ). Use raw execute() for database-specific syntax.',
                    $operation,
                    implode(', ', $unsupported)
                )
            );
        }
    }

    /**
     * Validate that an operator is in the allowed whitelist.
     */
    private function validateOperator(string $operator): string
    {
        $normalized = strtoupper(trim($operator));

        if (!in_array($normalized, self::ALLOWED_OPERATORS, true)) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf(
                    'Invalid operator "%s". Allowed: %s',
                    $operator,
                    implode(', ', self::ALLOWED_OPERATORS)
                )
            );
        }

        return $normalized;
    }
}
