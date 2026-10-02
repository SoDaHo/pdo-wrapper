<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use PDOException;
use Throwable;

/**
 * For test cases that look at what PDO recorded for the failure behind a library exception.
 */
trait ReadsPdoErrorInfo
{
    /**
     * Entry $index of the errorInfo of the PDOException behind $e (0: SQLSTATE, 1: driver code,
     * 2: driver message); null when PDO recorded none. Fails when no PDOException is behind $e.
     */
    private function errorInfoBehind(Throwable $e, int $index): mixed
    {
        $previous = $e->getPrevious();
        $this->assertInstanceOf(PDOException::class, $previous);

        return $previous->errorInfo[$index] ?? null;
    }
}
