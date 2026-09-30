<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Query;

/**
 * Represents a raw SQL expression that should not be quoted.
 *
 * Use this for aggregate functions, complex expressions, or any SQL
 * that should be passed through without identifier quoting. As a value in
 * insert()/update()/where()/whereIn()/whereBetween()/having() it is inlined
 * into the SQL instead of being bound as a parameter.
 *
 * SECURITY WARNING: Never pass untrusted user input to RawExpression.
 * This bypasses identifier quoting and, as a value, parameter binding:
 * the string goes into the SQL verbatim.
 *
 * @example
 * Database::raw('COUNT(*) as total')
 * Database::raw('YEAR(created_at)')
 * $db->update('counters', ['hits' => Database::raw('hits + 1')], ['id' => $id])
 */
class RawExpression
{
    public function __construct(
        public readonly string $value
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
