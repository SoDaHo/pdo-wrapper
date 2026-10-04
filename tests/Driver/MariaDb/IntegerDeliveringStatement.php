<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * A statement class (PDO::ATTR_STATEMENT_CLASS) whose fetch() turns the numeric strings of a row
 * into ints, as a client that delivers other types than mysqlnd would.
 */
final class IntegerDeliveringStatement extends PDOStatement
{
    private function __construct()
    {
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);

        return is_array($row) ? array_map(static fn (mixed $value): mixed => is_string($value) && is_numeric($value) ? (int) $value : $value, $row) : $row;
    }
}
