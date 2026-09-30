<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper;

use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Query\RawExpression;

/**
 * Factory class for creating database connections.
 *
 * Configuration priority: $config array > $_ENV > getenv()
 *
 * Usage:
 * - Database::connect(['driver' => 'mysql', 'host' => '...', ...]) or with DB_DRIVER set
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
     * Create a connection for the driver named in the config or in DB_DRIVER.
     *
     * 'mysql' (also 'mariadb'), 'pgsql' (also 'postgres', 'postgresql') and 'sqlite' delegate to
     * mysql(), postgres() and sqlite() with the same config keys and environment fallbacks; the
     * SQLite path comes from 'path', else 'database', else DB_SQLITE_PATH. One config array for
     * every environment: the driver decides which of them is used.
     *
     * @param array{driver?: string, path?: string, host?: string, database?: string, username?: string, password?: string, port?: int, charset?: string, options?: array<int, mixed>} $config
     *
     * @throws ConnectionException When no or an unknown driver is named, or the connection fails
     */
    public static function connect(array $config = []): DatabaseInterface
    {
        $driver = strtolower(trim($config['driver'] ?? self::env('DB_DRIVER') ?? ''));

        return match ($driver) {
            'mysql', 'mariadb' => self::mysql($config),
            'pgsql', 'postgres', 'postgresql' => self::postgres($config),
            'sqlite' => self::sqlite($config['path'] ?? $config['database'] ?? null),
            default => throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: $driver === ''
                    ? 'No database driver given: set $config[\'driver\'] or DB_DRIVER to mysql, pgsql or sqlite'
                    : sprintf('Unknown database driver "%s": use mysql, pgsql or sqlite', $driver)
            ),
        };
    }

    /**
     * Create a raw SQL expression that will not be quoted.
     *
     * Use this for aggregate functions, complex expressions, or any SQL
     * that should be passed through without identifier quoting. As a value in
     * insert()/update()/where()/having() the expression is inlined instead of bound.
     *
     * SECURITY WARNING: Never pass untrusted user input to this method.
     * This bypasses SQL injection protection for identifiers and, as a value,
     * the parameter binding.
     *
     * @param string $value The raw SQL string
     *
     * @example
     * $db->table('users')->select([Database::raw('COUNT(*) as total')])->get();
     * $db->table('orders')->select([Database::raw('SUM(amount) as revenue')])->get();
     * $db->update('counters', ['hits' => Database::raw('hits + 1')], ['id' => $id]);
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
