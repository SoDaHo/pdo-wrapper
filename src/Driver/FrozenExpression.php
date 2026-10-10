<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\RawExpression;

/**
 * A raw expression of the rows updateMultiple() checked and sends: the caller's expression with
 * its bindings as they were when the batch was checked - copied, no reference of the caller's
 * among them, so that a listener that changes such a reference while the batch runs changes
 * nothing the batch sends. Its SQL is the caller's expression's own, rendered by it once, by the
 * UPDATE that sends it (an expression of a class of its own may do more in __toString() than
 * return its text).
 *
 * @internal Built by AbstractDriver::updateMultiple() only
 */
final class FrozenExpression extends RawExpression
{
    private readonly RawExpression $expression;

    /**
     * @param list<mixed> $bindings The copied bindings
     *
     * @throws QueryException When a binding is a raw expression
     */
    private function __construct(#[\SensitiveParameter] RawExpression $expression, #[\SensitiveParameter] array $bindings)
    {
        parent::__construct($expression->value, $bindings);
        $this->expression = $expression;
    }

    /**
     * The expression with a copy of its bindings, each read by value: a binding the caller holds a
     * reference to (`Database::raw('?', [&$value])`) is the value it has now, no longer the reference.
     *
     * @throws QueryException When a binding is a raw expression now (put there through such a reference)
     */
    public static function of(#[\SensitiveParameter] RawExpression $expression): self
    {
        $bindings = [];
        foreach ($expression->bindings as $binding) {
            $bindings[] = $binding;
        }

        return new self($expression, $bindings);
    }

    public function __toString(): string
    {
        return (string) $this->expression;
    }
}
