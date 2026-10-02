<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use ReflectionClass;

/**
 * Calls with arguments the signature does not allow, as code without static analysis makes
 * them: for the tests of what the library does at run time then.
 */
final class Untyped
{
    public static function call(callable $callable, mixed ...$arguments): mixed
    {
        return $callable(...$arguments);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function create(string $class, mixed ...$arguments): object
    {
        return (new ReflectionClass($class))->newInstance(...$arguments);
    }
}
