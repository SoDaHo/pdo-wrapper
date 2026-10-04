<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use PDO;

/**
 * A PDO class (pdoClass) whose lastInsertId() reports what a test wants a database to have
 * reported, as long as $reported is set.
 */
final class ReportingPdo extends PDO
{
    public static ?string $reported = null;

    public function lastInsertId(?string $name = null): string|false
    {
        return self::$reported ?? parent::lastInsertId($name);
    }
}
