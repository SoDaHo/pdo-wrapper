<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use LogicException;
use PDOException;

/**
 * A PDOException whose codes cannot be read: without an errorInfo, reading it throws instead of
 * giving null (a property hook, as PHP 8.4 allows a subclass to add). What foreign code can hand
 * the library; reading it must not take down what the library is doing at that moment.
 */
final class UnreadablePdoException extends PDOException
{
    /** @var array<int, mixed>|null */
    public ?array $errorInfo = null {
        get => $this->errorInfo ?? throw new LogicException('no codes here (fixture)');
    }
}
