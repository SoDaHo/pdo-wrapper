<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * A statement class (installed through StatementClassPdo) whose fetchColumn() delivers the
 * server's answer wrapped in an array - an answer that is no scalar at all.
 */
final class ArrayColumnStatement extends PDOStatement
{
    private function __construct()
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return [parent::fetchColumn($column)];
    }
}
