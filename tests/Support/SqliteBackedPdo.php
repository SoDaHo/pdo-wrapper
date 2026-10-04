<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use PDO;

/**
 * A PDO class that connects to an in-memory SQLite database whatever it is given, and keeps what
 * it was given: for the tests of what a server driver hands its PDO class, without a server.
 */
final class SqliteBackedPdo extends PDO
{
    /** @var list<array{string, ?string, ?string}> DSN, user name and password, per construction */
    public static array $given = [];

    /**
     * @param array<int, mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null)
    {
        self::$given[] = [$dsn, $username, $password];
        parent::__construct('sqlite::memory:', null, null, $options);
    }
}
