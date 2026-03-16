<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Exception\ConnectionException;

/**
 * SQLite database driver.
 *
 * Connects to SQLite databases using PDO.
 * Use Database::sqlite() factory for environment variable support.
 */
class SqliteDriver extends AbstractDriver
{
    /**
     * Create a SQLite database connection.
     *
     * @param string $path Path to SQLite file or ':memory:' for in-memory database
     *
     * @throws ConnectionException When connection fails
     */
    public function __construct(string $path = ':memory:')
    {
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
}
