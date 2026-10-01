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

    /** @var array<int, array{column: string, direction: string, valid: bool}> */
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
     * @param string|null $dialect One of the DIALECT_* constants; null derives it from the quote character (` = MySQL, otherwise ANSI).
     *                             Drivers pass their dialect from table(); pass DIALECT_SQLITE yourself for a SQLite builder (backtick, too)
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
     * Add a raw WHERE condition with bound values.
     *
     * For conditions the other where*() methods cannot express: an expression on the left
     * (LOWER(email) = ?), an OR group, a database function. The SQL is used as given, in
     * parentheses, joined to the other conditions with AND; the values are bound to its ?
     * placeholders in order (positional placeholders only, like the rest of the builder).
     *
     * SECURITY: the SQL is trusted developer code, never build it from user input; user input
     * belongs in $bindings. A RawExpression is not accepted as a binding: write it into the SQL.
     *
     * @param string $sql Condition with ? placeholders, e.g. 'LOWER(email) = ?'
     * @param array<array-key, mixed> $bindings Values for the placeholders, in order
     *
     * @throws QueryException When $sql is empty or a binding is a RawExpression
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        if (trim($sql) === '') {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'whereRaw() needs a condition'
            );
        }
        foreach ($bindings as $binding) {
            if ($binding instanceof RawExpression) {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: 'whereRaw() binds its values; write a raw expression into the SQL instead'
                );
            }
        }

        $this->wheres[] = [
            'type' => 'raw',
            'sql' => trim($sql),
            'bindings' => array_values($bindings),
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
     * An unknown direction falls back to ASC for selects; delete()->limit() refuses it instead,
     * because there the direction decides which rows are deleted.
     *
     * @param string $column Column to order by
     * @param string $direction ASC or DESC (default: ASC)
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $given = $direction;
        $direction = strtoupper(trim($direction));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }

        $this->orderBy[] = [
            'column' => $column,
            'direction' => $direction,
            'valid' => strtoupper(trim($given)) === $direction,
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
     * projection yields a row even over an empty table). With groupBy() the aliased select() entries
     * stay, so having() may refer to them. With having() but no groupBy() it is evaluated as
     * `count() > 0` instead, which drops the offset as before.
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
        // DISTINCT keeps the original projection ("SELECT DISTINCT 1" would collapse every row into one);
        // a grouped query keeps its aliased entries, so that having() may refer to them; otherwise a
        // constant (an aggregate alias without groupBy() would yield a row over an empty table)
        $query->columns = match (true) {
            $this->distinct => $this->columns,
            !empty($this->groupBy) => $this->aliasedColumns() ?: [new RawExpression('1')],
            default => [new RawExpression('1')],
        };
        $query->orderBy = [];
        $query->limit = 1;

        [$sql, $params] = $query->toSql();

        return $this->db->query($sql, $params)->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * The aliased select() entries ("col as x", raw('COUNT(*) AS n'), raw('COUNT(*) AS "n"')), one
     * per alias: what a grouped query keeps so that having() and groupBy() may refer to the aliases.
     *
     * @return array<int, string|RawExpression>
     */
    private function aliasedColumns(): array
    {
        $aliased = [];
        foreach ($this->columns as $entry) {
            $key = $this->aliasKey($entry);
            if ($key !== null) {
                $aliased[$key] ??= $entry;
            }
        }

        return array_values($aliased);
    }

    /**
     * The alias of a select() entry as a comparison key, or null: a trailing `AS name`. In a string
     * entry the name is bare (that is what quoteIdentifier() renders); in a raw expression it may
     * also be double-quoted or backtick-quoted, which PostgreSQL treats case-sensitively, so only
     * bare names are folded to lower case.
     */
    private function aliasKey(string|RawExpression $entry): ?string
    {
        if ($entry instanceof RawExpression) {
            if (preg_match('/\s+as\s+(["`]?)(\w+)\1$/i', (string) $entry, $match) === 1) {
                return $match[1] === '' ? strtolower($match[2]) : $match[1] . $match[2];
            }

            return null;
        }

        return preg_match('/\s+as\s+(\w+)$/i', $entry, $match) === 1 ? strtolower($match[1]) : null;
    }

    /**
     * Why the select() would repeat an output name in a derived table, or null: two string entries
     * with the same alias or column name (the last dot segment), a wildcard next to other entries,
     * a bare "*" over a join (the joined tables repeat names). Raw entries are not inspected.
     */
    private function distinctOutputNameConflict(): ?string
    {
        $names = [];
        foreach ($this->columns as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            if ($entry === '*' || str_ends_with($entry, '.*')) {
                if ($entry === '*' && !empty($this->joins)) {
                    return 'a bare "*" over a join repeats the joined tables\' column names';
                }
                if (count($this->columns) > 1) {
                    return sprintf('the wildcard "%s" next to other entries may repeat a column name', $entry);
                }
                continue;
            }
            $name = $this->aliasKey($entry);
            if ($name === null) {
                $dot = strrpos($entry, '.');
                $name = strtolower($dot === false ? $entry : substr($entry, $dot + 1));
            }
            if (isset($names[$name])) {
                return sprintf('"%s" appears twice as an output name', $name);
            }
            $names[$name] = true;
        }

        return null;
    }

    /**
     * Get the count of matching records.
     *
     * With distinct(): the number of distinct rows of the selected columns (or COUNT(DISTINCT col)
     * for a column). The select() must yield unique output names (MySQL rejects repeated ones in the
     * counted derived table): two entries with the same column name or alias, a wildcard next to other
     * entries, or a bare "*" over a join throw a QueryException; alias the columns. A single "table.*"
     * is allowed, raw() entries are not inspected.
     *
     * With groupBy(): the number of groups, having() included; the column argument is irrelevant then.
     * Only aliased select() entries ("col as x", raw('COUNT(*) AS n'); scalar or aggregate expressions,
     * one per alias) stay in the counted query, so groupBy() may refer to an alias, and having() where
     * the database allows it (MySQL/MariaDB and SQLite; PostgreSQL rejects select aliases in HAVING).
     * having() without groupBy() makes the whole set one group: count() is its row count and distinct()
     * only applies to count('column') then (a non-aggregated select() is rejected by every driver in
     * that case).
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
     * With distinct(): SUM(DISTINCT col). With groupBy() the value is ambiguous (one per group) and a
     * QueryException is thrown; the same holds for avg(), min() and max().
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
     * @return mixed Minimum value in the driver's native type (PostgreSQL returns numeric and
     *               date/time values as strings, MySQL and SQLite return integers for integer
     *               columns), or null if no rows
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
     * @return mixed Maximum value in the driver's native type (PostgreSQL returns numeric and
     *               date/time values as strings, MySQL and SQLite return integers for integer
     *               columns), or null if no rows
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

        if (!empty($this->groupBy)) {
            // One value per group is ambiguous for sum()/avg()/min()/max(); count() means "how many groups"
            // (having() applies): one row per group of the grouped select, counted in a derived table.
            // Only aliased select() entries stay in the inner select (one per alias), so that having() and
            // groupBy() can refer to the aliases; everything else is dropped: it does not affect the number
            // of groups, and unaliased or repeated entries could repeat column names, which MySQL rejects
            // in a derived table. DISTINCT is dropped too: it could merge groups with equal projections.
            if ($function !== 'COUNT') {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: sprintf(
                        '%s() with groupBy() is ambiguous (one value per group). Select the aggregate explicitly with Database::raw() and get().',
                        strtolower($function)
                    )
                );
            }
            $query->distinct = false;
            $query->columns = $this->aliasedColumns() ?: [new RawExpression('1 as g')];
            [$innerSql, $params] = $query->toSql();
            $sql = sprintf('SELECT COUNT(*) as aggregate FROM (%s) as grouped', $innerSql);
        } elseif ($this->distinct && $column === '*' && empty($this->having)) {
            // count() of the distinct rows: count the distinct select itself (with having() but no
            // groupBy() the whole set is one group: that case takes the plain aggregate path below).
            // The derived table needs unique output names (MySQL rejects repeated ones): what is
            // statically visible is checked, raw entries are not inspected.
            $conflict = $this->distinctOutputNameConflict();
            if ($conflict !== null) {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: sprintf('distinct()->count() counts a derived table, which needs unique output names: %s. Alias the columns, or count one column with count(\'column\').', $conflict)
                );
            }
            [$innerSql, $params] = $query->toSql();
            $sql = sprintf('SELECT COUNT(*) as aggregate FROM (%s) as distinct_rows', $innerSql);
        } else {
            // distinct() narrows the aggregate to distinct values of the column: COUNT(DISTINCT col), SUM(DISTINCT col);
            // "*" has no DISTINCT form (only reached with having(): one group, all rows)
            $target = $column === '*' ? '*' : $query->quoteIdentifier($column);
            $expression = ($this->distinct && $column !== '*') ? "{$function}(DISTINCT {$target})" : "{$function}({$target})";
            $query->distinct = false;
            $query->columns = [new RawExpression("{$expression} as aggregate")];
            [$sql, $params] = $query->toSql();
        }

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
     * Insert a row only when a condition holds, in one statement (see DatabaseInterface::insertWhen()).
     *
     * The condition is the argument: a where*()/whereRaw(), join, groupBy()/having(), orderBy(),
     * limit()/offset(), distinct() or row lock set on this builder is not part of the statement, so
     * the method refuses to run with one (insert() ignores them); a select() is harmless and ignored.
     *
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param string $condition Trusted condition SQL with ? placeholders
     * @param array<array-key, mixed> $bindings Values for the condition, bound after the row's values
     *
     * @throws QueryException When a builder clause is set, $data or the condition is empty, a binding is a RawExpression, or the query fails
     *
     * @return int Inserted rows: 1 or 0
     */
    public function insertWhen(array $data, string $condition, array $bindings = []): int
    {
        if ($this->wheres !== [] || $this->joins !== [] || $this->groupBy !== [] || $this->having !== [] || $this->orderBy !== [] || $this->limit !== null || $this->offset !== null || $this->distinct || $this->lock !== null) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: 'insertWhen() takes its condition as an argument; where()/whereRaw(), joins, groupBy()/having(), orderBy(), limit()/offset(), distinct() and locks set on the builder are not part of the statement.'
            );
        }

        return $this->db->insertWhen($this->table, $data, $condition, $bindings);
    }

    /**
     * Update rows matching the WHERE conditions.
     *
     * Requires at least one WHERE condition for safety.
     * Does not support LIMIT, OFFSET, ORDER BY, JOIN, GROUP BY or HAVING (not part of the generated
     * statement); select(), distinct() and a row lock are ignored.
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
     *
     * On MySQL/MariaDB, limit() deletes at most that many rows, in orderBy() order when given
     * (`DELETE ... ORDER BY ... LIMIT n`: "the oldest n", for deleting in batches; order by a unique
     * key, or add one as tie-breaker, so that the batch is deterministic). DELETE ... LIMIT is not
     * portable: on the other dialects limit() throws instead of silently deleting every matching row.
     * OFFSET, JOIN, GROUP BY, HAVING and an orderBy() without limit() are not supported (not part of
     * the generated statement); select(), distinct() and a row lock are ignored.
     *
     * @throws QueryException When no WHERE conditions set (safety)
     * @throws QueryException When offset(), join(), groupBy(), having() or an orderBy() without limit() is set, limit() is used on a dialect other than MySQL, or an orderBy() direction is not ASC/DESC
     *
     * @return int Number of affected rows
     */
    public function delete(): int
    {
        // Only MySQL/MariaDB render DELETE ... [ORDER BY ...] LIMIT n; elsewhere the guard throws as before
        $limited = $this->limit !== null && $this->dialect === self::DIALECT_MYSQL;
        $this->guardAgainstSelectClauses('delete', $limited);
        if ($limited) {
            // The direction decides which rows go: an unknown word ("DESCENDING", "down") must not quietly mean ASC
            foreach ($this->orderBy as $order) {
                if (!$order['valid']) {
                    throw new QueryException(
                        message: 'Delete failed',
                        debugMessage: sprintf('delete() with limit() needs an explicit ASC or DESC in orderBy() for "%s" (an unknown direction would silently become ASC and delete the wrong rows).', $order['column'])
                    );
                }
            }
        }

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
        if ($limited) {
            $sql .= $this->orderByClause() . ' LIMIT ' . $this->limit;
        }

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

        $sql .= $this->orderByClause();

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
     * The ORDER BY clause with a leading space, or an empty string.
     */
    private function orderByClause(): string
    {
        if (empty($this->orderBy)) {
            return '';
        }
        $orderClauses = [];
        foreach ($this->orderBy as $order) {
            $orderClauses[] = $this->quoteIdentifier($order['column']) . ' ' . $order['direction'];
        }

        return ' ORDER BY ' . implode(', ', $orderClauses);
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
                    if (($operator === 'LIKE' || $operator === 'NOT LIKE') && $this->dialect !== self::DIALECT_MYSQL) {
                        $clause .= " ESCAPE '\\'";
                    }
                    $clauses[] = $clause;
                    break;

                case 'raw':
                    // Trusted developer SQL (see whereRaw()); its values are bound in order with the others
                    $clauses[] = '(' . (string)($where['sql'] ?? '') . ')';
                    /** @var array<int, mixed> $bindings */
                    $bindings = is_array($where['bindings'] ?? null) ? array_values($where['bindings']) : [];
                    foreach ($bindings as $binding) {
                        $params[] = $binding;
                    }
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
     * every matching row of the base table). This guard makes the error explicit. select(),
     * distinct() and a row lock are ignored: they cannot change which rows an UPDATE/DELETE hits
     * (the statement takes its own row locks), so a builder locked for a first() may be reused.
     *
     * @param string $operation Operation name for error message ('update' or 'delete')
     * @param bool $orderedLimitAllowed True when the statement renders ORDER BY ... LIMIT (delete() on MySQL/MariaDB)
     *
     * @throws QueryException When limit, offset, orderBy, join, groupBy or having is set (limit and orderBy allowed together when $orderedLimitAllowed)
     */
    private function guardAgainstSelectClauses(string $operation, bool $orderedLimitAllowed = false): void
    {
        $unsupported = [];

        if ($this->limit !== null && !$orderedLimitAllowed) {
            $unsupported[] = 'limit()';
        }
        if ($this->offset !== null) {
            $unsupported[] = 'offset()';
        }
        if (!empty($this->orderBy) && !($orderedLimitAllowed && $this->limit !== null)) {
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
            $hint = ($operation === 'delete' && $this->limit !== null && $this->dialect !== self::DIALECT_MYSQL)
                ? sprintf(' delete() with limit() would need DELETE ... LIMIT, which only MySQL/MariaDB support (dialect "%s"): use a subquery in raw execute() instead.', $this->dialect)
                : '';
            throw new QueryException(
                message: ucfirst($operation) . ' failed',
                debugMessage: sprintf(
                    '%s does not support %s (not part of the generated statement; the affected rows could silently differ). Use raw execute() for database-specific syntax.%s',
                    $operation,
                    implode(', ', $unsupported),
                    $hint
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
