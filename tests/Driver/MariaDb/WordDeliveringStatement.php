<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * A statement class (installed through StatementClassPdo) whose fetch() turns every string of a
 * row into a word that is no number, as a client that delivers other values than mysqlnd would.
 */
final class WordDeliveringStatement extends PDOStatement
{
    private function __construct()
    {
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);

        return is_array($row) ? array_map(static fn (mixed $value): mixed => is_string($value) ? 'not-a-number' : $value, $row) : $row;
    }
}
