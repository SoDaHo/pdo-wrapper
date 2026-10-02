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
 * mysql(), postgres(), sqlite() and connect() use what they are given and nothing else: they
 * never read the environment. fromEnv() is the one entry that does - DB_DRIVER, DB_HOST, DB_PORT,
 * DB_DATABASE, DB_USERNAME, DB_PASSWORD and DB_SQLITE_PATH, from $_ENV first, then getenv().
 *
 * Usage:
 * - Database::mysql(['host' => '...', 'database' => '...', 'username' => '...', ...])
 * - Database::postgres(['host' => '...', 'database' => '...', 'username' => '...', ...])
 * - Database::sqlite('/path/to/database.db') or Database::sqlite() for an in-memory database
 * - Database::connect(['driver' => 'mysql', 'host' => '...', ...])
 * - Database::fromEnv() or Database::fromEnv(['password' => $secret])
 */
class Database
{
    /**
     * Create a MySQL/MariaDB connection from the given config (see MySqlDriver for the keys).
     * Nothing is read from the environment: use fromEnv() for that.
     *
     * @param array{host?: string|null, database?: string|null, username?: string|null, password?: string|null, port?: int|string, charset?: string, options?: array<int, mixed>} $config
     *
     * @throws Exception\ConnectionException When a required value is missing or the connection fails
     */
    public static function mysql(#[\SensitiveParameter] array $config): MySqlDriver
    {
        return new MySqlDriver($config);
    }

    /**
     * Create a PostgreSQL connection from the given config (see PostgresDriver for the keys).
     * Nothing is read from the environment: use fromEnv() for that.
     *
     * @param array{host?: string|null, database?: string|null, username?: string|null, password?: string|null, port?: int|string, options?: array<int, mixed>} $config
     *
     * @throws Exception\ConnectionException When a required value is missing or the connection fails
     */
    public static function postgres(#[\SensitiveParameter] array $config): PostgresDriver
    {
        return new PostgresDriver($config);
    }

    /**
     * Create a SQLite connection. Nothing is read from the environment: use fromEnv() for that.
     *
     * @param string $path Path to the SQLite file, or ':memory:' (the default) for an in-memory database
     *
     * @throws Exception\ConnectionException When the path is an empty string or the connection fails
     */
    public static function sqlite(string $path = ':memory:'): SqliteDriver
    {
        return new SqliteDriver($path);
    }

    /**
     * Create a connection for the driver named in the config.
     *
     * 'mysql' (also 'mariadb'), 'pgsql' (also 'postgres', 'postgresql') and 'sqlite' delegate to
     * mysql(), postgres() and sqlite() with the same config keys; the SQLite path comes from
     * 'path', else 'database', else it is ':memory:'. One config array for every environment:
     * the driver decides which of the keys are used. Nothing is read from the environment: use
     * fromEnv() for that.
     *
     * @param array{driver?: string|null, path?: string|null, host?: string|null, database?: string|null, username?: string|null, password?: string|null, port?: int|string, charset?: string, options?: array<int, mixed>} $config
     *
     * @throws ConnectionException When no or an unknown driver is named, a required value is missing, or the connection fails
     */
    public static function connect(#[\SensitiveParameter] array $config): DatabaseInterface
    {
        $driver = strtolower(trim($config['driver'] ?? ''));

        return match ($driver) {
            'mysql', 'mariadb' => self::mysql($config),
            'pgsql', 'postgres', 'postgresql' => self::postgres($config),
            'sqlite' => self::sqlite($config['path'] ?? $config['database'] ?? ':memory:'),
            default => throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: $driver === ''
                    ? 'No database driver given: pass \'driver\' (mysql, pgsql or sqlite), or set DB_DRIVER for fromEnv()'
                    : sprintf('Unknown database driver "%s": use mysql, pgsql or sqlite', $driver)
            ),
        };
    }

    /**
     * Create a connection from the environment: the one place in this library that reads it.
     *
     * Variables, $_ENV first, then getenv(): DB_DRIVER (mysql, pgsql or sqlite), DB_HOST, DB_PORT,
     * DB_DATABASE, DB_USERNAME, DB_PASSWORD, and for SQLite DB_SQLITE_PATH. A variable that is
     * set but empty counts as not set (`DB_HOST=` in a dotenv template): a required value is
     * then reported as missing, DB_PORT takes the driver's default. An empty DB_SQLITE_PATH
     * throws; without the variable the SQLite database is ':memory:'. The SQLite file never
     * comes from DB_DATABASE, the name of a server database.
     *
     * What is passed in $overrides counts instead of the environment - also null and an empty
     * string: fromEnv(['password' => null]) connects without a password whatever DB_PASSWORD
     * says. The keys are those of connect(), 'charset' and 'options' included.
     *
     * @param array{driver?: string|null, path?: string|null, host?: string|null, database?: string|null, username?: string|null, password?: string|null, port?: int|string, charset?: string, options?: array<int, mixed>} $overrides
     *
     * @throws ConnectionException When no or an unknown driver is named, a required value is missing, DB_SQLITE_PATH is set but empty, or the connection fails
     */
    public static function fromEnv(#[\SensitiveParameter] array $overrides = []): DatabaseInterface
    {
        // What was passed stays, null included: the union only adds the keys that are missing
        $config = $overrides + [
            'driver' => self::env('DB_DRIVER'),
            'host' => self::env('DB_HOST'),
            'database' => self::env('DB_DATABASE'),
            'username' => self::env('DB_USERNAME'),
            'password' => self::env('DB_PASSWORD'),
        ];
        $port = trim((string) self::env('DB_PORT')); // surrounding whitespace (a trailing CR from an .env file) is not part of the value
        if ($port !== '' && !array_key_exists('port', $config)) {
            $config['port'] = $port;
        }

        if (strtolower(trim($config['driver'] ?? '')) === 'sqlite') {
            // The file comes from what was passed or from DB_SQLITE_PATH - never from DB_DATABASE
            if (array_key_exists('path', $overrides) || array_key_exists('database', $overrides)) {
                return self::connect(['driver' => 'sqlite'] + $overrides);
            }

            $path = self::env('DB_SQLITE_PATH', keepEmpty: true);
            if ($path === '') {
                throw new ConnectionException(
                    message: 'Database connection failed',
                    debugMessage: 'DB_SQLITE_PATH is set but empty or not a scalar: unset it for an in-memory database, or set it to ":memory:" or the path of a file'
                );
            }

            return self::sqlite($path ?? ':memory:');
        }

        return self::connect($config);
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
     * Read by fromEnv() only. Priority: $_ENV > getenv()
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
