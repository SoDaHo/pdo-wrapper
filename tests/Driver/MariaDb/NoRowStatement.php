<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * A statement class (PDO::ATTR_STATEMENT_CLASS) whose fetch() finds no row, as a server would
 * whose RETURNING gave nothing back.
 */
final class NoRowStatement extends PDOStatement
{
    private function __construct()
    {
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }
}
