<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;
use PDOException;
use RuntimeException;

/**
 * A connection on which the driver's first statement - the one that sets completion_type - does
 * not go through, as $mode says: 'throws' (a PDOException), 'fails silently' (the server refuses
 * a value it is sent instead, in a non-exception error mode), 'returns false' (without an error
 * PDO could name), 'foreign' (a PDO class of the caller's that throws something else).
 */
final class PinRefusingPdo extends PDO
{
    public static string $mode = '';

    public function exec(string $statement): int|false
    {
        if (!str_contains($statement, 'completion_type')) {
            return parent::exec($statement);
        }

        return match (self::$mode) {
            'throws' => throw new PDOException('completion_type refused (scenario)'),
            'fails silently' => parent::exec('SET SESSION completion_type = 42'),
            'returns false' => false,
            'foreign' => throw new RuntimeException('a foreign failure (scenario)'),
            default => parent::exec($statement),
        };
    }
}
