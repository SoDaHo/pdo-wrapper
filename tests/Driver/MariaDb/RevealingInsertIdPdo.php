<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;
use PDOException;

/**
 * A PDO class (pdoClass) whose lastInsertId() fails with a message that quotes $quoted, as a
 * database's message may quote a value: it throws a PDOException with that message in its
 * errorInfo ('throws'), or returns false and reports it through errorInfo() ('false'), as PDO
 * does in a non-exception error mode. Null: PDO's own. Reset the static state between tests.
 */
final class RevealingInsertIdPdo extends PDO
{
    /** @var 'throws'|'false'|null */
    public static ?string $mode = null;

    public static string $quoted = '';

    public static function reset(): void
    {
        self::$mode = null;
        self::$quoted = '';
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return match (self::$mode) {
            null => parent::lastInsertId($name),
            'false' => false,
            'throws' => throw self::failure(),
        };
    }

    /**
     * @return array<mixed>
     */
    public function errorInfo(): array
    {
        return self::$mode === null ? parent::errorInfo() : ['HY000', 2027, 'the id could not be read near ' . self::$quoted];
    }

    private static function failure(): PDOException
    {
        $failure = new PDOException('the id could not be read near ' . self::$quoted);
        $failure->errorInfo = ['HY000', 2027, 'the id could not be read near ' . self::$quoted];

        return $failure;
    }
}
