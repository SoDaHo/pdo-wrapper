<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * A statement class (installed through StatementClassPdo) whose fetchColumn() delivers the
 * server's real answer as text: an int becomes its digits ('1' for a lock that was taken).
 */
final class TextColumnStatement extends PDOStatement
{
    private function __construct()
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $answer = parent::fetchColumn($column);

        return is_int($answer) ? (string) $answer : $answer;
    }
}
