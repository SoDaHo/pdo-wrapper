<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use UnexpectedValueException;

/**
 * Connection settings of the test databases, read from the MYSQL_* and POSTGRES_* variables
 * (phpunit.xml.dist holds the defaults). A variable that is set to something unusable fails the
 * test instead of being cast into a value nobody asked for.
 */
final class TestEnvironment
{
    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public static function mysql(): array
    {
        return [
            'host' => self::text('MYSQL_HOST', '127.0.0.1'),
            'port' => self::port('MYSQL_PORT', 3306),
            'database' => self::text('MYSQL_DATABASE', 'pdo_wrapper_test'),
            'username' => self::text('MYSQL_USERNAME', 'root'),
            'password' => self::text('MYSQL_PASSWORD', 'root'),
        ];
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public static function postgres(): array
    {
        return [
            'host' => self::text('POSTGRES_HOST', '127.0.0.1'),
            'port' => self::port('POSTGRES_PORT', 5432),
            'database' => self::text('POSTGRES_DATABASE', 'pdo_wrapper_test'),
            'username' => self::text('POSTGRES_USERNAME', 'postgres'),
            'password' => self::text('POSTGRES_PASSWORD', 'postgres'),
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
