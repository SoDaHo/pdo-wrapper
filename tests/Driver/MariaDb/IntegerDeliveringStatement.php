<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * A statement class (PDO::ATTR_STATEMENT_CLASS) whose fetchColumn() turns a numeric string into
 * an int, as a client that delivers other types than mysqlnd would.
 */
final class IntegerDeliveringStatement extends PDOStatement
{
    private function __construct()
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $value = parent::fetchColumn($column);

        return is_string($value) && is_numeric($value) ? (int) $value : $value;
    }
}
