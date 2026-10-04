<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Throwable;

/**
 * MariaDB database driver: MariaDB 10.11 or later, through pdo_mysql built on mysqlnd.
 *
 * Connects with the utf8mb4 charset by default. Database::mariadb() does the same;
 * Database::fromEnv() reads the config from the environment.
 *
 * What comes back from a query is pinned when the connection opens: the server must be MariaDB
 * 10.11 or later and the client mysqlnd, fetched values are not turned into strings
 * (ATTR_STRINGIFY_FETCHES off), and NULL and '' are not turned into each other (ATTR_ORACLE_NULLS
 * NULL_NATURAL, PDO's default); options that set either otherwise are refused. Then, on 64-bit PHP, INT and
 * BIGINT arrive as int (a BIGINT UNSIGNED above PHP_INT_MAX as string), FLOAT and DOUBLE as float, DECIMAL - and SUM()/AVG() of integers, which
 * are DECIMAL - as string, TINYINT(1)/BOOLEAN as int, DATETIME, VARCHAR, TEXT and JSON as string,
 * NULL as null - in native and in emulated prepares alike (measured on MariaDB 10.11, 11.4 and
 * 12.3 with PHP 8.5 and mysqlnd). These are mysqlnd's types; pdo_mysql built on another client
 * library is not held to them, and is refused.
 */
class MariaDbDriver extends AbstractDriver
{
    /** True when the connection was opened with the driver's ATTR_FOUND_ROWS option: the server then counts matched rows, not changed ones */
    private bool $countsFoundRows = false;

    /**
     * Create a MariaDB database connection.
     *
     * Config keys:
     * - host: MariaDB server hostname (required)
     * - database: Database name (required)
     * - username: Database username (required)
     * - password: Database password (optional)
     * - port: Server port, a whole number or a string of digits (default: 3306)
     * - charset: Connection charset (default: utf8mb4)
     * - options: Additional PDO options; they replace the defaults below, the security-relevant
     *   ones included (native prepares, exceptions, no multi-statements)
     * - pdoClass: Name of a class that extends PDO (default: PDO). The connection is created as
     *   an object of that class, with the arguments PDO's constructor takes; getPdo() returns it.
     *   For a test that needs a COMMIT to fail, or for the driver's own class (Pdo\Mysql).
     *
     * Multi-statements are switched off: no statement this library sends needs them, and with them
     * a string that reaches raw PDO (getPdo()->exec()) or an emulated prepare could carry a second
     * statement. Pass the driver's ATTR_MULTI_STATEMENTS option as true to get them back.
     *
     * @param array{host?: string|null, database?: string|null, username?: string|null, password?: string|null, port?: int|string, charset?: string, options?: array<int, mixed>, pdoClass?: class-string<PDO>|null} $config
     *
     * @throws ConnectionException When required config is missing, 'pdoClass' names no class that extends PDO, 'options' turn ATTR_STRINGIFY_FETCHES on, the connection fails, or the server is no MariaDB 10.11 or later, the client no mysqlnd or ATTR_ORACLE_NULLS not NULL_NATURAL
     */
    public function __construct(#[\SensitiveParameter] array $config)
    {
        $host = $config['host'] ?? null;
        $database = $config['database'] ?? null;
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        $port = self::validPort($config['port'] ?? 3306);
        $pdoClass = self::validPdoClass($config['pdoClass'] ?? PDO::class);
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
            PDO::ATTR_STRINGIFY_FETCHES => false, // the PHP types of the results are the library's promise (see the class)
            \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
        ];

        $options = array_replace($defaultOptions, $config['options'] ?? []);
        if (!in_array($options[PDO::ATTR_STRINGIFY_FETCHES], [false, 0], true)) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'The option ATTR_STRINGIFY_FETCHES would turn every fetched value into a string: the library promises the PHP types of the results (see MariaDbDriver). Cast in the application instead.'
            );
        }

        // Remembered here: PDO does not let the option be read back from the connection
        $this->countsFoundRows = (bool) ($options[\Pdo\Mysql::ATTR_FOUND_ROWS] ?? false);

        // Kept for reconnect(); credentials and options in an object that no dump of the driver shows
        $settings = new ConnectionSettings($username, $password, $options);
        parent::__construct(static function () use ($pdoClass, $dsn, $settings, $host, $port): PDO {
            try {
                $pdo = new $pdoClass($dsn, $settings->username(), $settings->password(), $settings->options());
            } catch (PDOException $e) {
                throw new ConnectionException(
                    message: 'Database connection failed',
                    previous: $e,
                    debugMessage: sprintf('MariaDB connection to %s:%d failed: %s', $host, $port, $e->getMessage())
                );
            }
            self::refuseAnUnsupportedConnection($pdo, $host, $port);

            return $pdo;
        });
    }

    /**
     * Most statement errors undo only the statement. Some end the whole transaction on the server:
     * a deadlock (error 1213), a row another transaction changed since this one's snapshot under
     * innodb_snapshot_isolation (error 1020, on by default since MariaDB 11.6.2), a lock wait
     * timeout under innodb_rollback_on_timeout. PDO keeps reporting the transaction until the next
     * successful statement; a COMMIT right after such a failure commits nothing, and statements
     * sent after it would be committed one by one (measured on MariaDB 10.11, 11.4 and 12.3).
     * Every failure is remembered, the latest one - except that nothing replaces a deadlock or a
     * 1020: they settle the matter (transactionIsOver()), for the others transactionEndedBy() asks
     * the server. After a lock wait timeout that ended the transaction, the refusal names the
     * latest failure, which need not be that timeout.
     */
    protected function failureToRemember(?PDOException $remembered, PDOException $failure): PDOException
    {
        return $remembered !== null && self::endsTheTransaction($remembered) !== null ? $remembered : $failure;
    }

    /**
     * A deadlock and a 1020 are the failures after which the transaction is gone for certain. A
     * lock wait timeout is not: by default the server undoes only that statement and the
     * transaction lives on.
     */
    protected function transactionIsOver(PDOException $failure): bool
    {
        return self::endsTheTransaction($failure) !== null;
    }

    /**
     * After a deadlock or a 1020 the transaction is gone, whatever PDO or the server report now: with
     * autocommit switched off a later statement on raw PDO has silently opened a new one, which
     * holds only what came after the deadlock. For every other failure the server is asked: one no-op
     * statement on raw PDO (no hook sees it) makes mysqlnd read the current transaction status.
     * Still in a transaction: the failure cost only its statement. Not any more: the server ended
     * it. When the question itself fails, nothing is known and nothing is committed.
     */
    protected function transactionEndedBy(PDOException $failure): ?string
    {
        $why = self::endsTheTransaction($failure);
        if ($why !== null) {
            return sprintf('The server rolled the transaction back when a statement failed with %s (the previous exception). ', $why)
                . 'Nothing done before it can be committed. Roll back if PDO still reports a transaction, then run the whole transaction again.';
        }

        try {
            if ($this->askForTheTransactionStatus()) {
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
     * The server's answer to a failed statement carries no transaction status, so PDO keeps
     * reporting a transaction the server has ended: rolled back (a lock wait timeout under
     * innodb_rollback_on_timeout), or committed - a statement with an implicit commit commits the
     * open transaction even when it fails itself, `CREATE TABLE` for a table that exists
     * (measured on MariaDB 10.11, 11.4 and 12.3). The question makes PDO know. When it
     * fails while the connection goes on working (a proxy that rejects the statement), PDO
     * reports what it reported before, and that is not to be relied on: false.
     */
    protected function refreshTransactionState(): bool
    {
        return $this->askForTheTransactionStatus();
    }

    /**
     * One no-op statement on raw PDO (no hook sees it) makes mysqlnd read the server's current
     * transaction status. False when the question fails: nothing is known then.
     */
    private function askForTheTransactionStatus(): bool
    {
        try {
            return $this->pdo->exec('DO 1') !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Insert a row unless it collides with an existing one (see InternalMethods::insertIgnore()).
     *
     * `ON DUPLICATE KEY UPDATE col = col` reports 1 affected row for an inserted row and 0 for an
     * existing one - unless the connection counts matched rows (the driver's ATTR_FOUND_ROWS
     * option): then both report 1 (measured on MariaDB 10.11, 11.4 and 12.3), and the answer
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
     * Insert a row, or change the row it collides with (see InternalMethods::upsert()).
     *
     * On a connection that counts matched rows (ATTR_FOUND_ROWS) the server reports 1 for an
     * unchanged existing row, as for an inserted one (measured on 10.11, 11.4 and 12.3): the
     * statement is not sent there. upsertReturning() is not affected.
     *
     * @param array<string, mixed> $row Column => value pairs of the row
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     *
     * @throws QueryException When the connection was opened with ATTR_FOUND_ROWS, $row or $update is empty, or the query fails
     */
    public function upsert(string $table, array $row, array $update): int
    {
        $this->refuseACountOfMatchedRows('upsert()');

        return parent::upsert($table, $row, $update);
    }

    /**
     * Insert a row only when a condition holds (see InternalMethods::insertWhen()). With $update,
     * not on a connection that counts matched rows (see upsert()).
     *
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param array<array-key, mixed> $bindings Values for the condition's placeholders, in order
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     *
     * @throws QueryException When $update is given on a connection opened with ATTR_FOUND_ROWS, $data or the condition is empty, a binding is a RawExpression, or the query fails
     */
    public function insertWhen(string $table, array $data, string $condition, array $bindings = [], array $update = []): int
    {
        if ($update !== []) {
            $this->refuseACountOfMatchedRows('insertWhen() with $update');
        }

        return parent::insertWhen($table, $data, $condition, $bindings, $update);
    }

    /**
     * @throws QueryException When the connection was opened with the driver's ATTR_FOUND_ROWS option
     */
    private function refuseACountOfMatchedRows(string $what): void
    {
        if ($this->countsFoundRows) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: sprintf('%s cannot tell an inserted row from an unchanged existing one on a connection opened with ATTR_FOUND_ROWS: the server reports 1 affected row for both. Use the RETURNING form instead.', $what)
            );
        }
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
     * value before it comes from outside and may itself contain "for key". MariaDB prints the key
     * alone, without its table (measured on 10.11, 11.4 and 12.3), a name with a dot included.
     */
    protected function violatedConstraint(PDOException $failure): ?string
    {
        return preg_match("/ for key '([^']+)'$/", self::driverMessage($failure), $match) === 1 ? $match[1] : null;
    }

    /**
     * What a failure that has ended the whole transaction on the server was, or null for every
     * other failure: a deadlock (1213), or a row changed since the snapshot (1020).
     */
    private static function endsTheTransaction(PDOException $failure): ?string
    {
        return match ($failure->errorInfo[1] ?? null) {
            1213 => 'a deadlock (error 1213)',
            1020 => 'a row another transaction changed since this one read it (error 1020, innodb_snapshot_isolation)',
            default => null,
        };
    }

    /**
     * The library promises MariaDB 10.11 or later and the PHP types mysqlnd delivers: a server or
     * a client that cannot keep that promise is refused at once, before the library sends a
     * statement (an INIT_COMMAND among the options has run in the handshake already) - the same
     * at reconnect(). The version is the one the client got in the handshake (nothing is sent):
     * MariaDB before 11 prefixes it with "5.5.5-" for old MySQL clients, MariaDB Enterprise puts
     * its build number after the version ("11.4.5-3-MariaDB-enterprise"). A proxy in between
     * passes when it reports the server's version string. All of it is read from
     * the PDO object: a 'pdoClass' of the caller's that reports something else is trusted, as the
     * rest of the caller's code is.
     *
     * @throws ConnectionException
     */
    private static function refuseAnUnsupportedConnection(PDO $pdo, string $host, int $port): void
    {
        $client = $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION);
        $server = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $problem = match (true) {
            !is_string($client) || !str_starts_with($client, 'mysqlnd ') => sprintf(
                'pdo_mysql is not built on mysqlnd (client "%s"): the PHP types of the fetched values would not be the ones this library promises. Use a PHP build whose pdo_mysql uses mysqlnd.',
                is_string($client) ? $client : get_debug_type($client)
            ),
            !is_string($server) || preg_match('/^(?:5\.5\.5-)?(\d+\.\d+\.\d+)(?:-\d+)?-MariaDB(?:-|\z)/i', $server, $version) !== 1 => sprintf(
                'The server is no MariaDB (it reports "%s"): this library supports MariaDB 10.11 and later only.',
                is_string($server) ? $server : get_debug_type($server)
            ),
            version_compare($version[1], '10.11.0', '<') => sprintf(
                'MariaDB %s is older than 10.11, the oldest version this library supports.',
                $version[1]
            ),
            // Read back, not checked in the options: PDO takes any spelling of an int for it ('00', true)
            $pdo->getAttribute(PDO::ATTR_ORACLE_NULLS) !== PDO::NULL_NATURAL => sprintf(
                'ATTR_ORACLE_NULLS is %s on this connection: NULL would arrive as \'\' or \'\' as null, not as this library promises (see MariaDbDriver). Leave it at PDO::NULL_NATURAL and convert in the application.',
                var_export($pdo->getAttribute(PDO::ATTR_ORACLE_NULLS), true)
            ),
            default => null,
        };
        if ($problem !== null) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: sprintf('MariaDB connection to %s:%d refused: %s', $host, $port, $problem)
            );
        }
    }
}
