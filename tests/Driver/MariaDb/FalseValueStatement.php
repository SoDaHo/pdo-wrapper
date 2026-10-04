<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * A statement class (PDO::ATTR_STATEMENT_CLASS) whose fetch() delivers a row whose values are
 * false, as no client of MariaDB does.
 */
final class FalseValueStatement extends PDOStatement
{
    private function __construct()
    {
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);

        return is_array($row) ? array_map(static fn (mixed $value): bool => false, $row) : $row;
    }
}
