<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Query\RawExpression;
use Throwable;

/**
 * PostgreSQL database driver.
 *
 * Connects to PostgreSQL databases using PDO.
 * Database::postgres() does the same; Database::fromEnv() reads the config from the environment.
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
     * - port: Server port, a whole number or a string of digits (default: 5432)
     * - options: Additional PDO options; they replace the defaults, the security-relevant ones
     *   included (native prepares, exceptions)
     * - pdoClass: Name of a class that extends PDO (default: PDO). The connection is created as
     *   an object of that class, with the arguments PDO's constructor takes; getPdo() returns it.
     *   For a test that needs a COMMIT to fail, or for the driver's own class (Pdo\Pgsql).
     *
     * @param array{host?: string|null, database?: string|null, username?: string|null, password?: string|null, port?: int|string, options?: array<int, mixed>, pdoClass?: class-string<PDO>|null} $config
     *
     * @throws ConnectionException When required config is missing, 'pdoClass' names no class that extends PDO, or connection fails
     */
    public function __construct(#[\SensitiveParameter] array $config)
    {
        $host = $config['host'] ?? null;
        $database = $config['database'] ?? null;
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        $port = self::validPort($config['port'] ?? 5432);
        $pdoClass = self::validPdoClass($config['pdoClass'] ?? PDO::class);

        if ($host === null || $database === null || $username === null) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'Missing required config: host, database, or username'
            );
        }

        // PDO hands the DSN to libpq, which also splits on whitespace: a "host=evil" inside a value would
        // redirect the connection, credentials included. The values are therefore quoted the libpq way
        // ('...', with \ and ' escaped); ";" (PDO's own separator) and NUL (truncates the DSN) are rejected.
        foreach (['host' => $host, 'database' => $database] as $key => $value) {
            if (str_contains((string) $value, ';') || str_contains((string) $value, "\0")) {
                throw new ConnectionException(
                    message: 'Database connection failed',
                    debugMessage: sprintf('Invalid character in config value "%s"', $key)
                );
            }
        }

        $dsn = sprintf(
            "pgsql:host='%s';port=%d;dbname='%s'",
            self::quoteForLibpq((string) $host),
            $port,
            self::quoteForLibpq((string) $database)
        );

        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $options = array_replace($defaultOptions, $config['options'] ?? []);

        // Kept for reconnect(); credentials and options in an object that no dump of the driver shows
        $settings = new ConnectionSettings($username, $password, $options);
        parent::__construct(static function () use ($pdoClass, $dsn, $settings, $host, $port): PDO {
            try {
                return new $pdoClass($dsn, $settings->username(), $settings->password(), $settings->options());
            } catch (PDOException $e) {
                throw new ConnectionException(
                    message: 'Database connection failed',
                    previous: $e,
                    debugMessage: sprintf('PostgreSQL connection to %s:%d failed: %s', $host, $port, $e->getMessage())
                );
            }
        });
    }

    /**
     * Insert a row and return the last insert ID.
     *
     * Uses PostgreSQL sequence naming convention ({table}_id_seq, in the table's schema when one
     * is given) for reliable ID retrieval. Returns 0 for tables without auto-increment (composite PKs, UUIDs)
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
     * @return int Last insert ID, or 0 if table has no serial column
     */
    public function insert(string $table, array $data): int
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

        // Read before the 'query' hook runs: a listener that inserts into the same table would replace the id
        $id = null;
        $this->queryThen($sql, $params, function () use (&$id, $table): void {
            $id = $this->sequenceValue($this->sequenceName($table));
        });

        // An overridden query() that bypasses AbstractDriver::query() never ran the step
        return $id ?? $this->sequenceValue($this->sequenceName($table));
    }

    /**
     * SQLSTATE 23505 (unique_violation): duplicate key for a unique constraint or the primary key.
     */
    protected function isUniqueViolation(PDOException $failure): bool
    {
        return ($failure->errorInfo[0] ?? null) === '23505';
    }

    /**
     * `duplicate key value violates unique constraint "users_email_key"`, then a line break and the
     * detail with the duplicate value: the name is what stands between the quotes of the first
     * line. The server prints it as it is, a double quote inside the name included (measured on
     * PostgreSQL 15), so everything up to the line's last quote is taken. Only the first line is
     * read: the detail below it repeats the duplicate value, line breaks and all, and that value
     * comes from outside.
     */
    protected function violatedConstraint(PDOException $failure): ?string
    {
        if (preg_match('/\A[^\n]* violates unique constraint "(.+)"$/m', self::driverMessage($failure), $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Every statement error aborts a PostgreSQL transaction, unless a savepoint catches it: the
     * latest one is remembered. SQLSTATE 25P02 is the answer of a transaction that is aborted
     * already, a consequence: it does not replace the cause - but it is remembered when nothing
     * else is, because then the cause was a failure this library did not see (raw PDO).
     */
    protected function failureToRemember(?PDOException $remembered, PDOException $failure): PDOException
    {
        return $remembered !== null && ($failure->errorInfo[0] ?? null) === '25P02' ? $remembered : $failure;
    }

    /**
     * An aborted transaction answers every statement with an error (SQLSTATE 25P02) and a COMMIT
     * with a silent ROLLBACK that PDO reports as success. One probe statement on raw PDO (no hook
     * sees it) tells whether a savepoint caught the failure; it runs only after a statement failed.
     * A probe that fails for any other reason (the connection is gone) refuses the commit as well.
     */
    protected function transactionEndedBy(PDOException $failure): ?string
    {
        try {
            if ($this->pdo->query('SELECT 1') !== false) {
                return null;
            }
            $state = $this->pdo->errorCode();
        } catch (PDOException $e) {
            $state = $e->getCode();
        } catch (Throwable) {
            // PDO::ERRMODE_WARNING with an error handler that throws: PDO recorded the state before it warned
            $state = $this->pdo->errorCode();
        }

        return $state === '25P02'
            ? 'The transaction is aborted: a statement failed inside it (the previous exception) and no savepoint caught the failure. '
                . 'PostgreSQL would answer COMMIT with a ROLLBACK and report success. Roll back instead.'
            : sprintf(
                'The transaction cannot be committed: a statement failed inside it (the previous exception) and the connection no longer answers (SQLSTATE %s). '
                . 'Roll back, or discard the connection.',
                is_scalar($state) ? (string) $state : 'unknown'
            );
    }

    /**
     * The table's id sequence by PostgreSQL's naming convention, as a quoted name for currval():
     * `shop.users` becomes `"shop"."users_id_seq"`. Quoted like the table in the INSERT, so the
     * sequence of exactly that table is read: an unquoted name would be folded to lower case and
     * looked up through the search_path, where a table of the same name in another schema could
     * answer instead.
     */
    private function sequenceName(string $table): string
    {
        $parts = explode('.', $table);
        $parts[count($parts) - 1] .= '_id_seq';

        return implode('.', array_map(
            static fn (string $part): string => '"' . str_replace('"', '""', $part) . '"',
            $parts
        ));
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
        // Throwable, not only PDOException: in PDO::ERRMODE_WARNING an error handler that throws reports the failed probe its own way
        if (!$this->pdo->inTransaction()) {
            try {
                return $this->currentValue($sequence) ?? 0;
            } catch (Throwable) {
                return 0;
            }
        }

        try {
            $this->execRaw('SAVEPOINT pdo_wrapper_insert_id');
            try {
                $id = $this->currentValue($sequence);
            } catch (Throwable) {
                $id = null;
            }
            if ($id === null) {
                // The failed probe aborted the transaction up to the savepoint: undo that part.
                $this->execRaw('ROLLBACK TO SAVEPOINT pdo_wrapper_insert_id');
                $id = 0;
            }
            $this->execRaw('RELEASE SAVEPOINT pdo_wrapper_insert_id');
        } catch (PDOException $e) {
            $this->noteStatementFailure($e); // without the savepoint the failed probe left the transaction aborted

            throw new QueryException(
                message: 'Insert failed',
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

        // (int) cuts nothing here: a sequence is a BIGINT at most, its value fits an integer of PHP
        return ($id !== '' && $id !== '0') ? (int) $id : 0;
    }

    /**
     * Run a savepoint statement on raw PDO; a false result (non-exception error mode) is a failure too.
     *
     * @throws PDOException When the statement fails
     * @throws Throwable An error handler's exception that is not about a PDO failure
     */
    private function execRaw(string $sql): void
    {
        try {
            $failed = $this->pdo->exec($sql) === false;
        } catch (Throwable $e) {
            // Not PDO's own exception: PDO::ERRMODE_WARNING with an error handler that throws
            throw $this->failureBehind($e, $this->pdo->errorInfo()) ?? $e;
        }

        if ($failed) {
            $info = $this->pdo->errorInfo();
            $reason = is_string($info[2] ?? null) ? $info[2] : 'unknown error';
            $failure = new PDOException(sprintf('%s failed: %s', $sql, $reason));
            $failure->errorInfo = $info; // as a thrown PDOException carries it: SQLSTATE and driver code

            throw $failure;
        }
    }

    protected function getDialect(): string
    {
        return QueryBuilder::DIALECT_PGSQL;
    }

    /**
     * Escape a value for a single-quoted libpq connection parameter: backslash and apostrophe are
     * prefixed with a backslash, everything else (spaces, "=", newlines) is then literal.
     */
    private static function quoteForLibpq(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Current date and time in the session's time zone at statement time, to the second.
     *
     * statement_timestamp() rather than NOW()/LOCALTIMESTAMP: those return the transaction's start
     * time, which would drift from MySQL and SQLite inside long transactions.
     */
    public function now(): RawExpression
    {
        return new RawExpression('CAST(statement_timestamp() AS TIMESTAMP(0))');
    }

    /**
     * Current UTC date and time at statement time, to the second, as a zoneless value.
     */
    public function utcNow(): RawExpression
    {
        return new RawExpression("CAST(statement_timestamp() AT TIME ZONE 'UTC' AS TIMESTAMP(0))");
    }
}
