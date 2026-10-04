<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\EdgeCases;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDO class (pdoClass) whose prepare() throws a PDOException that carries no errorInfo: what
 * PDO's own argument errors and a PDO subclass of someone else's throw.
 */
final class PrepareFailingPdo extends PDO
{
    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new PDOException('no errorInfo on this one');
    }
}
