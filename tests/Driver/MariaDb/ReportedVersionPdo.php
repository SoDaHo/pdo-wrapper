<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;

/**
 * A PDO class (pdoClass) on the test database that reports the server and client versions a test
 * names, as the handshake of another server or a PHP build on another client library would, and
 * the ATTR_ORACLE_NULLS mode a test names. Null reports the real one. Reset the static state
 * between tests.
 */
final class ReportedVersionPdo extends PDO
{
    public static mixed $server = null;

    public static mixed $client = null;

    public static mixed $nulls = null;

    public static function reset(): void
    {
        self::$server = null;
        self::$client = null;
        self::$nulls = null;
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_SERVER_VERSION => self::$server ?? parent::getAttribute($attribute),
            PDO::ATTR_CLIENT_VERSION => self::$client ?? parent::getAttribute($attribute),
            PDO::ATTR_ORACLE_NULLS => self::$nulls ?? parent::getAttribute($attribute),
            default => parent::getAttribute($attribute),
        };
    }
}
