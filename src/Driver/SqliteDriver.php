<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use PDO;
use PDOException;
use PDOStatement;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Query\RawExpression;

/**
 * SQLite database driver.
 *
 * Connects to SQLite databases using PDO.
 * Database::sqlite($path) does the same; Database::fromEnv() reads the path from DB_SQLITE_PATH.
 */
class SqliteDriver extends AbstractDriver
{
    /**
     * Create a SQLite database connection.
     *
     * @param string $path Path to SQLite file or ':memory:' for in-memory database
     *
     * @throws ConnectionException When the path is empty or contains a NUL byte, or the connection fails
     */
    public function __construct(string $path = ':memory:')
    {
        // SQLite opens a private temporary database for an empty path and deletes it when the
        // connection closes: a missing setting would look like a working database that forgets everything
        if ($path === '') {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'SQLite path is empty: use ":memory:" for an in-memory database, or the path of a file'
            );
        }

        // The path ends at a NUL byte for SQLite: "data.db\0.txt" would open "data.db"
        if (str_contains($path, "\0")) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'Invalid character in config value "path"'
            );
        }

        $dsn = sprintf('sqlite:%s', $path);

        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->pdo = new PDO($dsn, null, null, $defaultOptions);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $e) {
            throw new ConnectionException(
                message: 'Database connection failed',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: sprintf('SQLite connection failed: %s', $e->getMessage())
            );
        }
    }

    protected function getDialect(): string
    {
        return QueryBuilder::DIALECT_SQLITE;
    }

    /**
     * SQLite reports every constraint failure with the same code (19, SQLSTATE 23000); the message
     * tells them apart: "UNIQUE constraint failed: table.column" for unique keys and primary keys.
     * It names columns, never the constraint: violatedConstraint() stays null.
     */
    protected function isUniqueViolation(PDOException $failure): bool
    {
        return ($failure->errorInfo[1] ?? null) === 19
            && str_starts_with(self::driverMessage($failure), 'UNIQUE constraint failed:');
    }

    /**
     * Backtick, not the double quote: SQLite reads a double-quoted name that is not a column as a
     * string literal (legacy behaviour, on by default), so a typo in a column name silently compares
     * or sorts by a constant. A backtick-quoted name is always an identifier and fails with
     * "no such column".
     */
    protected function getQuoteChar(): string
    {
        return '`';
    }

    /**
     * Bind integers as integers and booleans as 0/1. PDOStatement::execute($params) binds every
     * value as text (false as ''), and SQLite converts text to a number only through a column's
     * affinity. Compared with an expression that has none (`HAVING COUNT(*) > ?`, `WHERE price * 2 > ?`)
     * a text sorts above every number, so the numeric result is never greater than the text "1".
     * Strings, floats and null keep the default binding.
     *
     * @param array<int|string, mixed> $params
     */
    protected function bindAndExecute(PDOStatement $stmt, array $params): bool
    {
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }

        return $stmt->execute();
    }

    /**
     * Current local date and time: `datetime('now', 'localtime')`.
     *
     * SQLite takes "local" from the operating system's time zone, not from PHP's date.timezone.
     */
    public function now(): RawExpression
    {
        return new RawExpression("datetime('now', 'localtime')");
    }

    /**
     * Current UTC date and time: `datetime('now')`.
     */
    public function utcNow(): RawExpression
    {
        return new RawExpression("datetime('now')");
    }
}
