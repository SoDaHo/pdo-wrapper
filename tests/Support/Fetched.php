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
     * A number as an integer: the drivers deliver the same number as an integer or as a numeric
     * string. Anything that is no number fails the test instead of being cast into one.
     */
    public static function int(mixed $value): int
    {
        Assert::assertIsNumeric($value);

        return (int) $value;
    }
}
