<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * PostgreSQL database driver.
 *
 * Connects to PostgreSQL databases using PDO.
 * Use Database::postgres() factory for environment variable support.
 */
class PostgresDriver extends AbstractDriver
{
    /**
     * Create a PostgreSQL database connection.
     *
     * Config keys:
     * - host: PostgreSQL server hostname (required)
     * - database: Database name (required)
     * - username: Database username (required)
     * - password: Database password (optional)
     * - port: Server port (default: 5432)
     * - options: Additional PDO options
     *
     * @param array{host?: string|null, database?: string|null, username?: string|null, password?: string|null, port?: int, options?: array<int, mixed>} $config
     *
     * @throws ConnectionException When required config is missing or connection fails
     */
    public function __construct(array $config)
    {
        $host = $config['host'] ?? null;
        $database = $config['database'] ?? null;
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        $port = $config['port'] ?? 5432;

        if ($host === null || $database === null || $username === null) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'Missing required config: host, database, or username'
            );
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $port,
            $database
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
                debugMessage: sprintf('PostgreSQL connection to %s:%d failed: %s', $host, $port, $e->getMessage())
            );
        }
    }

    /**
     * Insert a row and return the last insert ID.
     *
     * Uses PostgreSQL sequence naming convention ({table}_id_seq) for reliable
     * ID retrieval. Returns 0 for tables without auto-increment (composite PKs, UUIDs).
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $data Column => value pairs
     *
     * @throws QueryException When $data is empty or query fails
     *
     * @return int|string Last insert ID, or 0 if table has no serial column
     */
    public function insert(string $table, array $data): int|string
    {
        if (empty($data)) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: 'Cannot insert empty data'
            );
        }

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        $this->query($sql, array_values($data));

        // Strip schema prefix for sequence name (e.g. "public.users" -> "users")
        $baseTable = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        try {
            $id = $this->pdo->lastInsertId($baseTable . '_id_seq');
            if ($id !== false && $id !== '' && $id !== '0') {
                return (int) $id;
            }
        } catch (\PDOException) {
            // No sequence for this table (composite PK, UUID PK, etc.)
        }

        return 0;
    }
}
