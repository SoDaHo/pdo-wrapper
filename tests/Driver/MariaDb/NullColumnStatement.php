<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * A statement class (PDO::ATTR_STATEMENT_CLASS) whose fetchColumn() delivers NULL, as GET_LOCK()
 * does after an error on the server (a killed thread).
 */
final class NullColumnStatement extends PDOStatement
{
    private function __construct()
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return null;
    }
}
