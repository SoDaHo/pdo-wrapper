<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Query;

/**
 * Represents a raw SQL expression that should not be quoted.
 *
 * Use this for aggregate functions, complex expressions, or any SQL
 * that should be passed through without identifier quoting.
 *
 * SECURITY WARNING: Never pass untrusted user input to RawExpression.
 * This bypasses all SQL injection protection for identifiers.
 *
 * @example
 * Database::raw('COUNT(*) as total')
 * Database::raw('YEAR(created_at)')
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
