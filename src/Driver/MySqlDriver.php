<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Exception\ConnectionException;

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
     * Quote an identifier using MySQL backticks.
     *
     * Handles database.table and table.column format:
     * - `users` → `users`
     * - `mydb.users` → `mydb`.`users`
     *
     * @param string $identifier Identifier to quote
     *
     * @return string Quoted identifier
     */
    protected function quoteIdentifier(string $identifier): string
    {
        // Handle schema.table or table.column format
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            return implode('.', array_map(
                fn ($part) => '`' . str_replace('`', '``', $part) . '`',
                $parts
            ));
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Get the MySQL quote character (backtick).
     */
    protected function getQuoteChar(): string
    {
        return '`';
    }
}
