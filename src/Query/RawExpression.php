<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Query;

use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * Represents a raw SQL expression that should not be quoted.
 *
 * Use this for aggregate functions, complex expressions, or any SQL
 * that should be passed through without identifier quoting. As a value in
 * insert()/update()/where()/whereIn()/whereBetween()/having() it is inlined
 * into the SQL instead of being bound as a parameter.
 *
 * An expression may carry values of its own: ? placeholders in the SQL and their values in
 * $bindings, in order. They are bound exactly where the expression stands among the statement's
 * other values. Only an expression used as a value may carry them; select(), groupBy(),
 * orderBy(), the column of having() and of the where*() methods and RETURNING refuse one that does.
 *
 * SECURITY WARNING: Never pass untrusted user input to RawExpression.
 * This bypasses identifier quoting and, as a value, parameter binding:
 * the string goes into the SQL verbatim. User input belongs in $bindings.
 *
 * @example
 * Database::raw('COUNT(*) as total')
 * Database::raw('YEAR(created_at)')
 * $db->update('counters', ['hits' => Database::raw('hits + 1')], ['id' => $id])
 * $db->update('jobs', ['run_at' => Database::raw('run_at + ?', [$delay])], ['id' => $id])
 */
class RawExpression
{
    /** @var list<mixed> Values for the ? placeholders in $value, in order */
    public readonly array $bindings;

    /**
     * @param array<array-key, mixed> $bindings Values for the ? placeholders in $value, in order
     *
     * @throws QueryException When a binding is itself a RawExpression: write it into the SQL
     */
    public function __construct(
        public readonly string $value,
        array $bindings = []
    ) {
        foreach ($bindings as $binding) {
            if ($binding instanceof self) {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: 'A raw expression binds its values; write a raw expression into the SQL instead of binding it'
                );
            }
        }

        $this->bindings = array_values($bindings);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
