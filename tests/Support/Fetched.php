<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Values as the drivers deliver them, in the type a test compares.
 */
final class Fetched
{
    /**
     * A number as an integer, where a test does not pin the PHP type the value arrives as (an
     * aggregate column, a count read through a raw query): an integer or a numeric string.
     * Anything that is no number fails the test instead of being cast into one. Where the type is
     * the library's promise (an `int` column of the binding), tests compare with assertSame().
     */
    public static function int(mixed $value): int
    {
        Assert::assertIsNumeric($value);

        return (int) $value;
    }
}
