<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Query\RawExpression;
use Throwable;

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
    public function __construct(array $config)
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

        // By name: Pdo\Mysql exists since PHP 8.4, and PDO::MYSQL_ATTR_MULTI_STATEMENTS is deprecated
        // since 8.5 (a deprecation notice on every connection). Without pdo_mysql the connection
        // fails below with PDO's own "could not find driver".
        if (extension_loaded('pdo_mysql')) {
            $defaultOptions[constant(PHP_VERSION_ID >= 80400 ? 'Pdo\Mysql::ATTR_MULTI_STATEMENTS' : 'PDO::MYSQL_ATTR_MULTI_STATEMENTS')] = false;
        }

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
