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
 * MySQL database driver.
 *
 * Connects to MySQL databases using PDO with utf8mb4 charset by default.
 * Database::mysql() does the same; Database::fromEnv() reads the config from the environment.
 */
class MySqlDriver extends AbstractDriver
{
    /** True when the connection was opened with the driver's ATTR_FOUND_ROWS option: the server then counts matched rows, not changed ones */
    private bool $countsFoundRows = false;

    /**
     * Create a MySQL database connection.
     *
     * Config keys:
     * - host: MySQL server hostname (required)
     * - database: Database name (required)
     * - username: Database username (required)
     * - password: Database password (optional)
     * - port: Server port, a whole number or a string of digits (default: 3306)
     * - charset: Connection charset (default: utf8mb4)
     * - options: Additional PDO options; they replace the defaults below, the security-relevant
     *   ones included (native prepares, exceptions, no multi-statements)
     *
     * Multi-statements are switched off: no statement this library sends needs them, and with them
     * a string that reaches raw PDO (getPdo()->exec()) or an emulated prepare could carry a second
     * statement. Pass the driver's ATTR_MULTI_STATEMENTS option as true to get them back.
     *
     * @param array{host?: string|null, database?: string|null, username?: string|null, password?: string|null, port?: int|string, charset?: string, options?: array<int, mixed>} $config
     *
     * @throws ConnectionException When required config is missing or connection fails
     */
    public function __construct(#[\SensitiveParameter] array $config)
    {
        $host = $config['host'] ?? null;
        $database = $config['database'] ?? null;
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        $port = self::validPort($config['port'] ?? 3306);
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

        // Without pdo_mysql the class Pdo\Mysql does not exist; the connection then fails below
        // with PDO's own "could not find driver".
        if (extension_loaded('pdo_mysql')) {
            $defaultOptions[\Pdo\Mysql::ATTR_MULTI_STATEMENTS] = false;
        }

        $options = array_replace($defaultOptions, $config['options'] ?? []);

        // Remembered here: PDO does not let the option be read back from the connection
        if (extension_loaded('pdo_mysql')) {
            $this->countsFoundRows = (bool) ($options[\Pdo\Mysql::ATTR_FOUND_ROWS] ?? false);
        }

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
     * Most statement errors undo only the statement. Some end the whole transaction on the server:
     * a deadlock (error 1213), a lock wait timeout under innodb_rollback_on_timeout. PDO keeps
     * reporting the transaction until the next successful statement; a COMMIT right after a
     * deadlock succeeds and commits nothing, and statements sent after it would be committed one
     * by one (measured on MySQL 8.0, MariaDB 10.11 and 11.4). Every failure is remembered, the
     * latest one - except that nothing replaces a deadlock: it settles the matter
     * (transactionIsOver()), for the others transactionEndedBy() asks the server. After a lock
     * wait timeout that ended the transaction, the refusal names the latest failure, which need
     * not be that timeout.
     */
    protected function failureToRemember(?PDOException $remembered, PDOException $failure): PDOException
    {
        return $remembered !== null && self::isDeadlock($remembered) ? $remembered : $failure;
    }

    /**
     * A deadlock is the one failure after which the transaction is gone for certain. A lock wait
     * timeout is not: by default the server undoes only that statement and the transaction lives on.
     */
    protected function transactionIsOver(PDOException $failure): bool
    {
        return self::isDeadlock($failure);
    }

    /**
     * After a deadlock the transaction is gone, whatever PDO or the server report now: with
     * autocommit switched off a later statement on raw PDO has silently opened a new one, which
     * holds only what came after the deadlock. For every other failure the server is asked: one no-op
     * statement on raw PDO (no hook sees it) makes mysqlnd read the current transaction status.
     * Still in a transaction: the failure cost only its statement. Not any more: the server ended
     * it. When the question itself fails, nothing is known and nothing is committed.
     */
    protected function transactionEndedBy(PDOException $failure): ?string
    {
        if (self::isDeadlock($failure)) {
            return 'The server rolled the transaction back when a statement failed with a deadlock (error 1213, the previous exception). '
                . 'Nothing done before it can be committed. Roll back if PDO still reports a transaction, then run the whole transaction again.';
        }

        try {
            if ($this->pdo->exec('DO 1') !== false) {
                return $this->pdo->inTransaction()
                    ? null
                    : 'The server reports no transaction any more: a statement failed inside it (the previous exception), and since then the transaction was ended - '
                        . 'rolled back by the server, or committed implicitly (a DDL statement). There is nothing left to commit; what ran after its end was committed on its own.';
            }
        } catch (Throwable) {
            // reported below
        }

        return 'The transaction cannot be committed: a statement failed inside it (the previous exception) and the server could not be asked whether it still exists. '
            . 'Roll back, or discard the connection.';
    }

    /**
     * Insert a row unless it collides with an existing one (see DatabaseInterface::insertIgnore()).
     *
     * `ON DUPLICATE KEY UPDATE col = col` reports 1 affected row for an inserted row and 0 for an
     * existing one - unless the connection counts matched rows (the driver's ATTR_FOUND_ROWS
     * option): then both report 1 (measured on MySQL 8.0, MariaDB 10.11 and 11.4), and the answer
     * could not be told. The statement is not sent there.
     *
     * For an existing row the server runs the table's BEFORE INSERT, BEFORE UPDATE and AFTER
     * UPDATE triggers, although nothing is updated. A BEFORE UPDATE trigger that changes the row
     * does change it, and the server then reports 2 affected rows: still not an insert, so 0.
     *
     * @param array<string, mixed> $data Column => value pairs
     *
     * @throws QueryException When the connection was opened with ATTR_FOUND_ROWS, $data is empty or the query fails for another reason than a duplicate
     */
    public function insertIgnore(string $table, array $data): int
    {
        if ($this->countsFoundRows) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: 'insertIgnore() cannot tell an inserted row from an existing one on a connection opened with ATTR_FOUND_ROWS: the server reports 1 affected row for both. Use insert() and catch UniqueViolationException instead.'
            );
        }

        // 1: inserted. 0: existed. 2: existed and an update trigger changed the row
        return parent::insertIgnore($table, $data) === 1 ? 1 : 0;
    }

    /**
     * Error 1062 (ER_DUP_ENTRY): duplicate entry for a unique key or the primary key.
     */
    protected function isUniqueViolation(PDOException $failure): bool
    {
        return ($failure->errorInfo[1] ?? null) === 1062;
    }

    /**
     * "Duplicate entry '...' for key 'name'": only the name at the very end counts - the duplicate
     * value before it comes from outside and may itself contain "for key". What stands there
     * depends on the server (measured): MariaDB and MySQL up to 8.0.18 print the key alone
     * (`email`), MySQL since 8.0.19 puts the table in front (`users.email`). A name without a dot
     * is the key on every server. With a dot the server's version decides: where the key stands
     * alone, the name is returned as printed; where the table is in front, exactly one dot
     * separates the two and the part behind it is the key - more than one means the table or the
     * key contains a dot itself, and the name cannot be told. Where the version cannot be read,
     * a name with a dot cannot be told either. Null rather than a wrong name.
     */
    protected function violatedConstraint(PDOException $failure): ?string
    {
        if (preg_match("/ for key '([^']+)'$/", self::driverMessage($failure), $match) !== 1) {
            return null;
        }
        $name = $match[1];
        if (!str_contains($name, '.')) {
            return $name;
        }

        return match ($this->printsTheTableBeforeTheKey()) {
            false => $name,
            true => substr_count($name, '.') === 1 ? substr($name, (int) strpos($name, '.') + 1) : null,
            null => null,
        };
    }

    /**
     * Whether this server prints `table.key` in its duplicate entry message: MySQL since 8.0.19
     * does, MariaDB and older MySQL versions do not. Read from the version string the client got
     * in the handshake (nothing is sent); null when it cannot be read or is no version number.
     * A proxy that reports another server's version, or a MySQL-compatible server whose version
     * does not tell its message format, makes this answer wrong: every key then comes out as
     * `table.key`, or a key name with a dot is cut.
     */
    private function printsTheTableBeforeTheKey(): ?bool
    {
        try {
            $version = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return null;
        }
        if (!is_string($version)) {
            return null;
        }
        if (stripos($version, 'MariaDB') !== false) {
            return false;
        }
        if (preg_match('/^\d+\.\d+\.\d+/', $version, $number) !== 1) {
            return null;
        }

        return version_compare($number[0], '8.0.19', '>=');
    }

    private static function isDeadlock(PDOException $failure): bool
    {
        return ($failure->errorInfo[1] ?? null) === 1213;
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
