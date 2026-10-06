<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * A statement class (PDO::ATTR_STATEMENT_CLASS) whose fetchColumn() delivers NULL, as GET_LOCK()
 * does after an error on the server (a killed thread) - while $null is on, else the real answer.
 */
final class NullColumnStatement extends PDOStatement
{
    public static bool $null = true;

    private function __construct()
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return self::$null ? null : parent::fetchColumn($column);
    }
}
