<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * A statement class (PDO::ATTR_STATEMENT_CLASS) whose fetchColumn() delivers the number as a
 * string - what a server answer would look like if it came back as text.
 */
final class StringColumnStatement extends PDOStatement
{
    private function __construct()
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return '7';
    }
}
