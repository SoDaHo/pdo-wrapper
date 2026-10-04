<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use PDO;
use PDOStatement;

/**
 * A PDO class (pdoClass) that fails every prepare() by returning false without recording
 * anything: errorInfo() says '00000', "no error", as PDO can report it in a non-exception error
 * mode.
 */
final class UnrecordedFailurePdo extends PDO
{
    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return false;
    }

    /**
     * @return array<int, mixed>
     */
    public function errorInfo(): array
    {
        return ['00000', null, null];
    }
}
