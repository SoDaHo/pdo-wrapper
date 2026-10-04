<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use PDO;

/**
 * A PDO class that keeps what it was given and connects to the test database whatever that was:
 * for the tests of what a driver hands its PDO class, with credentials no server knows.
 */
final class RecordingPdo extends PDO
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
        $test = TestEnvironment::mariadb();
        parent::__construct(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $test['host'], $test['port'], $test['database']),
            $test['username'],
            $test['password'],
            $options
        );
    }
}
