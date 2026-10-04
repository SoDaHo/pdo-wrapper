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
    /** @var list<array{string, ?string, ?string, array<mixed>|null}> DSN, user name, password and options, per construction */
    private static array $given = [];

    public static function forget(): void
    {
        self::$given = [];
    }

    /**
     * What the constructions so far were given.
     *
     * @return list<array{string, ?string, ?string, array<mixed>|null}>
     *
     * @phpstan-impure
     */
    public static function given(): array
    {
        return self::$given;
    }

    /**
     * @param array<mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null)
    {
        self::$given[] = [$dsn, $username, $password, $options];
        parent::__construct('sqlite::memory:'); // the options are a server's: kept, not applied
    }
}
