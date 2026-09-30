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
     * ID retrieval. Returns 0 for tables without auto-increment (composite PKs, UUIDs)
     * and for rows inserted with an explicit id (the sequence was not used).
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $data Column => value pairs
     *
     * @throws QueryException When $data is empty, the query fails, or the savepoint around the ID probe fails
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

        return $this->sequenceValue($baseTable . '_id_seq');
    }

    /**
     * Read the sequence's current value, or 0 if the probe fails: no such sequence (composite or
     * UUID key) or nextval() not called in this session (explicit id).
     *
     * A failing currval() aborts the surrounding transaction, and the later COMMIT would silently
     * become a ROLLBACK. Inside a transaction the probe therefore runs in a savepoint - on raw PDO,
     * so that no 'query' hook sees the savepoint statements.
     *
     * @throws QueryException When the savepoint cannot be set, rolled back or released
     */
    private function sequenceValue(string $sequence): int
    {
        if (!$this->pdo->inTransaction()) {
            try {
                return $this->currentValue($sequence);
            } catch (PDOException) {
                return 0;
            }
        }

        try {
            $this->pdo->exec('SAVEPOINT pdo_wrapper_insert_id');
            try {
                $id = $this->currentValue($sequence);
            } catch (PDOException) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT pdo_wrapper_insert_id');
                $id = 0;
            }
            $this->pdo->exec('RELEASE SAVEPOINT pdo_wrapper_insert_id');
        } catch (PDOException $e) {
            throw new QueryException(
                message: 'Insert failed',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: 'Savepoint around the insert ID probe failed: ' . $e->getMessage()
            );
        }

        return $id;
    }

    /**
     * @throws PDOException When the sequence does not exist or has no current value in this session
     */
    private function currentValue(string $sequence): int
    {
        $id = $this->pdo->lastInsertId($sequence);

        return ($id !== false && $id !== '' && $id !== '0') ? (int) $id : 0;
    }
}
