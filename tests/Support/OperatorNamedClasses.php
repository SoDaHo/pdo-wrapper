<?php

declare(strict_types=1);

// A class of the global namespace named like an operator of where(): get_debug_type() of an
// instance reads "Like", which a check that read the type's name as the operator took for LIKE.
// Loaded with require_once by the tests that need it - no namespace, so no autoload. There is no
// class "Is" next to it: PHP 8.6 deprecates "is" as a class name, and the suite fails on deprecations.

/** A class named like the operator LIKE, whose string is the operator "=" */
final class Like
{
    public function __toString(): string
    {
        return '=';
    }
}
