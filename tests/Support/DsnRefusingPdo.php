<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use PDO;
use PDOException;

/**
 * A PDO class that keeps the DSN it is given and refuses every connection: for the tests of what
 * reaches the connection (host, port) without a connection attempt - nothing that listens on a port
 * of the machine can answer them.
 */
final class DsnRefusingPdo extends PDO
{
    /** The DSN of the latest construction */
    public static ?string $dsn = null;

    /**
     * Takes the DSN alone: the credentials and options the driver passes after it are not looked at.
     */
    public function __construct(string $dsn)
    {
        self::$dsn = $dsn;

        throw new PDOException('connection refused (fixture)');
    }
}
