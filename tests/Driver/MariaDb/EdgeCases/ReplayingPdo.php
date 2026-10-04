<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb\EdgeCases;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDO class (pdoClass) that replays a server's answer: prepare() throws the failure a test
 * wants the server to have reported. Everything else is the real connection. Reset the static
 * state between tests.
 */
final class ReplayingPdo extends PDO
{
    public static ?PDOException $failure = null;

    public static function reset(): void
    {
        self::$failure = null;
    }

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw self::$failure ?? new PDOException('unset');
    }
}
