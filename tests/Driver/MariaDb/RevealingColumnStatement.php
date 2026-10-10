<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOException;
use PDOStatement;

/**
 * A statement class (installed through StatementClassPdo) whose fetchColumn() fails with a message
 * that quotes $quoted, as a database's message may quote a value: it throws a PDOException with
 * that message in its errorInfo ('throws'), or returns false and reports it through errorInfo()
 * ('false'), as PDO does in a non-exception error mode.
 */
final class RevealingColumnStatement extends PDOStatement
{
    /** @var 'throws'|'false' */
    public static string $mode = 'throws';

    public static string $quoted = '';

    private function __construct()
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (self::$mode === 'false') {
            return false;
        }
        $failure = new PDOException('the answer could not be read near ' . self::$quoted);
        $failure->errorInfo = $this->errorInfo();

        throw $failure;
    }

    /**
     * @return array{string, int, string}
     */
    public function errorInfo(): array
    {
        return ['HY000', 2027, 'the answer could not be read near ' . self::$quoted];
    }
}
