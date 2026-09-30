<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Query\RawExpression;

/**
 * MySQL database driver.
 *
 * Connects to MySQL databases using PDO with utf8mb4 charset by default.
 * Use Database::mysql() factory for environment variable support.
 */
class MySqlDriver extends AbstractDriver
{
    /**
     * Create a MySQL database connection.
     *
     * Config keys:
     * - host: MySQL server hostname (required)
     * - database: Database name (required)
     * - username: Database username (required)
     * - password: Database password (optional)
     * - port: Server port (default: 3306)
     * - charset: Connection charset (default: utf8mb4)
     * - options: Additional PDO options
     *
     * @param array{host?: string|null, database?: string|null, username?: string|null, password?: string|null, port?: int, charset?: string, options?: array<int, mixed>} $config
     *
     * @throws ConnectionException When required config is missing or connection fails
     */
    public function __construct(array $config)
    {
        $host = $config['host'] ?? null;
        $database = $config['database'] ?? null;
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        $port = $config['port'] ?? 3306;
        $charset = $config['charset'] ?? 'utf8mb4';

        if ($host === null || $database === null || $username === null) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'Missing required config: host, database, or username'
            );
        }

        // A ";" would append further DSN keys and could redirect the connection, credentials included;
        // NUL would truncate the DSN and drop the keys after it. pdo_mysql splits on ";" only.
        foreach (['host' => $host, 'database' => $database, 'charset' => $charset] as $key => $value) {
            if (str_contains((string) $value, ';') || str_contains((string) $value, "\0")) {
                throw new ConnectionException(
                    message: 'Database connection failed',
                    debugMessage: sprintf('Invalid character in config value "%s"', $key)
                );
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $options = array_replace($defaultOptions, $config['options'] ?? []);

        try {
            $this->pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new ConnectionException(
                message: 'Database connection failed',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: sprintf('MySQL connection to %s:%d failed: %s', $host, $port, $e->getMessage())
            );
        }
    }

    /**
     * Get the MySQL quote character (backtick).
     */
    protected function getQuoteChar(): string
    {
        return '`';
    }

    protected function getDialect(): string
    {
        return QueryBuilder::DIALECT_MYSQL;
    }

    /**
     * Current date and time in the session's time zone: `NOW()`.
     */
    public function now(): RawExpression
    {
        return new RawExpression('NOW()');
    }

    /**
     * Current UTC date and time: `UTC_TIMESTAMP()`.
     */
    public function utcNow(): RawExpression
    {
        return new RawExpression('UTC_TIMESTAMP()');
    }
}
