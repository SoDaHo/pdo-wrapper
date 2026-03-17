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
        'IS', 'IS NOT',
    ];

    private DatabaseInterface $db;
    private string $table;
    private string $quoteChar;

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

    /**
     * Create a new query builder instance.
     *
     * @param DatabaseInterface $db Database connection
     * @param string $table Table name
     * @param string $quoteChar Quote character for identifiers (" or `)
     */
    public function __construct(DatabaseInterface $db, string $table, string $quoteChar = '"')
    {
        $this->db = $db;
        $this->table = $table;
        $this->quoteChar = $quoteChar;
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
     *
     * @param string|array<string, mixed> $column Column name or array of conditions
     * @param mixed $operatorOrValue Operator or value (if 2 args)
     * @param mixed $value Value (if 3 args)
     *
     * @throws QueryException When called with only column name (missing value)
     */
    public function where(string|array $column, mixed $operatorOrValue = null, mixed $value = null): self
    {
        // Array syntax: where(['active' => 1, 'role' => 'admin'])
        if (is_array($column)) {
            foreach ($column as $col => $val) {
                $this->where($col, '=', $val);
            }
            return $this;
        }

        // Must have at least 2 arguments for string column
        if ($operatorOrValue === null) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'where() requires at least 2 arguments: where(column, value) or where(column, operator, value)'
            );
        }

        // Two params: where('id', 5) → equals
        if ($value === null) {
            $value = $operatorOrValue;
            $operatorOrValue = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $this->validateOperator((string)$operatorOrValue),
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a WHERE IN condition.
     *
     * @param string $column Column name
     * @param array<int, mixed> $values Values to match
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
     * @param array<int, mixed> $values Values to exclude
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
     * @param array<int, mixed> $values [min, max] values
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
     * @param array<int, mixed> $values [min, max] values to exclude
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
     * @return bool True if at least one record exists
     */
    public function exists(): bool
    {
        return $this->count() > 0;
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
     */
    private function aggregate(string $function, string $column): mixed
    {
        $originalColumns = $this->columns;
        $originalLimit = $this->limit;
        $originalOffset = $this->offset;
        $originalOrderBy = $this->orderBy;

        $this->limit = null;
        $this->offset = null;
        $this->orderBy = [];

        if ($column === '*') {
            $this->columns = [new RawExpression("{$function}(*) as aggregate")];
        } else {
            $this->columns = [new RawExpression("{$function}({$this->quoteIdentifier($column)}) as aggregate")];
        }

        [$sql, $params] = $this->toSql();
        $stmt = $this->db->query($sql, $params);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->columns = $originalColumns;
        $this->limit = $originalLimit;
        $this->offset = $originalOffset;
        $this->orderBy = $originalOrderBy;

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
     * Does not support LIMIT, OFFSET, or ORDER BY (not portable across databases).
     *
     * @param array<string, mixed> $data Column => value pairs to update
     *
     * @throws QueryException When no WHERE conditions set (safety)
     * @throws QueryException When limit(), offset(), or orderBy() is set (not supported)
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

        [$whereSql, $whereParams] = $this->buildWhere();

        $setClauses = [];
        $params = [];

        foreach ($data as $column => $value) {
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
     * Does not support LIMIT, OFFSET, or ORDER BY (not portable across databases).
     *
     * @throws QueryException When no WHERE conditions set (safety)
     * @throws QueryException When limit(), offset(), or orderBy() is set (not supported)
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
                ' %s JOIN %s ON %s %s %s',
                $join['type'],
                $this->quoteIdentifier($join['table']),
                $this->quoteIdentifier($join['first']),
                $join['operator'],
                $this->quoteIdentifier($join['second'])
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

        // LIMIT (cast to int for security)
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        // OFFSET (cast to int for security)
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return [$sql, $params];
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
                    $clauses[] = $this->quoteIdentifier($column) . ' ' . $operator . ' ?';
                    $params[] = $where['value'] ?? null;
                    break;

                case 'in':
                    /** @var array<int, mixed> $values */
                    $values = is_array($where['values'] ?? null) ? $where['values'] : [];
                    $placeholders = implode(', ', array_fill(0, count($values), '?'));
                    $inOperator = ($where['not'] ?? false) ? 'NOT IN' : 'IN';
                    $clauses[] = $this->quoteIdentifier($column) . " {$inOperator} ({$placeholders})";
                    $params = array_merge($params, $values);
                    break;

                case 'between':
                    $betweenOperator = ($where['not'] ?? false) ? 'NOT BETWEEN' : 'BETWEEN';
                    /** @var array<int, mixed> $betweenValues */
                    $betweenValues = is_array($where['values'] ?? null) ? $where['values'] : [null, null];
                    $clauses[] = $this->quoteIdentifier($column) . " {$betweenOperator} ? AND ?";
                    $params[] = $betweenValues[0] ?? null;
                    $params[] = $betweenValues[1] ?? null;
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
            $clauses[] = $column . ' ' . $h['operator'] . ' ?';
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

        // Handle table.column format
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            return implode('.', array_map(
                fn ($p) => $this->quoteChar . str_replace($this->quoteChar, $escape, $p) . $this->quoteChar,
                $parts
            ));
        }

        return $this->quoteChar . str_replace($this->quoteChar, $escape, $identifier) . $this->quoteChar;
    }

    /**
     * Guard against SELECT-only clauses (LIMIT, OFFSET, ORDER BY) in update/delete.
     *
     * These clauses are silently ignored by update()/delete() SQL generation,
     * which could cause unintended data loss (e.g., deleting all rows instead of
     * a limited subset). This guard makes the error explicit.
     *
     * @param string $operation Operation name for error message ('update' or 'delete')
     *
     * @throws QueryException When limit, offset, or orderBy is set
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

        if (!empty($unsupported)) {
            throw new QueryException(
                message: ucfirst($operation) . ' failed',
                debugMessage: sprintf(
                    '%s does not support %s (not portable across databases). Use raw execute() for database-specific syntax.',
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
