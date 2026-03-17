<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper;

use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Query\RawExpression;

/**
 * Factory class for creating database connections.
 *
 * Configuration priority: $config array > $_ENV > getenv()
 *
 * Usage:
 * - Database::mysql(['host' => '...', 'database' => '...', ...])
 * - Database::postgres(['host' => '...', 'database' => '...', ...])
 * - Database::sqlite(':memory:')
 */
class Database
{
    /**
     * Create a MySQL database connection.
     *
     * Falls back to environment variables if config values are not provided:
     * DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_PORT
     *
     * @param array{host?: string, database?: string, username?: string, password?: string, port?: int, charset?: string, options?: array<int, mixed>} $config
     *
     * @throws Exception\ConnectionException When connection fails
     */
    public static function mysql(array $config = []): MySqlDriver
    {
        $envPort = self::env('DB_PORT');

        $mergedConfig = [
            'host' => $config['host'] ?? self::env('DB_HOST'),
            'database' => $config['database'] ?? self::env('DB_DATABASE'),
            'username' => $config['username'] ?? self::env('DB_USERNAME'),
            'password' => $config['password'] ?? self::env('DB_PASSWORD'),
            'port' => $config['port'] ?? (is_numeric($envPort) ? (int)$envPort : 3306),
            'charset' => $config['charset'] ?? 'utf8mb4',
            'options' => $config['options'] ?? [],
        ];

        return new MySqlDriver($mergedConfig);
    }

    /**
     * Create a PostgreSQL database connection.
     *
     * Falls back to environment variables if config values are not provided:
     * DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_PORT
     *
     * @param array{host?: string, database?: string, username?: string, password?: string, port?: int, options?: array<int, mixed>} $config
     *
     * @throws Exception\ConnectionException When connection fails
     */
    public static function postgres(array $config = []): PostgresDriver
    {
        $envPort = self::env('DB_PORT');

        $mergedConfig = [
            'host' => $config['host'] ?? self::env('DB_HOST'),
            'database' => $config['database'] ?? self::env('DB_DATABASE'),
            'username' => $config['username'] ?? self::env('DB_USERNAME'),
            'password' => $config['password'] ?? self::env('DB_PASSWORD'),
            'port' => $config['port'] ?? (is_numeric($envPort) ? (int)$envPort : 5432),
            'options' => $config['options'] ?? [],
        ];

        return new PostgresDriver($mergedConfig);
    }

    /**
     * Create a SQLite database connection.
     *
     * Falls back to DB_SQLITE_PATH environment variable if path is null.
     * Defaults to ':memory:' if neither is set.
     *
     * @param string|null $path Path to SQLite file, ':memory:' for in-memory, or null for default
     *
     * @throws Exception\ConnectionException When connection fails
     */
    public static function sqlite(?string $path = null): SqliteDriver
    {
        $path ??= self::env('DB_SQLITE_PATH') ?? ':memory:';

        return new SqliteDriver($path);
    }

    /**
     * Create a raw SQL expression that will not be quoted.
     *
     * Use this for aggregate functions, complex expressions, or any SQL
     * that should be passed through without identifier quoting.
     *
     * SECURITY WARNING: Never pass untrusted user input to this method.
     * This bypasses SQL injection protection for identifiers.
     *
     * @param string $value The raw SQL string
     *
     * @example
     * $db->table('users')->select([Database::raw('COUNT(*) as total')])->get();
     * $db->table('orders')->select([Database::raw('SUM(amount) as revenue')])->get();
     */
    public static function raw(string $value): RawExpression
    {
        return new RawExpression($value);
    }

    /**
     * Escape LIKE wildcard characters in a value.
     *
     * Use this to safely include user input in LIKE patterns.
     * Escapes %, _ and \ so they are treated as literal characters.
     *
     * @param string $value The value to escape
     *
     * @return string Escaped value safe for use in LIKE patterns
     *
     * @example
     * $safe = Database::escapeLike($userInput); // "100%" → "100\%"
     * $db->table('users')->whereLike('name', '%' . $safe . '%')->get();
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }

    /**
     * Get environment variable value.
     *
     * Priority: $_ENV > getenv()
     * This ensures thread-safety when using $_ENV while maintaining
     * compatibility with legacy code that uses putenv/getenv.
     *
     * @param string $key Environment variable name
     *
     * @return string|null Value or null if not set
     */
    private static function env(string $key): ?string
    {
        // $_ENV is thread-safe, preferred
        if (isset($_ENV[$key])) {
            return (string)$_ENV[$key];
        }

        // getenv() fallback for legacy compatibility
        $value = getenv($key);

        return $value !== false ? $value : null;
    }
}
