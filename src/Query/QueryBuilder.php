<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Query;

use PDO;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\LockOutsideTransactionException;
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
        'IS', 'IS NOT', // null-safe equality, rendered as <=> (see comparison())
    ];

    /**
     * Escape character of every LIKE the builder renders, the one Database::escapeLike() writes.
     * It is bound (`LIKE ? ESCAPE ?`), never written into the SQL: a backslash literal means
     * different things depending on the SQL mode (NO_BACKSLASH_ESCAPES), a bound value means the
     * same everywhere.
     */
    private const LIKE_ESCAPE = '\\';

    private DatabaseInterface $db;
    private string $table;

    /** @var array<int, string|RawExpression> */
    private array $columns = ['*'];

    /**
     * The conditions, in order, each in the shape its kind has
     *
     * @var list<array{type: 'basic', column: string|RawExpression, operator: string, value: mixed}|array{type: 'raw', sql: string, bindings: list<mixed>}|array{type: 'in'|'between', column: string|RawExpression, values: array<array-key, mixed>, not: bool}|array{type: 'null', column: string|RawExpression, not: bool}>
     */
    private array $wheres = [];

    /** @var array<int, array<string, string>> */
    private array $joins = [];

    /** @var array<int, array{column: string|RawExpression, direction: string}> */
    private array $orderBy = [];

    private ?int $limit = null;
    private ?int $offset = null;

    /** @var array<int, string|RawExpression> */
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
     */
    public function __construct(DatabaseInterface $db, string $table)
    {
        $this->db = $db;
        $this->table = $table;
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
     *
     * @throws QueryException When a raw expression carries bindings (only a value may)
     */
    public function select(#[\SensitiveParameter] string|array $columns = '*'): self
    {
        if ($columns === '*') {
            $this->columns = ['*'];
        } elseif (is_string($columns)) {
            $this->columns = array_map('trim', explode(',', $columns));
        } else {
            $this->guardAgainstBoundRaw('select', $columns);
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
     * Aggregates (count() etc.) and exists() keep it: `SELECT COUNT(*) ... FOR UPDATE` locks the
     * rows it reads - in REPEATABLE READ the gaps between them too -, so a count followed by an
     * insert in the same transaction is not overtaken by another transaction's insert. Not
     * allowed together with distinct(), groupBy() or having() (QueryException): such a result is
     * not the rows the lock would hold. Only inside a transaction: outside of one the lock would end
     * with its own statement, and get(), first(), exists() and the aggregates refuse it with a
     * LockOutsideTransactionException before anything is sent (toSql() renders it all the same).
     */
    public function lockForUpdate(): self
    {
        $this->lock = 'update';
        return $this;
    }

    /**
     * Lock the selected rows against updates by others while allowing concurrent reads:
     * `LOCK IN SHARE MODE` (see lockForUpdate()).
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
     * - where('nick', 'IS', $nick)  → null-safe equality, also when $nick is null (IS NOT likewise)
     *
     * The argument count decides the form: with two arguments the second one is always the value
     * (so 'IS' for Iceland or 'LIKE' as a value is fine), with three it is the operator.
     *
     * The column may be an expression without bindings instead of a name - a JSON value
     * (Database::json('payload', '$.net')) or Database::raw('LOWER(email)'); the same holds for
     * the other where*() methods.
     *
     * @param string|RawExpression|array<string, mixed> $column Column name, an expression, or array of column => value conditions
     * @param mixed $operatorOrValue Operator or value (if 2 args)
     * @param mixed $value Value (if 3 args)
     *
     * @throws QueryException When the value is null with an operator other than IS / IS NOT (use whereNull()/whereNotNull()), the operator is not allowed, the array form has a numeric key, or the column is an expression with bindings
     */
    public function where(#[\SensitiveParameter] string|RawExpression|array $column, #[\SensitiveParameter] mixed $operatorOrValue = null, #[\SensitiveParameter] mixed $value = null): self
    {
        // Array syntax: where(['active' => 1, 'role' => 'admin'])
        if (is_array($column)) {
            $this->guardAgainstNumericKeys($column, 'where() with an array', 'Use where(\'column\', $value) instead.');
            // Every entry is checked before the first is added: a refused array leaves the builder as it was,
            // never with part of the filter (a caller that catches the exception and goes on would query with it)
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
            }
            foreach ($column as $col => $val) {
                $this->where($col, '=', $val); // a string key and a value that is not null: nothing left to refuse
            }
            return $this;
        }
        $this->guardAgainstBoundRaw('where', [$column]);

        // Two arguments: where('id', 5) means equality, whatever the value looks like.
        // where(column: 'id', value: 5) leaves the operator null: equality as well.
        if (func_num_args() < 3) {
            $value = $operatorOrValue;
            $operator = '=';
        } elseif ($operatorOrValue === null) {
            $operator = '=';
        } else {
            // Anything but a string is no operator: named by its type, validateOperator() refuses it
            $operator = $this->validateOperator(is_string($operatorOrValue) ? $operatorOrValue : get_debug_type($operatorOrValue));
        }

        // where('col', null) and where('col', '=', null): a NULL comparison never matches by accident.
        // IS / IS NOT are the null-safe comparison: there a null value is what the caller means.
        if ($value === null && $operator !== 'IS' && $operator !== 'IS NOT') {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf(
                    'Cannot use a null value in where() (operator "%s"). Use whereNull(\'%s\') or whereNotNull(\'%s\'), or the operator IS / IS NOT for a value that may be null.',
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
     * For conditions the other where*() methods cannot express: an OR group, a database function
     * with values of its own (an expression without bindings on the left goes into where() itself:
     * where(Database::raw('LOWER(email)'), $email)). The SQL is used as given, in
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
    public function whereRaw(string $sql, #[\SensitiveParameter] array $bindings = []): self
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
     * An empty list matches no row: the condition is rendered as `1 = 0`, so a select finds
     * nothing and an update or delete hits nothing, as `IN ()` would if SQL allowed it.
     *
     * @param string|RawExpression $column Column name, or an expression without bindings (see where())
     * @param array<array-key, mixed> $values Values to match (a RawExpression element is inlined)
     *
     * @throws QueryException When $values contains null, or the column is an expression with bindings
     */
    public function whereIn(#[\SensitiveParameter] string|RawExpression $column, #[\SensitiveParameter] array $values): self
    {
        $this->guardAgainstBoundRaw('whereIn', [$column]);
        if ($values === []) {
            $this->wheres[] = ['type' => 'raw', 'sql' => '1 = 0', 'bindings' => []];

            return $this;
        }
        $this->guardAgainstNullElement('whereIn', $column, $values);

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
     * @param string|RawExpression $column Column name, or an expression without bindings (see where())
     * @param array<array-key, mixed> $values Values to exclude (a RawExpression element is inlined)
     *
     * An empty list throws, unlike whereIn(): "not in nothing" would match every row, and in an
     * update or delete that is every row of the table - too much to happen because a list came
     * back empty.
     *
     * @throws QueryException When $values is empty or contains null, or the column is an expression with bindings
     */
    public function whereNotIn(#[\SensitiveParameter] string|RawExpression $column, #[\SensitiveParameter] array $values): self
    {
        $this->guardAgainstBoundRaw('whereNotIn', [$column]);
        if (empty($values)) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'whereNotIn() with an empty list would match every row: check for the empty list before, and skip the condition or the statement'
            );
        }
        $this->guardAgainstNullElement('whereNotIn', $column, $values);

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
     * @param string|RawExpression $column Column name, or an expression without bindings (see where())
     * @param array<array-key, mixed> $values [min, max] values (a RawExpression bound is inlined)
     *
     * @throws QueryException When $values doesn't have exactly 2 elements or one of them is null, or the column is an expression with bindings
     */
    public function whereBetween(#[\SensitiveParameter] string|RawExpression $column, #[\SensitiveParameter] array $values): self
    {
        $this->guardAgainstBoundRaw('whereBetween', [$column]);
        if (count($values) !== 2) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'whereBetween requires exactly 2 values'
            );
        }
        $this->guardAgainstNullBound('whereBetween', $column, $values);

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
     * @param string|RawExpression $column Column name, or an expression without bindings (see where())
     * @param array<array-key, mixed> $values [min, max] values to exclude (a RawExpression bound is inlined)
     *
     * @throws QueryException When $values doesn't have exactly 2 elements or one of them is null, or the column is an expression with bindings
     */
    public function whereNotBetween(#[\SensitiveParameter] string|RawExpression $column, #[\SensitiveParameter] array $values): self
    {
        $this->guardAgainstBoundRaw('whereNotBetween', [$column]);
        if (count($values) !== 2) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'whereNotBetween requires exactly 2 values'
            );
        }
        $this->guardAgainstNullBound('whereNotBetween', $column, $values);

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
     * @param string|RawExpression $column Column name, or an expression without bindings (see where())
     *
     * @throws QueryException When the column is an expression with bindings
     */
    public function whereNull(#[\SensitiveParameter] string|RawExpression $column): self
    {
        $this->guardAgainstBoundRaw('whereNull', [$column]);
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
     * @param string|RawExpression $column Column name, or an expression without bindings (see where())
     *
     * @throws QueryException When the column is an expression with bindings
     */
    public function whereNotNull(#[\SensitiveParameter] string|RawExpression $column): self
    {
        $this->guardAgainstBoundRaw('whereNotNull', [$column]);
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
     * Rendered as `LIKE ? ESCAPE ?` with the backslash bound as escape character,
     * so a pattern part run through Database::escapeLike() is literal whatever the engine's default
     * or SQL mode. The same holds for where() and having() with the LIKE / NOT LIKE operator.
     *
     * @param string|RawExpression $column Column name, or an expression without bindings (see where())
     * @param string $pattern LIKE pattern (use % for wildcards)
     *
     * @throws QueryException When the column is an expression with bindings
     */
    public function whereLike(#[\SensitiveParameter] string|RawExpression $column, #[\SensitiveParameter] string $pattern): self
    {
        $this->guardAgainstBoundRaw('whereLike', [$column]);
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
     * @param string|RawExpression $column Column name, or an expression without bindings (see where())
     * @param string $pattern LIKE pattern to exclude
     *
     * @throws QueryException When the column is an expression with bindings
     */
    public function whereNotLike(#[\SensitiveParameter] string|RawExpression $column, #[\SensitiveParameter] string $pattern): self
    {
        $this->guardAgainstBoundRaw('whereNotLike', [$column]);
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
     * The direction must be ASC or DESC (any case, surrounding whitespace ignored). Anything else
     * ("DESCENDING", "down", "DESC NULLS LAST") throws instead of silently sorting ascending: the
     * direction decides which rows a page shows and, in delete()->limit(), which rows are deleted.
     *
     * A string is always a column name, quoted; an expression is ordered by only as an object -
     * Database::raw('FIELD(status, ...)'), an alias of a select() entry - so that a string from a
     * request can never become SQL. The expression may not carry bindings.
     *
     * @param string|RawExpression $column Column to order by, or an expression
     * @param string $direction ASC or DESC (default: ASC)
     *
     * @throws QueryException When the direction is neither ASC nor DESC, or the expression carries bindings
     */
    public function orderBy(#[\SensitiveParameter] string|RawExpression $column, string $direction = 'ASC'): self
    {
        $normalized = strtoupper(trim($direction));
        if (!in_array($normalized, ['ASC', 'DESC'], true)) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('Invalid orderBy() direction "%s" for "%s". Allowed: ASC, DESC', $direction, (string) $column)
            );
        }
        $this->guardAgainstBoundRaw('orderBy', [$column]);

        $this->orderBy[] = ['column' => $column, 'direction' => $normalized];

        return $this;
    }

    /**
     * Set the LIMIT clause.
     *
     * @param int $limit Maximum number of rows (0 or more)
     *
     * @throws QueryException When $limit is negative (MariaDB rejects it)
     */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('limit() needs 0 or more, got %d', $limit)
            );
        }

        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the OFFSET clause.
     *
     * @param int $offset Number of rows to skip (0 or more)
     *
     * @throws QueryException When $offset is negative (MariaDB rejects it)
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('offset() needs 0 or more, got %d', $offset)
            );
        }

        $this->offset = $offset;
        return $this;
    }

    // =========================================================================
    // GROUP BY, HAVING
    // =========================================================================

    /**
     * Add a GROUP BY clause.
     *
     * A string is split at commas into column names; an expression (`DATE(created_at)`,
     * `LOWER(name)`) goes in as Database::raw(), alone or inside the array, and is rendered as
     * given. SECURITY: never build a raw expression from user input.
     *
     * @param string|RawExpression|array<int, string|RawExpression> $columns Column(s) or expression(s) to group by
     *
     * @throws QueryException When a raw expression carries bindings (only a value may)
     */
    public function groupBy(#[\SensitiveParameter] string|RawExpression|array $columns): self
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        } elseif ($columns instanceof RawExpression) {
            $columns = [$columns];
        }
        $this->guardAgainstBoundRaw('groupBy', $columns);

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
     * @param mixed $value Value to compare (null only with IS / IS NOT, the null-safe comparison)
     *
     * A string is a name, quoted: "COUNT(*)" is the output column a select() entry
     * Database::raw('COUNT(*)') carries (MariaDB names an unaliased expression after its text) -
     * without such an entry the query throws when it is built (see buildHaving()).
     *
     * @throws QueryException When the operator is not allowed, the value is null with an operator other than IS / IS NOT, or a raw expression used as the column carries bindings (as the value it may)
     */
    public function having(#[\SensitiveParameter] string|RawExpression $column, string $operator, #[\SensitiveParameter] mixed $value): self
    {
        $operator = $this->validateOperator($operator);
        $this->guardAgainstBoundRaw('having', [$column]);

        // "= NULL", "> NULL", "LIKE NULL" are never true: the condition would silently drop every group
        if ($value === null && $operator !== 'IS' && $operator !== 'IS NOT') {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf(
                    'Cannot use a null value in having() (operator "%s", column "%s"). Use the operator IS or IS NOT instead.',
                    $operator,
                    (string) $column
                )
            );
        }

        $this->having[] = [
            'column' => $column,
            'operator' => $operator,
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
     * @throws LockOutsideTransactionException When a row lock is requested outside of a transaction (nothing is sent)
     * @throws QueryException On query failure
     *
     * @return array<int, array<string, mixed>> Array of rows as associative arrays
     */
    public function get(): array
    {
        [$sql, $params] = $this->toSql();
        $this->refuseALockOutsideATransaction();
        $stmt = $this->db->query($sql, $params);
        /** @var array<int, array<string, mixed>> $rows FETCH_ASSOC: each row an array of column name => value */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Execute the query and get the first result.
     *
     * @throws QueryException On query failure
     * @throws LockOutsideTransactionException When a row lock is requested outside of a transaction (nothing is sent)
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
     * Runs `SELECT 1 ... LIMIT 1` and keeps a requested row lock and an offset(), so `offset(50)->exists()` answers "is there a next page?". With distinct() the
     * selected columns stay in place, so the answer refers to the distinct result rows (an aggregate
     * projection yields a row even over an empty table). With groupBy() the aliased select() entries
     * stay, so having() may refer to them. With having() but no groupBy() it is evaluated as
     * `count() > 0` instead, which drops the offset as before.
     *
     * @throws QueryException When a row lock is combined with distinct(), groupBy() or having()
     * @throws LockOutsideTransactionException When a row lock is requested outside of a transaction (nothing is sent)
     *
     * @return bool True if at least one record exists
     */
    public function exists(): bool
    {
        $this->assertTheLockFitsTheResult();
        $this->refuseALockOutsideATransaction();

        // HAVING without GROUP BY makes the whole set one group (see count()): keep the COUNT(*)
        // evaluation there.
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
     * Whether a select() entry is named so: an expression selected without an alias carries its text
     * as its name (MariaDB names the output column after it), an aliased entry its alias - compared
     * without case, as MariaDB compares column names.
     */
    private function selectsTheName(string $name): bool
    {
        foreach ($this->columns as $entry) {
            $alias = $this->aliasKey($entry);
            if ($alias !== null ? $alias === $this->quotedNameKey($name) : ($entry instanceof RawExpression && strcasecmp(trim((string) $entry), trim($name)) === 0)) {
                return true;
            }
        }

        return false;
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
     * The alias of a select() entry as a comparison key, or null: a trailing `AS name`. In a raw
     * expression the name may be bare, double-quoted or backtick-quoted. MariaDB compares aliases
     * without case, quoted or not: the key is folded like a column name (quotedNameKey()). Letters
     * of any script count (u, as in quoteIdentifier()), and the name ends the entry - a trailing
     * newline is no part of the pattern's end (D).
     */
    private function aliasKey(#[\SensitiveParameter] string|RawExpression $entry): ?string
    {
        $pattern = $entry instanceof RawExpression ? '/\s+as\s+(["`]?)(\w+)\1$/iuD' : '/\s+as\s+()(\w+)$/iuD';

        return preg_match($pattern, (string) $entry, $match) === 1 ? $this->quotedNameKey($match[2]) : null;
    }

    /**
     * A name the builder renders quoted (a column, a string entry's alias) as a comparison key.
     * MariaDB compares column names without case, in every script: folded to lower case with
     * mbstring - "Ä" and "ä" are one name, "a" and "ä" two (measured on 10.11).
     */
    private function quotedNameKey(string $name): string
    {
        return mb_strtolower($name, 'UTF-8');
    }

    /**
     * The column a reference names ("col", "table.col") as a comparison key: its last dot segment,
     * folded (quotedNameKey()).
     */
    private function columnNameKey(string $reference): string
    {
        $dot = strrpos($reference, '.');

        return $this->quotedNameKey($dot === false ? $reference : substr($reference, $dot + 1));
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
            $name = $this->aliasKey($entry) ?? $this->columnNameKey($entry);
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
     * for a column). The select() must yield unique output names (MariaDB rejects repeated ones in the
     * counted derived table): two entries with the same column name or alias, a wildcard next to other
     * entries, or a bare "*" over a join throw a QueryException; alias the columns. A single "table.*"
     * is allowed, raw() entries are not inspected.
     *
     * With groupBy(): the number of groups, having() included; the column argument is irrelevant then.
     * Only aliased select() entries ("col as x", raw('COUNT(*) AS n'); scalar or aggregate expressions,
     * one per alias) stay in the counted query, so groupBy() and having() may refer to an alias
     * (MariaDB accepts select aliases in HAVING).
     * having() without groupBy() makes the whole set one group: count() is its row count and distinct()
     * only applies to count('column') then.
     *
     * @param string $column Column to count (default: *)
     *
     * @throws QueryException When the connection delivers a value that is no number (a statement class of the caller's): never passed off as 0
     * @throws LockOutsideTransactionException When a row lock is requested outside of a transaction (nothing is sent)
     *
     * @return int Number of records
     */
    public function count(string $column = '*'): int
    {
        $result = $this->aggregate('COUNT', $column);
        // No row: a having() without groupBy() filtered out the one group - none to count
        if ($result === null) {
            return 0;
        }
        if (is_numeric($result)) {
            return (int) $result;
        }

        throw new QueryException(
            message: 'Query failed',
            debugMessage: sprintf('count() got %s from the connection: MariaDB delivers an integer here. The connection does not deliver the types this library promises (see MariaDbDriver).', get_debug_type($result))
        );
    }

    /**
     * Get the sum of a column, as the database delivers it.
     *
     * Not converted: a float would lose what the database computed exactly - a sum of BIGINT
     * values above 2^53, a DECIMAL sum. MariaDB sends the sum of integer and DECIMAL columns as a
     * numeric string ('75', '0.3000'), and a float for a FLOAT/DOUBLE column. Cast where a number
     * is wanted: (int), (float), or pass the string to an arbitrary-precision library.
     *
     * With distinct(): SUM(DISTINCT col). With groupBy() the value is ambiguous (one per group) and a
     * QueryException is thrown; the same holds for avg(), min() and max().
     *
     * @param string $column Column to sum
     *
     * @throws LockOutsideTransactionException When a row lock is requested outside of a transaction (nothing is sent)
     *
     * @return float|string|null The sum as MariaDB delivers it, or null without a value (no rows,
     *                           only NULL, or a having() without groupBy() that filtered out the
     *                           one group)
     */
    public function sum(string $column): float|string|null
    {
        return self::numberAsDelivered('sum', $this->aggregate('SUM', $column));
    }

    /**
     * Get the average of a column, as the database delivers it.
     *
     * MariaDB sends a numeric string whose number of decimals is the database's ('1.5000') and a
     * float for a FLOAT/DOUBLE column. See sum().
     *
     * @param string $column Column to average
     *
     * @throws LockOutsideTransactionException When a row lock is requested outside of a transaction (nothing is sent)
     *
     * @return float|string|null The average as MariaDB delivers it, or null without a value (no
     *                           rows, only NULL, or a having() that filtered out the one group)
     */
    public function avg(string $column): float|string|null
    {
        return self::numberAsDelivered('avg', $this->aggregate('AVG', $column));
    }

    /**
     * What MariaDB returns for SUM() and AVG(): a numeric string (they are DECIMAL for integer and
     * DECIMAL columns) or a float (for FLOAT and DOUBLE) - handed on as it is. Null for SQL NULL
     * (no rows, or only NULL). Anything else means the connection does not deliver MariaDB's
     * types (see MariaDbDriver): thrown, not passed off as "no value".
     *
     * @throws QueryException
     */
    private static function numberAsDelivered(string $function, #[\SensitiveParameter] mixed $value): float|string|null
    {
        if ($value === null || is_float($value) || is_string($value)) {
            return $value;
        }

        throw new QueryException(
            message: 'Query failed',
            debugMessage: sprintf('%s() got %s from the connection: MariaDB delivers a numeric string, a float or NULL here. The connection does not deliver the types this library promises (see MariaDbDriver).', $function, get_debug_type($value))
        );
    }

    /**
     * Get the minimum value of a column.
     *
     * @param string $column Column to check
     *
     * @throws LockOutsideTransactionException When a row lock is requested outside of a transaction (nothing is sent)
     *
     * @return mixed Minimum value in the driver's native type (MariaDB returns integers for
     *               integer columns), or null without a value (no rows, only NULL, or a having()
     *               without groupBy() that filtered out the one group)
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
     * @throws LockOutsideTransactionException When a row lock is requested outside of a transaction (nothing is sent)
     *
     * @return mixed Maximum value in the driver's native type (MariaDB returns integers for
     *               integer columns), or null without a value (no rows, only NULL, or a having()
     *               without groupBy() that filtered out the one group)
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
        // The lock stays (see lockForUpdate()); the combinations a select refuses are refused here
        // before any of them is taken apart, and so is a lock outside of a transaction
        $this->assertTheLockFitsTheResult();
        $this->refuseALockOutsideATransaction();

        $query = clone $this;
        $query->limit = null;
        $query->offset = null;
        $query->orderBy = [];

        if (!empty($this->groupBy)) {
            // One value per group is ambiguous for sum()/avg()/min()/max(); count() means "how many groups"
            // (having() applies): one row per group of the grouped select, counted in a derived table.
            // Only aliased select() entries stay in the inner select (one per alias), so that having() and
            // groupBy() can refer to the aliases; everything else is dropped: it does not affect the number
            // of groups, and unaliased or repeated entries could repeat column names, which MariaDB rejects
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
            // The derived table needs unique output names (MariaDB rejects repeated ones): what is
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

        // By position, not by the name "aggregate": PDO::ATTR_CASE may rename the result's keys.
        // The whole row, not fetchColumn(), whose false for "no row" could pass for a value. No
        // row: a having() without groupBy() filtered out the one group - no value.
        $row = $this->db->query($sql, $params)->fetch(PDO::FETCH_NUM);

        return is_array($row) ? $row[0] : null;
    }

    // =========================================================================
    // INSERT, UPDATE, DELETE
    // =========================================================================

    /**
     * Insert a row via the query builder.
     *
     * A where(), join, groupBy()/having(), orderBy(), limit()/offset(), distinct() or row lock set on
     * this builder is not part of an INSERT: it throws instead of being dropped without a word (the
     * row would be inserted whatever the condition said) - for an insert that depends on a
     * condition, use insertWhen().
     *
     * @param array<string, mixed> $data Column => value pairs
     *
     * @throws QueryException When a builder clause is set (nothing is sent), on failure, and after the row is inserted when the ID the database reports is no integer of PHP (see DatabaseInterface::insert())
     *
     * @return int Last insert ID, 0 when the database generated none
     */
    public function insert(#[\SensitiveParameter] array $data): int
    {
        $this->refuseClauses('insert', 'inserts one row, unconditionally (insertWhen() takes a condition)');

        return $this->db->insert($this->table, $data);
    }

    /**
     * Insert a row only when a condition holds, in one statement (see DatabaseInterface::insertWhen()).
     *
     * The condition is the argument: a where*()/whereRaw(), join, groupBy()/having(), orderBy(),
     * limit()/offset(), distinct() or row lock set on this builder is not part of the statement, so
     * the method refuses to run with one (as insert() does); a select() is harmless and ignored.
     *
     * With $update, a row that collides with an existing one changes it instead (see upsert()):
     * `INSERT ... SELECT ... FROM DUAL WHERE (condition) ON DUPLICATE KEY UPDATE ...`.
     *
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param string $condition Trusted condition SQL with ? placeholders
     * @param array<array-key, mixed> $bindings Values for the condition, bound after the row's values
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order, bound last
     *
     * @throws QueryException When a builder clause is set, $data or the condition is empty, a binding is a RawExpression, $update is given on a connection with ATTR_FOUND_ROWS or a persistent one, or the query fails
     *
     * @return int Inserted rows, 1 or 0; with $update MariaDB's count (1 inserted, 2 updated, 0 neither - insertWhenReturning() tells "condition false" from "unchanged")
     */
    public function insertWhen(#[\SensitiveParameter] array $data, string $condition, #[\SensitiveParameter] array $bindings = [], #[\SensitiveParameter] array $update = []): int
    {
        $this->refuseClauses('insertWhen', 'takes its condition as an argument');

        return $this->db->insertWhen($this->table, $data, $condition, $bindings, $update);
    }

    /**
     * insertWhen() that returns the row (`... RETURNING <columns>`): the inserted row, with $update
     * the existing row after the update - or null when the condition was false.
     *
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param string $condition Trusted condition SQL with ? placeholders
     * @param array<array-key, mixed> $bindings Values for the condition, bound after the row's values
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order, bound last
     * @param list<string|RawExpression> $columns What to return: column names, '*', or expressions without bindings (Database::raw('n * 2 AS twice'))
     *
     * @throws QueryException As insertWhen() (not for ATTR_FOUND_ROWS or a persistent connection), and when $columns is empty or holds an expression with bindings
     *
     * @return array<string, mixed>|null The row, or null when the condition was false
     */
    public function insertWhenReturning(#[\SensitiveParameter] array $data, string $condition, #[\SensitiveParameter] array $bindings = [], #[\SensitiveParameter] array $update = [], #[\SensitiveParameter] array $columns = ['*']): ?array
    {
        $this->refuseClauses('insertWhenReturning', 'takes its condition as an argument');

        return $this->db->insertWhenReturning($this->table, $data, $condition, $bindings, $update, $columns);
    }

    /**
     * Insert a row unless it collides with an existing one (see DatabaseInterface::insertIgnore()).
     *
     * A where*()/whereRaw(), join, groupBy()/having(), orderBy(), limit()/offset(), distinct() or
     * row lock set on this builder is not part of the statement, so it throws instead of being
     * ignored. A select() is ignored.
     *
     * @param array<string, mixed> $data Column => value pairs of the row
     *
     * @throws QueryException When builder clauses are set, $data is empty, the connection counts matched rows (ATTR_FOUND_ROWS) or may (a persistent one), or the query fails for another reason than a duplicate
     *
     * @return int Inserted rows: 1 or 0
     */
    public function insertIgnore(#[\SensitiveParameter] array $data): int
    {
        $this->refuseClauses('insertIgnore', 'inserts one row');

        return $this->db->insertIgnore($this->table, $data);
    }

    /**
     * Insert a row, or change the row it collides with on any unique key or the primary key, in
     * one statement: `INSERT INTO t (...) VALUES (...) ON DUPLICATE KEY UPDATE col = ?, ...` (see
     * DatabaseInterface::upsert()). MariaDB has no conflict target: a collision on any unique key
     * counts. The update is rendered in the order of $update and applied from left to right; a
     * value may be Database::raw() with bindings, Database::value('col') is the value the row
     * would have been inserted with. Clauses set on the builder throw, as for insertIgnore().
     *
     * @param array<string, mixed> $row Column => value pairs of the row
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     *
     * @throws QueryException When builder clauses are set, $row or $update is empty, the connection counts matched rows (ATTR_FOUND_ROWS) or may (a persistent one), or the query fails
     *
     * @return int 1 inserted, 2 updated, 0 the existing row already held those values
     */
    public function upsert(#[\SensitiveParameter] array $row, #[\SensitiveParameter] array $update): int
    {
        $this->refuseClauses('upsert', 'inserts or changes one row');

        return $this->db->upsert($this->table, $row, $update);
    }

    /**
     * upsert() that returns the row after the statement (`... RETURNING <columns>`): the inserted
     * row, or the existing one after the update - also when it already held those values.
     *
     * @param array<string, mixed> $row Column => value pairs of the row
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     * @param list<string|RawExpression> $columns What to return: column names, '*', or expressions without bindings (Database::raw('n * 2 AS twice'))
     *
     * @throws QueryException When builder clauses are set, $row, $update or $columns is empty, a column is an expression with bindings, or the query fails
     *
     * @return array<string, mixed> The row
     */
    public function upsertReturning(#[\SensitiveParameter] array $row, #[\SensitiveParameter] array $update, #[\SensitiveParameter] array $columns = ['*']): array
    {
        $this->refuseClauses('upsertReturning', 'inserts or changes one row');

        return $this->db->upsertReturning($this->table, $row, $update, $columns);
    }

    /**
     * The builder's clauses are not part of a single-row insert: refused instead of ignored.
     *
     * @throws QueryException When a clause other than select() is set
     */
    private function refuseClauses(string $method, string $what): void
    {
        if ($this->hasClauses()) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: sprintf('%s() %s; where()/whereRaw(), joins, groupBy()/having(), orderBy(), limit()/offset(), distinct() and locks set on the builder are not part of the statement.', $method, $what)
            );
        }
    }

    /**
     * Whether any clause other than select() is set on this builder.
     */
    private function hasClauses(): bool
    {
        return $this->wheres !== [] || $this->joins !== [] || $this->groupBy !== [] || $this->having !== [] || $this->orderBy !== [] || $this->limit !== null || $this->offset !== null || $this->distinct || $this->lock !== null;
    }

    /**
     * Add to a column in the rows matching the WHERE conditions, in one statement:
     * `UPDATE ... SET col = col + CAST(? AS SIGNED)` - atomic, no read before the write. $extra is
     * set in the same statement, after the column. The same rules as update(): at least one WHERE
     * condition, limit() with orderBy().
     *
     * The amount is bound as text, like every value, and cast so that the server adds it exactly:
     * a text operand would make MariaDB add in DOUBLE, and a BIGINT above 2^53 or a DECIMAL with
     * more than about 15 digits would come back rounded (measured on 10.11, 11.4 and 12.3). An
     * int is cast to SIGNED (integer arithmetic; on a DECIMAL column, DECIMAL arithmetic). A float
     * is bound as the shortest decimal text that reads back as the same float (0.1, not PHP's
     * `precision` setting, which cuts after 14 digits) and cast to DECIMAL(65,30); a float whose
     * text has more than 35 integer or 30 fraction digits, INF and NAN are refused. A column that
     * is NULL stays NULL (NULL + 1 is NULL); the row then counts as changed only when $extra
     * changes something (or the connection counts matched rows).
     *
     * With $extra, the column must not be set again there - also not in another case or as
     * "table.column": MariaDB takes those for the same column, and the second assignment would
     * silently replace the step. Names with characters beyond ASCII are refused then: MariaDB folds
     * their case by rules that differ between versions, so a match could not be ruled out.
     *
     * @param string $column Column to add to
     * @param int|float $by What is added (bound)
     * @param array<string, mixed> $extra Further column => value pairs to set
     *
     * @throws QueryException As update(), when $extra sets the column itself (in any case, or as
     *                        "table.column") or a name with $extra holds characters beyond ASCII,
     *                        and for a float DECIMAL(65,30) cannot hold
     *
     * @return int Number of affected rows
     */
    public function increment(string $column, #[\SensitiveParameter] int|float $by = 1, #[\SensitiveParameter] array $extra = []): int
    {
        return $this->step($column, '+', $by, $extra);
    }

    /**
     * Subtract from a column in the rows matching the WHERE conditions:
     * `SET col = col - CAST(? AS SIGNED)` (see increment()).
     *
     * @param array<string, mixed> $extra Further column => value pairs to set
     *
     * @throws QueryException As update(), when $extra sets the column itself or a name with $extra
     *                        holds characters beyond ASCII, and for a float DECIMAL(65,30) cannot
     *                        hold (see increment())
     *
     * @return int Number of affected rows
     */
    public function decrement(string $column, #[\SensitiveParameter] int|float $by = 1, #[\SensitiveParameter] array $extra = []): int
    {
        return $this->step($column, '-', $by, $extra);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @throws QueryException
     */
    private function step(string $column, string $sign, #[\SensitiveParameter] int|float $by, #[\SensitiveParameter] array $extra): int
    {
        $method = $sign === '+' ? 'increment' : 'decrement';
        // The column is a key of the assignments as well: a qualified one would be taken apart at its dot
        if (str_contains($column, '.')) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('%s() needs the plain name of a column, got "%s": a qualified name (table.column) would be taken apart at its dot and name the column after it. Pass the column\'s own name.', $method, $column)
            );
        }
        $this->guardTheColumnsToSet($extra, $method . '() with $extra');
        // MariaDB takes "Attempts" and "t.attempts" for the column "attempts": a second assignment
        // to it would silently replace the step
        foreach (array_keys($extra) as $key) {
            if (preg_match('/[^\x00-\x7F]/', $column . $key) === 1) {
                throw new QueryException(
                    message: 'Update failed',
                    debugMessage: sprintf('%s() with $extra compares the column names itself, and "%s" or "%s" holds characters beyond ASCII, whose case MariaDB folds by rules of its own: whether they name the same column cannot be ruled out. Set the extra columns with a separate update().', $method, $column, $key)
                );
            }
            if ($this->columnNameKey($key) === $this->columnNameKey($column)) {
                throw new QueryException(
                    message: 'Update failed',
                    debugMessage: sprintf('%s() changes "%s" itself; it cannot be set in $extra as well (as "%s")', $method, $column, $key)
                );
            }
        }
        if (is_float($by)) {
            $text = self::decimalText($by);
            if ($text === null) {
                throw new QueryException(
                    message: 'Update failed',
                    debugMessage: sprintf('%s() adds a float as DECIMAL(65,30), which cannot hold %s: more than 35 integer or 30 fraction digits, or no finite number. Use update() with Database::raw() for it.', $method, var_export($by, true))
                );
            }
            $step = new RawExpression(sprintf('%s %s CAST(? AS DECIMAL(65,30))', $this->quoteIdentifier($column), $sign), [$text]);
        } else {
            $step = new RawExpression(sprintf('%s %s CAST(? AS SIGNED)', $this->quoteIdentifier($column), $sign), [$by]);
        }

        return $this->update([$column => $step] + $extra);
    }

    /**
     * The float's exact text (FloatText), or null when DECIMAL(65,30) cannot hold it: more than 35
     * integer or 30 fraction digits, or INF or NAN.
     */
    private static function decimalText(#[\SensitiveParameter] float $value): ?string
    {
        if (!is_finite($value)) {
            return null;
        }
        $text = FloatText::of($value);
        [$integer, $fraction] = explode('.', ltrim($text, '-') . '.');

        return strlen($integer) > 35 || strlen($fraction) > 30 ? null : $text;
    }

    /**
     * Update rows matching the WHERE conditions.
     *
     * Requires at least one WHERE condition for safety.
     *
     * limit() updates at most that many rows, in orderBy() order (`UPDATE ... ORDER BY ... LIMIT
     * n`, for updating in batches; order by a unique key, or add one as tie-breaker, so that the
     * batch is deterministic): limit() without orderBy() throws. OFFSET, JOIN, GROUP BY, HAVING and
     * an orderBy() without limit() are not supported (not part of the generated statement);
     * select(), distinct() and a row lock are ignored.
     *
     * @param array<string, mixed> $data Column => value pairs to update
     *
     * @throws QueryException When no WHERE conditions set (safety), or a key of $data is an integer (a list, a column named by digits alone)
     * @throws QueryException When offset(), join(), groupBy(), having(), an orderBy() without limit() or a limit() without orderBy() is set
     *
     * @return int Number of affected rows
     */
    public function update(#[\SensitiveParameter] array $data): int
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
        $this->guardTheColumnsToSet($data, 'update()');

        [$whereSql, $whereParams] = $this->buildWhere();

        $setClauses = [];
        $params = [];

        // The assignments in the order of $data; a raw value's own bindings stand where it stands. A key
        // is the name of one column, as in the CRUD methods: no alias ("a as b" is that column)
        foreach ($data as $column => $value) {
            $setClauses[] = Sql::name((string) $column) . ' = ' . Sql::value($value, $params);
        }

        $params = array_merge($params, $whereParams);

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($this->table),
            implode(', ', $setClauses),
            $whereSql
        );
        if ($this->limit !== null) {
            $sql .= $this->orderByClause() . ' LIMIT ' . $this->limit;
        }

        return $this->db->execute($sql, $params);
    }

    /**
     * Delete rows matching the WHERE conditions.
     *
     * Requires at least one WHERE condition for safety.
     *
     * limit() deletes at most that many rows, in orderBy() order (`DELETE ... ORDER BY ... LIMIT
     * n`: "the oldest n", for deleting in batches; order by a unique key, or add one as
     * tie-breaker, so that the batch is deterministic): limit() without orderBy() throws. OFFSET,
     * JOIN, GROUP BY, HAVING and an orderBy() without limit() are not supported (not part of the
     * generated statement); select(), distinct() and a row lock are ignored.
     *
     * @throws QueryException When no WHERE conditions set (safety)
     * @throws QueryException When offset(), join(), groupBy(), having(), an orderBy() without limit() or a limit() without orderBy() is set
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
        if ($this->limit !== null) {
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
     * Parameters are collected in SQL clause order: the escape character of a LIKE in a join
     * condition, then the WHERE params, then the HAVING params.
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
            // A pattern taken from a column: the same bound escape character as in where() and having()
            if ($join['operator'] === 'LIKE' || $join['operator'] === 'NOT LIKE') {
                $sql .= ' ESCAPE ?';
                $params[] = self::LIKE_ESCAPE;
            }
        }

        // WHERE - params collected in SQL order
        if (!empty($this->wheres)) {
            [$whereSql, $whereParams] = $this->buildWhere();
            $sql .= ' WHERE ' . $whereSql;
            $params = array_merge($params, $whereParams);
        }

        // GROUP BY
        if (!empty($this->groupBy)) {
            // RawExpression bypasses quoting (grouping by an expression)
            $quotedGroupBy = array_map(
                fn (string|RawExpression $column): string => $column instanceof RawExpression ? (string) $column : $this->quoteIdentifier($column),
                $this->groupBy
            );
            $sql .= ' GROUP BY ' . implode(', ', $quotedGroupBy);
        }

        // HAVING - params collected AFTER where params (SQL order)
        if (!empty($this->having)) {
            [$havingSql, $havingParams] = $this->buildHaving();
            $sql .= ' HAVING ' . $havingSql;
            $params = array_merge($params, $havingParams);
        }

        $sql .= $this->orderByClause();

        // LIMIT / OFFSET (typed as ?int, enforced by PHP's type system); MariaDB needs a LIMIT
        // before an OFFSET, so an offset() without limit() gets its "no limit" value
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        } elseif ($this->offset !== null) {
            $sql .= $this->unlimitedLimit();
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        if ($this->lock !== null) {
            $this->assertTheLockFitsTheResult();
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
            $column = $order['column'];
            $orderClauses[] = ($column instanceof RawExpression ? (string) $column : $this->quoteReference($column)) . ' ' . $order['direction'];
        }

        return ' ORDER BY ' . implode(', ', $orderClauses);
    }

    /**
     * A row lock is refused with DISTINCT, GROUP BY and HAVING: such a result is not the rows the
     * lock would hold.
     *
     * @throws QueryException When a row lock is combined with distinct(), groupBy() or having()
     */
    private function assertTheLockFitsTheResult(): void
    {
        if ($this->lock !== null && ($this->distinct || !empty($this->groupBy) || !empty($this->having))) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'lockForUpdate()/sharedLock() cannot be combined with distinct(), groupBy() or having() (such a result is not the rows the lock would hold). Lock the rows with a plain select first.'
            );
        }
    }

    /**
     * The LIMIT MariaDB needs before an OFFSET without LIMIT: the largest it takes.
     */
    private function unlimitedLimit(): string
    {
        return ' LIMIT 18446744073709551615';
    }

    /**
     * A row lock outside of a transaction ends with its own statement: by the time the caller acts on
     * the rows, nothing holds them. Refused before anything is sent - no 'query.before' fires. Open
     * is a transaction the library began (currentTransaction(): when the server has ended it,
     * query() refuses the statement and says so) or one PDO reports (begun on raw PDO); a state
     * that cannot be read is none (fail-closed, the cause as previous). With autocommit switched
     * off and nothing sent yet PDO reports none, although the SELECT would open one: refused as
     * well - begin the transaction explicitly.
     *
     * @throws LockOutsideTransactionException
     */
    private function refuseALockOutsideATransaction(): void
    {
        if ($this->lock === null) {
            return;
        }
        try {
            if ($this->db->currentTransaction() !== null || $this->db->inTransaction()) {
                return;
            }
            $unreadable = null;
        } catch (\Throwable $e) {
            $unreadable = $e;
        }

        throw new LockOutsideTransactionException(
            previous: $unreadable,
            debugMessage: sprintf(
                '%s outside of a transaction: the lock would end with its own statement, and nothing would hold the rows afterwards. Read them inside transaction() or after beginTransaction()%s.',
                $this->lock === 'share' ? 'sharedLock()' : 'lockForUpdate()',
                $unreadable !== null ? ' (the transaction state could not be read: the previous exception)' : ''
            )
        );
    }

    /**
     * Row lock clause for the SELECT.
     */
    private function lockClause(): string
    {
        return $this->lock === 'share' ? ' LOCK IN SHARE MODE' : ' FOR UPDATE';
    }

    /**
     * Render a comparison. IS / IS NOT with a bound value mean null-safe equality: `<=>`.
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

        return $negated ? sprintf('NOT (%s <=> %s)', $left, $right) : sprintf('%s <=> %s', $left, $right);
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
            if ($where['type'] === 'raw') {
                // Trusted developer SQL (see whereRaw()); its values are bound in order with the others
                $clauses[] = '(' . $where['sql'] . ')';
                foreach ($where['bindings'] as $binding) {
                    $params[] = $binding;
                }
                continue;
            }

            // A name is quoted; an expression (Database::json(), raw() without bindings) stands as it is.
            // A condition declares no alias: "has as col" is the name of one column, as in orderBy()
            $column = $where['column'] instanceof RawExpression ? (string) $where['column'] : $this->quoteReference($where['column']);

            switch ($where['type']) {
                case 'basic':
                    $operator = $where['operator'];
                    $right = Sql::value($where['value'], $params);
                    $clause = $this->comparison($column, $operator, $right, $where['value'] instanceof RawExpression);
                    if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
                        $clause .= ' ESCAPE ?';
                        $params[] = self::LIKE_ESCAPE;
                    }
                    $clauses[] = $clause;
                    break;

                case 'in':
                    // array_values(): string keys would be renumbered by the later merge and could shadow each other
                    $slots = [];
                    foreach (array_values($where['values']) as $item) {
                        $slots[] = Sql::value($item, $params);
                    }
                    $inOperator = $where['not'] ? 'NOT IN' : 'IN';
                    $clauses[] = $column . " {$inOperator} (" . implode(', ', $slots) . ')';
                    break;

                case 'between':
                    $betweenOperator = $where['not'] ? 'NOT BETWEEN' : 'BETWEEN';
                    // array_values(): ['min' => 1, 'max' => 2] must not silently become NULL AND NULL
                    $betweenValues = array_values($where['values']);
                    $bounds = [];
                    foreach ([$betweenValues[0] ?? null, $betweenValues[1] ?? null] as $bound) {
                        $bounds[] = Sql::value($bound, $params);
                    }
                    $clauses[] = $column . " {$betweenOperator} {$bounds[0]} AND {$bounds[1]}";
                    break;

                case 'null':
                    $nullOperator = $where['not'] ? 'IS NOT NULL' : 'IS NULL';
                    $clauses[] = $column . ' ' . $nullOperator;
                    break;
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Guard against a raw expression with bindings where no value stands: its bindings would have
     * to be placed in front of every other value of the statement, which this builder does not do.
     *
     * @param array<array-key, mixed> $entries
     *
     * @throws QueryException When an entry is a RawExpression with bindings
     */
    private function guardAgainstBoundRaw(string $method, #[\SensitiveParameter] array $entries): void
    {
        foreach ($entries as $entry) {
            if ($entry instanceof RawExpression && $entry->bindings !== []) {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: sprintf(
                        'A raw expression with bindings is only accepted as a value (insert()/update() data, where(), whereIn(), whereBetween(), the value of having()), not in %s(). Use whereRaw() for a condition, or query() for the whole statement.',
                        in_array($method, ['select', 'orderBy', 'groupBy'], true) ? $method : 'the column of ' . $method
                    )
                );
            }
        }
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
            // RawExpression bypasses quoting (for aggregates). A string is a name; one with an expression in it
            // ("COUNT(*)") names an output column only where a select() entry carries that name: otherwise it
            // would reach the server as an unknown column
            if (is_string($h['column']) && str_contains($h['column'], '(') && !$this->selectsTheName($h['column'])) {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: sprintf(
                        'having() takes a column or an alias as a string, and "%s" is an expression no select() entry is named after: it would be quoted as the name of a column. Select Database::raw(\'%s\'), pass it as Database::raw(), or select it with an alias and name that.',
                        $h['column'],
                        $h['column']
                    )
                );
            }
            $column = $h['column'] instanceof RawExpression
                ? (string) $h['column']
                : $this->quoteIdentifier($h['column']);
            $clause = $this->comparison($column, $h['operator'], Sql::value($h['value'], $params), $h['value'] instanceof RawExpression);
            if ($h['operator'] === 'LIKE' || $h['operator'] === 'NOT LIKE') {
                $clause .= ' ESCAPE ?';
                $params[] = self::LIKE_ESCAPE;
            }
            $clauses[] = $clause;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Quote an identifier (table/column name).
     *
     * Handles simple, dotted (table.column), and alias (column as alias) formats.
     * Escapes the quote character within identifiers to prevent SQL injection.
     *
     * The alias is quoted like every other name: it is then the same name wherever the builder
     * refers to it (orderBy(), groupBy(), a column of an aliased table - all rendered quoted), a
     * reserved word is a valid alias, and the result key is the alias as written. The alias is a
     * word of letters (any script), digits and underscores at the very end: a trailing newline is no
     * part of the pattern's end (D), "col as ä" is an alias (u) - anything else is one quoted name.
     */
    private function quoteIdentifier(string $identifier): string
    {
        // Handle alias: "column as alias" or "table.column as alias"
        if (preg_match('/^(.+)\s+as\s+(\w+)$/iuD', $identifier, $matches)) {
            return $this->quoteReference(trim($matches[1])) . ' as ' . Sql::name($matches[2]);
        }

        return $this->quoteReference($identifier);
    }

    /**
     * Quote a column reference without an alias ("col", "table.col", "table.*"): what orderBy()
     * takes - there "title as x" is the name of one column, not an alias declaration. A backtick in
     * a name is doubled, "users.*" keeps its wildcard: `users`.* (see Sql::name()).
     */
    private function quoteReference(string $identifier): string
    {
        return Sql::name($identifier, wildcard: true);
    }

    /**
     * The keys of the columns to set (update(), the $extra of increment()/decrement()): the plain
     * name of one column each, as in the CRUD methods - no integer key, no dot (a qualified name
     * would be taken apart and name the column after it, a second spelling a deny-list misses).
     *
     * @param array<array-key, mixed> $pairs
     *
     * @throws QueryException When a key is an integer or holds a dot
     */
    private function guardTheColumnsToSet(#[\SensitiveParameter] array $pairs, string $what): void
    {
        $this->guardAgainstNumericKeys($pairs, $what, 'Pass column => value pairs.');
        foreach (array_keys($pairs) as $key) {
            if (str_contains((string) $key, '.')) {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: sprintf('%s needs the plain names of columns as keys, got "%s": a qualified name (table.column) would be taken apart at its dot and name the column after it. Pass the column\'s own name.', $what, $key)
                );
            }
        }
    }

    /**
     * Guard against integer keys in a column => value array - where(['column' => $value]),
     * update(), the $extra of increment()/decrement(): a list (['active', 1]), or a numeric column
     * name, whose key PHP turns into an integer whatever the declared type says. Without the guard
     * the integer would reach the quoting, a TypeError instead of a QueryException.
     *
     * @param array<array-key, mixed> $pairs
     *
     * @throws QueryException When a key is an integer
     */
    private function guardAgainstNumericKeys(#[\SensitiveParameter] array $pairs, string $what, string $hint): void
    {
        foreach (array_keys($pairs) as $key) {
            if (is_int($key)) {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: sprintf('%s needs column names as keys, got the numeric key %d. %s', $what, $key, $hint)
                );
            }
        }
    }

    /**
     * Guard against a null element in whereIn()/whereNotIn(): IN never matches NULL, and NOT IN
     * with a NULL in the list matches no row at all.
     *
     * @param array<array-key, mixed> $values
     *
     * @throws QueryException When an element is null
     */
    private function guardAgainstNullElement(string $method, #[\SensitiveParameter] string|RawExpression $column, #[\SensitiveParameter] array $values): void
    {
        foreach ($values as $value) {
            if ($value === null) {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: sprintf(
                        'Cannot use a null element in %s() for column "%s". Add whereNull() or whereNotNull() for it.',
                        $method,
                        $column
                    )
                );
            }
        }
    }

    /**
     * Guard against a null bound in whereBetween()/whereNotBetween(): BETWEEN with NULL is never
     * true, so the condition would silently match no row (and NOT BETWEEN none either).
     *
     * @param array<array-key, mixed> $values
     *
     * @throws QueryException When a bound is null
     */
    private function guardAgainstNullBound(string $method, #[\SensitiveParameter] string|RawExpression $column, #[\SensitiveParameter] array $values): void
    {
        foreach ($values as $value) {
            if ($value === null) {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: sprintf(
                        'Cannot use a null bound in %s() for column "%s". Use where() with a comparison operator for an open range.',
                        $method,
                        $column
                    )
                );
            }
        }
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
     * The statements render ORDER BY ... LIMIT: limit() is allowed with an orderBy() - without one,
     * which rows the statement hits would be up to the server, and it throws.
     *
     * @param string $operation Operation name for error message ('update' or 'delete')
     *
     * @throws QueryException When offset, join, groupBy or having is set, orderBy without limit, or limit without orderBy
     */
    private function guardAgainstSelectClauses(string $operation): void
    {
        if ($this->limit !== null && empty($this->orderBy)) {
            throw new QueryException(
                message: ucfirst($operation) . ' failed',
                debugMessage: sprintf(
                    '%s() with limit() needs an orderBy(): without one, which rows it hits would be up to the server. Order by a unique key, or add one as tie-breaker.',
                    $operation
                )
            );
        }

        $unsupported = [];

        if ($this->offset !== null) {
            $unsupported[] = 'offset()';
        }
        if (!empty($this->orderBy) && $this->limit === null) {
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
