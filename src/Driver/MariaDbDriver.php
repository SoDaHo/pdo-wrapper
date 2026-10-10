<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\ConnectionRefusal;
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
 *
 * The driver's first statement on a connection sets completion_type to NO_CHAIN - against the
 * server's default and an INIT_COMMAND, at reconnect() as well: the outcomes of a transaction
 * rely on a COMMIT and a ROLLBACK that end it and open none (see pinCompletionType()).
 */
class MariaDbDriver extends AbstractDriver
{
    /** The client's error for a statement sent while an unbuffered result is still open (CR_COMMANDS_OUT_OF_SYNC): nothing reached the server */
    private const COMMANDS_OUT_OF_SYNC = 2014;

    /** True when the connection was opened with the driver's ATTR_FOUND_ROWS option: the server then counts matched rows, not changed ones */
    private bool $countsFoundRows = false;

    /** What a named lock's name is prefixed with on the server: the configured database and ":"; null when that database name holds a ":" itself (see namedLockPrefix()) */
    private ?string $lockPrefix = null;

    /**
     * Create a MariaDB database connection.
     *
     * Config keys:
     * - host: MariaDB server hostname (required, not empty)
     * - database: Database name (required, not empty)
     * - username: Database username (required, not empty)
     * - password: Database password (optional; '' is a password)
     * - port: Server port, a whole number or a string of digits (default: 3306)
     * - charset: Connection charset (default: utf8mb4)
     * - options: Additional PDO options; they replace the defaults below, the security-relevant
     *   ones included (native prepares, exceptions, no multi-statements)
     * - pdoClass: Name of a class that extends PDO (default: PDO). The connection is created as
     *   an object of that class, with the arguments PDO's constructor takes; getPdo() returns it.
     *   For a test that needs a COMMIT to fail, or for the driver's own class (Pdo\Mysql).
     * - redactParameters: true to keep the values a statement binds out of every hook payload
     *   ('query.before', 'query', 'error': each value replaced by '[redacted]', the database's
     *   message by its codes), every debug message and every previous exception of the library
     *   (a RedactedPdoException with the SQLSTATE and driver code stands in for PDO's); default
     *   false. The trace arguments of the library's methods that take values are marked
     *   #[\SensitiveParameter] either way.
     *
     * Multi-statements are switched off and stay off: no statement this library sends needs them,
     * and with them a string that reaches raw PDO (getPdo()->exec()) or an emulated prepare could
     * carry a second statement - one the library does not judge (it reads a statement's start to
     * refuse one that commits implicitly inside a transaction). ATTR_MULTI_STATEMENTS as anything
     * but false (or 0) among the options is refused; a migration that sends a whole file in one call
     * uses a PDO connection of its own. So is ATTR_STATEMENT_CLASS, whatever its value: a statement
     * class of its own would decide what the library reads back (whether a named lock was taken, the
     * value of an aggregate); a PDO class of the caller's ('pdoClass') that sets one is the caller's code.
     *
     * @param array{host?: string|null, database?: string|null, username?: string|null, password?: string|null, port?: int|string, charset?: string, options?: array<int, mixed>, pdoClass?: class-string<PDO>|null, redactParameters?: bool} $config
     *
     * @throws ConnectionException When required config is missing, 'pdoClass' names no class that extends PDO, 'redactParameters' is no boolean, 'options' turn ATTR_STRINGIFY_FETCHES or ATTR_MULTI_STATEMENTS on or name ATTR_STATEMENT_CLASS, the connection fails, or the server is no MariaDB 10.11 or later, the client no mysqlnd or ATTR_ORACLE_NULLS not NULL_NATURAL, or completion_type cannot be set to NO_CHAIN
     * @throws \Throwable What a 'pdoClass', or an error handler under a non-exception error mode, throws while the connection opens besides a PDOException: unchanged
     */
    public function __construct(#[\SensitiveParameter] array $config)
    {
        $host = $config['host'] ?? null;
        $database = $config['database'] ?? null;
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        $port = self::validPort($config['port'] ?? 3306);
        $pdoClass = self::validPdoClass($config['pdoClass'] ?? PDO::class);
        // A key that is present is checked as it is: null switches nothing off without a word
        $redactParameters = self::validSwitch('redactParameters', array_key_exists('redactParameters', $config) ? $config['redactParameters'] : false);
        $charset = $config['charset'] ?? 'utf8mb4';

        // Empty counts as missing: pdo_mysql would take an empty host for the local socket, an empty
        // database for none (named locks then prefixed with ":" alone) and an empty user for an
        // anonymous login - none of it what the configuration meant
        if ($host === null || $host === '' || $database === null || $database === '' || $username === null || $username === '') {
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
        if (!in_array($options[\Pdo\Mysql::ATTR_MULTI_STATEMENTS], [false, 0], true)) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'The option ATTR_MULTI_STATEMENTS would let one string carry several statements: the library judges a statement by its start (it refuses one that commits implicitly inside a transaction), and the statements after the first would pass unseen. Send a migration that needs them on a PDO connection of your own.'
            );
        }
        // Any value, the default included: no statement class is needed, and one of its own decides what the
        // library reads back - whether a named lock was taken, what an aggregate is - from its fetchColumn()
        if (array_key_exists(PDO::ATTR_STATEMENT_CLASS, $options)) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'The option ATTR_STATEMENT_CLASS would hand every answer the library reads back - whether a named lock was taken, the value of an aggregate - to a statement class of its own. A PDO class of yours (pdoClass) that sets one is your code and trusted like it.'
            );
        }

        // Remembered here: PDO does not let the option be read back from the connection
        $this->countsFoundRows = (bool) ($options[\Pdo\Mysql::ATTR_FOUND_ROWS] ?? false);
        // The configured database, not DATABASE(): the same for every connection of the application
        $this->lockPrefix = str_contains($database, ':') ? null : $database . ':';

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
            self::pinCompletionType($pdo, $host, $port);

            return $pdo;
        }, $redactParameters);
    }

    /**
     * A configured switch as the boolean it must be, or a ConnectionException: a "0" or "false" from
     * a configuration file would otherwise switch it on, or off, without a word. The message names
     * the key, never the value.
     *
     * @throws ConnectionException When the value is not true or false
     */
    private static function validSwitch(string $key, mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: sprintf('Invalid config value "%s": expected true or false', $key)
            );
        }

        return $value;
    }

    /**
     * Most statement errors undo only the statement. Some end the whole transaction on the server:
     * a deadlock (error 1213), a row another transaction changed since this one's snapshot under
     * innodb_snapshot_isolation (error 1020, on by default since MariaDB 11.6.2), a lock wait
     * timeout under innodb_rollback_on_timeout. PDO keeps reporting the transaction until the next
     * successful statement; a COMMIT right after such a failure commits nothing, and statements
     * sent after it would be committed one by one (measured on MariaDB 10.11, 11.4 and 12.3).
     * Every failure is remembered, the latest one - except that nothing replaces a deadlock or a
     * 1020: they settle the matter (transactionIsOver()). For the others the driver is asked right
     * after the failure inside a transaction the library began (refreshTransactionState()), and
     * nothing more is sent once that transaction is found gone - the refusal then names the failure
     * after which it was found gone, the lock wait timeout. transactionEndedBy() asks the server
     * again at commit; in a transaction begun on raw PDO it is the only question, and after a lock
     * wait timeout that ended it the refusal names the latest failure, which need not be that timeout.
     * Not remembered at all: error 2014 (commands out of sync - a statement sent while an unbuffered
     * result is still open). The client refuses it before anything reaches the server, so it cannot
     * have ended the transaction, and a question would fail the same way and hold the transaction
     * for gone: close the cursor and send the statement again.
     */
    protected function failureToRemember(?PDOException $remembered, PDOException $failure): ?PDOException
    {
        if (($failure->errorInfo[1] ?? null) === self::COMMANDS_OUT_OF_SYNC) {
            return $remembered;
        }

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
                        . 'rolled back by the server, or committed implicitly (a DDL statement). There is nothing left to commit; what ran after its end outside of it was committed on its own.';
            }
        } catch (Throwable) {
            // reported below
        }

        return 'The transaction cannot be committed: a statement failed inside it (the previous exception) and the server could not be asked whether it still exists. '
            . 'Roll back, or discard the connection.';
    }

    /**
     * MariaDB's statements with an implicit commit, read from their leading keywords (see
     * ImplicitCommit): DDL, LOCK/UNLOCK TABLES, the table maintenance and account statements -
     * not the statements that steer transactions themselves (BEGIN, START TRANSACTION, SET
     * autocommit, XA), and not CREATE/DROP TEMPORARY TABLE.
     */
    protected function implicitCommitOf(string $sql): ?string
    {
        return ImplicitCommit::of($sql);
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
     * Insert a row unless it collides with an existing one (see DatabaseInterface::insertIgnore()).
     *
     * `ON DUPLICATE KEY UPDATE col = col` reports 1 affected row for an inserted row and 0 for an
     * existing one - unless the connection counts matched rows (the driver's ATTR_FOUND_ROWS
     * option): then both report 1 (measured on MariaDB 10.11, 11.4 and 12.3), and the answer
     * could not be told. The statement is not sent there, nor on a persistent connection, which
     * may count them without this driver knowing (see whyCountsAreUnclear()).
     *
     * For an existing row the server runs the table's BEFORE INSERT, BEFORE UPDATE and AFTER
     * UPDATE triggers, although nothing is updated. A BEFORE UPDATE trigger that changes the row
     * does change it, and the server then reports 2 affected rows: still not an insert, so 0.
     *
     * @param array<string, mixed> $data Column => value pairs
     *
     * @throws QueryException When the connection was opened with ATTR_FOUND_ROWS or is persistent, $data is empty or the query fails for another reason than a duplicate
     */
    public function insertIgnore(string $table, #[\SensitiveParameter] array $data): int
    {
        $unclear = $this->whyCountsAreUnclear();
        if ($unclear !== null) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: sprintf('insertIgnore() cannot tell an inserted row from an existing one %s. Use insert() and catch UniqueViolationException instead.', $unclear)
            );
        }

        // 1: inserted. 0: existed. 2: existed and an update trigger changed the row
        return parent::insertIgnore($table, $data) === 1 ? 1 : 0;
    }

    /**
     * Insert a row, or change the row it collides with (see DatabaseInterface::upsert()).
     *
     * On a connection that counts matched rows (ATTR_FOUND_ROWS) the server reports 1 for an
     * unchanged existing row, as for an inserted one (measured on 10.11, 11.4 and 12.3): the
     * statement is not sent there, nor on a persistent connection (see whyCountsAreUnclear()).
     * upsertReturning() is not affected.
     *
     * @param array<string, mixed> $row Column => value pairs of the row
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     *
     * @throws QueryException When the connection was opened with ATTR_FOUND_ROWS or is persistent, $row or $update is empty, or the query fails
     */
    public function upsert(string $table, #[\SensitiveParameter] array $row, #[\SensitiveParameter] array $update): int
    {
        $this->refuseACountOfMatchedRows('upsert()');

        return parent::upsert($table, $row, $update);
    }

    /**
     * Insert a row only when a condition holds (see DatabaseInterface::insertWhen()). With $update,
     * not on a connection that counts matched rows (see upsert()).
     *
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param array<array-key, mixed> $bindings Values for the condition's placeholders, in order
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     *
     * @throws QueryException When $update is given on a connection opened with ATTR_FOUND_ROWS or a persistent one, $data or the condition is empty, a binding is a RawExpression, or the query fails
     */
    public function insertWhen(string $table, #[\SensitiveParameter] array $data, string $condition, #[\SensitiveParameter] array $bindings = [], #[\SensitiveParameter] array $update = []): int
    {
        if ($update !== []) {
            $this->refuseACountOfMatchedRows('insertWhen() with $update');
        }

        return parent::insertWhen($table, $data, $condition, $bindings, $update);
    }

    /**
     * The configured database and ":" - not DATABASE(): the same for every connection of the
     * application, whatever a USE changed. None when the database name holds a ":" itself: the lock
     * "c" of the database "a:b" and the lock "b:c" of the database "a" would be one lock on the
     * server. The named-lock methods then throw; the connection itself works as ever.
     */
    protected function namedLockPrefix(): ?string
    {
        return $this->lockPrefix;
    }

    /**
     * @throws QueryException When the server's count cannot tell the rows apart (whyCountsAreUnclear())
     */
    private function refuseACountOfMatchedRows(string $what): void
    {
        $unclear = $this->whyCountsAreUnclear();
        if ($unclear !== null) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: sprintf('%s cannot tell an inserted row from an unchanged existing one %s. Use the RETURNING form instead.', $what, $unclear)
            );
        }
    }

    /**
     * Why the server's count of affected rows does not tell an existing row from an inserted one
     * on this connection, or null when it does. With ATTR_FOUND_ROWS the server counts matched
     * rows. A persistent connection may have been opened with that option by an earlier request:
     * PDO keys its pool by DSN and credentials, not by the options, and hands such a connection
     * back without it (measured on 10.11, 11.4 and 12.3) - what this driver was told then says
     * nothing.
     */
    private function whyCountsAreUnclear(): ?string
    {
        return match (true) {
            $this->countsFoundRows => 'on a connection opened with ATTR_FOUND_ROWS: the server reports 1 affected row for both',
            $this->getPdo()->getAttribute(PDO::ATTR_PERSISTENT) === true => 'on a persistent connection: PDO may hand back one an earlier request opened with ATTR_FOUND_ROWS, and the server then reports 1 affected row for both',
            default => null,
        };
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
     * alone, without its table and without escaping a quote in it (measured on 10.11, 11.4 and
     * 12.3), a name with a dot included. The greedy start takes the last " for key '" - an earlier
     * one belongs to the value -, and the name runs to the quote that ends the message.
     */
    protected function violatedConstraint(PDOException $failure): ?string
    {
        return preg_match("/^.* for key '(.+)'$/sD", self::driverMessage($failure), $match) === 1 ? $match[1] : null;
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
        $refused = match (true) {
            !is_string($client) || !str_starts_with($client, 'mysqlnd ') => [ConnectionRefusal::NotMysqlnd, sprintf(
                'pdo_mysql is not built on mysqlnd (client "%s"): the PHP types of the fetched values would not be the ones this library promises. Use a PHP build whose pdo_mysql uses mysqlnd.',
                is_string($client) ? $client : get_debug_type($client)
            )],
            !is_string($server) || preg_match('/^(?:5\.5\.5-)?(\d+\.\d+\.\d+)(?:-\d+)?-MariaDB(?:-|\z)/i', $server, $version) !== 1 => [ConnectionRefusal::NotMariaDb, sprintf(
                'The server is no MariaDB (it reports "%s"): this library supports MariaDB 10.11 and later only.',
                is_string($server) ? $server : get_debug_type($server)
            )],
            version_compare($version[1], '10.11.0', '<') => [ConnectionRefusal::MariaDbTooOld, sprintf(
                'MariaDB %s is older than 10.11, the oldest version this library supports.',
                $version[1]
            )],
            // Read back, not checked in the options: PDO takes any spelling of an int for it ('00', true)
            $pdo->getAttribute(PDO::ATTR_ORACLE_NULLS) !== PDO::NULL_NATURAL => [ConnectionRefusal::NullMode, sprintf(
                'ATTR_ORACLE_NULLS is %s on this connection: NULL would arrive as \'\' or \'\' as null, not as this library promises (see MariaDbDriver). Leave it at PDO::NULL_NATURAL and convert in the application.',
                var_export($pdo->getAttribute(PDO::ATTR_ORACLE_NULLS), true)
            )],
            default => null,
        };
        if ($refused !== null) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: sprintf('MariaDB connection to %s:%d refused: %s', $host, $port, $refused[1]),
                refusal: $refused[0]
            );
        }
    }

    /**
     * The first statement the driver sends on a connection, on raw PDO (no hook sees it): the
     * library's outcomes rely on a COMMIT or ROLLBACK that ends the transaction and opens none.
     * Under completion_type CHAIN every COMMIT and ROLLBACK opens the next transaction, which
     * nobody commits; under RELEASE the server closes the connection after it. Both can be the
     * server's default, which every new session inherits - reconnect() included -, or come from an
     * INIT_COMMAND among the options; this statement runs after both. A SET SESSION afterwards is
     * the caller's: see AbstractDriver::commitMayHaveChained().
     *
     * @throws ConnectionException When the statement fails
     * @throws Throwable What a PDO class of the caller's, or an error handler under a non-exception error mode, throws from exec() besides a PDOException: unchanged
     */
    private static function pinCompletionType(PDO $pdo, string $host, int $port): void
    {
        try {
            if ($pdo->exec("SET SESSION completion_type = 'NO_CHAIN'") !== false) {
                return;
            }
            $failure = null;
            $message = $pdo->errorInfo()[2] ?? null;
            $why = is_string($message) ? $message : 'PDO::exec() returned false';
        } catch (PDOException $e) {
            $failure = $e;
            $why = $e->getMessage();
        }

        throw new ConnectionException(
            message: 'Database connection failed',
            previous: $failure,
            debugMessage: sprintf("MariaDB connection to %s:%d failed: SET SESSION completion_type = 'NO_CHAIN' did not go through: %s", $host, $port, $why)
        );
    }
}
