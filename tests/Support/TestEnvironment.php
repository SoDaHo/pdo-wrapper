<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use UnexpectedValueException;

/**
 * Connection settings of the test database, read from the MARIADB_* variables
 * (phpunit.xml.dist holds the defaults). A variable that is set to something unusable fails the
 * test instead of being cast into a value nobody asked for.
 */
final class TestEnvironment
{
    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public static function mariadb(): array
    {
        return [
            'host' => self::text('MARIADB_HOST', '127.0.0.1'),
            'port' => self::port('MARIADB_PORT', 3306),
            'database' => self::text('MARIADB_DATABASE', 'pdo_wrapper_test'),
            'username' => self::text('MARIADB_USERNAME', 'root'),
            'password' => self::text('MARIADB_PASSWORD', 'root'),
        ];
    }

    private static function text(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $default;
        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('%s must be a string, %s given', $key, get_debug_type($value)));
        }

        return $value;
    }

    private static function port(string $key, int $default): int
    {
        $value = $_ENV[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException(sprintf('%s must be a port number, %s given', $key, get_debug_type($value)));
    }
}
