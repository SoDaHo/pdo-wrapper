<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use ErrorException;
use PDOException;
use PDOStatement;

/**
 * A statement class (PDO::ATTR_STATEMENT_CLASS) whose fetchColumn() cannot read the answer of a
 * statement that ran: it throws a PDOException ('pdo'), throws an exception of someone else's
 * ('other': what an error handler throws), or returns false as PDO does in a non-exception error
 * mode ('false').
 */
final class UnreadableColumnStatement extends PDOStatement
{
    /** @var 'pdo'|'other'|'false' */
    public static string $mode = 'pdo';

    private function __construct()
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return match (self::$mode) {
            'pdo' => throw new PDOException('the answer could not be read (scenario)'),
            'other' => throw new ErrorException('an error handler of the application (scenario)'),
            'false' => false,
        };
    }
}
