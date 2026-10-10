<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use PDO;
use PDOException;

/**
 * A PDO class that keeps the DSN it is given and refuses every connection, as pdo_mysql reports a
 * refused TCP connection (HY000, 2002): for the tests of what reaches the connection (host, port)
 * without a connection attempt - nothing that listens on a port of the machine can answer them.
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

        // As pdo_mysql reports a refused TCP connection: the codes in the message, the code and errorInfo
        $refused = new PDOException('SQLSTATE[HY000] [2002] Connection refused (fixture)', 2002);
        $refused->errorInfo = ['HY000', 2002, 'Connection refused (fixture)'];

        throw $refused;
    }
}
