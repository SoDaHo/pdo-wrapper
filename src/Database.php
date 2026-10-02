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
 * Configuration priority: $config array > $_ENV > getenv(). An environment variable that is set
 * but empty counts as not set (a required value is then reported as missing); an empty
 * DB_SQLITE_PATH throws.
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
     * @param array{host?: string, database?: string, username?: string, password?: string, port?: int|string, charset?: string, options?: array<int, mixed>} $config
     *
     * @throws Exception\ConnectionException When connection fails
     */
    public static function mysql(#[\SensitiveParameter] array $config = []): MySqlDriver
    {
        $envPort = trim((string) self::env('DB_PORT'));

        $mergedConfig = [
            'host' => $config['host'] ?? self::env('DB_HOST'),
            'database' => $config['database'] ?? self::env('DB_DATABASE'),
            'username' => $config['username'] ?? self::env('DB_USERNAME'),
            'password' => $config['password'] ?? self::env('DB_PASSWORD'),
            'port' => $config['port'] ?? ($envPort !== '' ? $envPort : 3306),
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
     * @param array{host?: string, database?: string, username?: string, password?: string, port?: int|string, options?: array<int, mixed>} $config
     *
     * @throws Exception\ConnectionException When connection fails
     */
    public static function postgres(#[\SensitiveParameter] array $config = []): PostgresDriver
    {
        $envPort = trim((string) self::env('DB_PORT'));

        $mergedConfig = [
            'host' => $config['host'] ?? self::env('DB_HOST'),
            'database' => $config['database'] ?? self::env('DB_DATABASE'),
            'username' => $config['username'] ?? self::env('DB_USERNAME'),
            'password' => $config['password'] ?? self::env('DB_PASSWORD'),
            'port' => $config['port'] ?? ($envPort !== '' ? $envPort : 5432),
            'options' => $config['options'] ?? [],
        ];

        return new PostgresDriver($mergedConfig);
    }

    /**
     * Create a SQLite database connection.
     *
     * Falls back to DB_SQLITE_PATH environment variable if path is null.
     * Defaults to ':memory:' if neither is set. A DB_SQLITE_PATH that is set but empty (or not a
     * scalar) is not "not set": the default would be a database that forgets everything, so it
     * throws.
     *
     * @param string|null $path Path to SQLite file, ':memory:' for in-memory, or null for default
     *
     * @throws Exception\ConnectionException When the path is an empty string, DB_SQLITE_PATH is set but empty or not a scalar, or the connection fails
     */
    public static function sqlite(?string $path = null): SqliteDriver
    {
        if ($path === null) {
            $path = self::env('DB_SQLITE_PATH', keepEmpty: true) ?? ':memory:';
            if ($path === '') {
                throw new ConnectionException(
                    message: 'Database connection failed',
                    debugMessage: 'DB_SQLITE_PATH is set but empty or not a scalar: unset it for the default in-memory database, or set it to ":memory:" or the path of a file'
                );
            }
        }

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
     * @param array{driver?: string, path?: string, host?: string, database?: string, username?: string, password?: string, port?: int|string, charset?: string, options?: array<int, mixed>} $config
     *
     * @throws ConnectionException When no or an unknown driver is named, or the connection fails
     */
    public static function connect(#[\SensitiveParameter] array $config = []): DatabaseInterface
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
     * An expression used as a value may carry values of its own: ? placeholders in the SQL,
     * their values in $bindings. They are bound exactly where the expression stands among the
     * statement's other values (in an update: in the order of the SET list, before the WHERE
     * values). select(), groupBy() and the column of having() refuse an expression with bindings.
     *
     * SECURITY WARNING: Never pass untrusted user input as $value.
     * This bypasses SQL injection protection for identifiers and, as a value,
     * the parameter binding. User input belongs in $bindings.
     *
     * @param string $value The raw SQL string
     * @param array<array-key, mixed> $bindings Values for the ? placeholders in $value, in order
     *
     * @throws Exception\QueryException When a binding is itself a RawExpression
     *
     * @example
     * $db->table('users')->select([Database::raw('COUNT(*) as total')])->get();
     * $db->table('orders')->select([Database::raw('SUM(amount) as revenue')])->get();
     * $db->update('counters', ['hits' => Database::raw('hits + 1')], ['id' => $id]);
     * $db->update('jobs', ['run_at' => Database::raw('run_at + ?', [$delay])], ['id' => $id]);
     */
    public static function raw(string $value, array $bindings = []): RawExpression
    {
        return new RawExpression($value, $bindings);
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
     * A variable that is set but empty counts as not set (`DB_HOST=` in a dotenv template): the
     * connection then fails for a missing value instead of being opened with an empty one. A
     * value in $_ENV that is no scalar (an array) is no usable value and counts as empty. The
     * first channel that has a value for the key decides; null in $_ENV is no value, as before.
     *
     * @param string $key Environment variable name
     * @param bool $keepEmpty Return an empty or unusable value as '' instead of null, for a caller that tells that from "not set"
     *
     * @return string|null Value or null if not set (or empty, unless $keepEmpty)
     */
    private static function env(string $key, bool $keepEmpty = false): ?string
    {
        // $_ENV is thread-safe, preferred
        if (isset($_ENV[$key])) {
            $value = is_scalar($_ENV[$key]) ? (string) $_ENV[$key] : '';
        } else {
            // getenv() fallback for legacy compatibility
            $value = getenv($key);
            if ($value === false) {
                return null;
            }
        }

        return $value === '' && !$keepEmpty ? null : $value;
    }
}
