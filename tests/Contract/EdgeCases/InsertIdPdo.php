<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\EdgeCases;

use PDO;
use PDOException;

/**
 * A PDO class (pdoClass) whose lastInsertId() fails on demand, the way PDO reports it: by
 * returning false (non-exception error mode), or by throwing. Counts every read, failed or not.
 * Reset the static state between tests.
 */
final class InsertIdPdo extends PDO
{
    /** lastInsertId() returns false, as long as this is set */
    public static bool $returnsFalse = false;

    /** How many of the next reads throw */
    public static int $throwingReads = 0;

    /** Thrown by the next read instead of PDO's own exception: someone else's (an extension's, a proxy's) */
    public static ?\Throwable $foreign = null;

    /** How often lastInsertId() was called */
    public static int $reads = 0;

    public static function reset(): void
    {
        self::$returnsFalse = false;
        self::$throwingReads = 0;
        self::$foreign = null;
        self::$reads = 0;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        self::$reads++;
        if (self::$returnsFalse) {
            return false;
        }
        if (self::$foreign !== null) {
            $foreign = self::$foreign;
            self::$foreign = null;

            throw $foreign;
        }
        if (self::$throwingReads > 0) {
            self::$throwingReads--;

            throw new PDOException('lastInsertId failed (fixture)');
        }

        return parent::lastInsertId($name);
    }
}
