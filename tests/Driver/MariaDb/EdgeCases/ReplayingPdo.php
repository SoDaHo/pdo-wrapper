<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb\EdgeCases;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDO class (pdoClass) that replays a server's answer: prepare() throws the failure a test
 * wants the server to have reported, and the connection reports the server version the test
 * names - 'unreadable' throws, 'not a string' returns null. Everything else is the real
 * connection. Reset the static state between tests.
 */
final class ReplayingPdo extends PDO
{
    public static ?PDOException $failure = null;

    /** What the connection reports as PDO::ATTR_SERVER_VERSION */
    public static string $serverVersion = '';

    public static function reset(): void
    {
        self::$failure = null;
        self::$serverVersion = '';
    }

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw self::$failure ?? new PDOException('unset');
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute !== PDO::ATTR_SERVER_VERSION) {
            return parent::getAttribute($attribute);
        }

        return match (self::$serverVersion) {
            'unreadable' => throw new PDOException('server version unreadable'),
            'not a string' => null,
            default => self::$serverVersion,
        };
    }
}
