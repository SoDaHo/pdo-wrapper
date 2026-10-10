<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * The statements of InsertIdEchoPdo: each keeps the first value it is executed with as the ID that
 * PDO class reports next (a value that is no scalar keeps none).
 */
final class InsertIdEchoStatement extends PDOStatement
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        $first = $params === null ? null : (array_values($params)[0] ?? null);
        InsertIdEchoPdo::$echoed = is_scalar($first) ? (string) $first : null;

        return parent::execute($params);
    }
}
