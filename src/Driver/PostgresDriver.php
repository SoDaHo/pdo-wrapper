<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\RawExpression;

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
     * and when the sequence has no value in this session yet (explicit id before any
     * sequence-based insert); after an earlier sequence-based insert on the same
     * connection, an explicit-id insert returns that earlier value, as before. Use a raw
     * query with RETURNING for explicit ids.
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

        [$columns, $values, $params] = $this->buildInsertParts($data);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            $columns,
            $values
        );

        $this->query($sql, $params);

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
                return $this->currentValue($sequence) ?? 0;
            } catch (PDOException) {
                return 0;
            }
        }

        try {
            $this->execRaw('SAVEPOINT pdo_wrapper_insert_id');
            try {
                $id = $this->currentValue($sequence);
            } catch (PDOException) {
                $id = null;
            }
            if ($id === null) {
                // The failed probe aborted the transaction up to the savepoint: undo that part.
                $this->execRaw('ROLLBACK TO SAVEPOINT pdo_wrapper_insert_id');
                $id = 0;
            }
            $this->execRaw('RELEASE SAVEPOINT pdo_wrapper_insert_id');
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
     * Read the sequence's current value.
     *
     * @throws PDOException When the sequence does not exist or has no current value in this session
     *
     * @return int|null The value (0 if empty), or null when PDO reported the failure by returning
     *                  false instead of throwing (non-exception error mode)
     */
    private function currentValue(string $sequence): ?int
    {
        $id = $this->pdo->lastInsertId($sequence);
        if ($id === false) {
            return null;
        }

        return ($id !== '' && $id !== '0') ? (int) $id : 0;
    }

    /**
     * Run a savepoint statement on raw PDO; a false result (non-exception error mode) is a failure too.
     *
     * @throws PDOException When the statement fails
     */
    private function execRaw(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            $info = $this->pdo->errorInfo();
            $reason = is_string($info[2] ?? null) ? $info[2] : 'unknown error';
            throw new PDOException(sprintf('%s failed: %s', $sql, $reason));
        }
    }

    /**
     * Current date and time in the session's time zone, to the second: `LOCALTIMESTAMP(0)`.
     */
    public function now(): RawExpression
    {
        return new RawExpression('LOCALTIMESTAMP(0)');
    }

    /**
     * Current UTC date and time, to the second.
     */
    public function utcNow(): RawExpression
    {
        return new RawExpression("CAST(NOW() AT TIME ZONE 'UTC' AS TIMESTAMP(0))");
    }
}
