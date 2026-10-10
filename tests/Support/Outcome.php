<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use Closure;
use Sodaho\PdoWrapper\Exception\CommitFailedException;

/**
 * Writes an outcome into a CommitFailedException the way the driver does - through a closure bound
 * to the class, its settle() being private: for tests that need an exception with an outcome.
 */
final class Outcome
{
    /**
     * @param 'rolled_back'|'lost' $outcome
     */
    public static function settle(CommitFailedException $failure, string $outcome): void
    {
        $settle = Closure::bind(static function (CommitFailedException $failure) use ($outcome): void {
            $failure->settle($outcome);
        }, null, CommitFailedException::class);
        $settle($failure);
    }
}
