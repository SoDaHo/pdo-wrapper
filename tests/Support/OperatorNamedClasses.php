<?php

declare(strict_types=1);

// Classes of the global namespace named like operators of where(): get_debug_type() of an instance
// reads "Like" and "Is", which the eighth candidate of 3.2.0 took for the operators LIKE and IS
// (Astra review). Loaded with require_once by the tests that need them - no namespace, so no autoload.

/** A class named like the operator LIKE, whose string is the operator "=" */
final class Like
{
    public function __toString(): string
    {
        return '=';
    }
}

/** A class named like the operator IS, without a string */
final class Is
{
}
