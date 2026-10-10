<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;

/**
 * A PDO class (pdoClass) whose lastInsertId() reports the first value the last statement was
 * executed with: an ID that echoes a bound value - as the server does for an explicit id, and a
 * PDO class of the caller's may do for anything. Its statements (InsertIdEchoStatement) keep that
 * value; until one ran, PDO's own ID. Reset by every new connection.
 */
final class InsertIdEchoPdo extends PDO
{
    public static ?string $echoed = null;

    /**
     * @param array<int, mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null)
    {
        self::$echoed = null;
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [InsertIdEchoStatement::class]);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return self::$echoed ?? parent::lastInsertId($name);
    }
}
