<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use PDO;
use PDOException;

/**
 * A PDO class whose connection can be refused from a given construction on: for a reconnect()
 * whose new connection fails. Reset $made and $refuseFrom between tests.
 */
final class RefusingPdo extends PDO
{
    /** How many objects were constructed (refused ones included) */
    public static int $made = 0;

    /** From which construction on the connection is refused (1 = the first) */
    public static int $refuseFrom = PHP_INT_MAX;

    /**
     * @param array<int, mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null)
    {
        if (++self::$made >= self::$refuseFrom) {
            throw new PDOException('connection refused (fixture)');
        }
        parent::__construct($dsn, $username, $password, $options);
    }
}
