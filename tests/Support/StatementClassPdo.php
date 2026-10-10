<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use PDO;
use PDOStatement;

/**
 * A PDO class (pdoClass) whose statements are of the class a test names: what
 * PDO::ATTR_STATEMENT_CLASS among the options did before the driver refused that option - a PDO
 * class of the caller's that sets one is the caller's code. config() names the class for the next
 * connection this class opens, and that connection takes it (once: a reconnect() gets PDO's own
 * statements, and nothing is left for the next test).
 */
final class StatementClassPdo extends PDO
{
    /** @var class-string<PDOStatement>|null */
    private static ?string $next = null;

    /**
     * The extra config of a driver whose statements are of that class.
     *
     * @param class-string<PDOStatement> $statementClass
     *
     * @return array{pdoClass: class-string<PDO>}
     */
    public static function config(string $statementClass): array
    {
        self::$next = $statementClass;

        return ['pdoClass' => self::class];
    }

    /**
     * @param array<int, mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null)
    {
        // Taken before the connection may fail: a failed one leaves nothing for the next
        $class = self::$next;
        self::$next = null;
        parent::__construct($dsn, $username, $password, $options);
        if ($class !== null) {
            $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [$class]);
        }
    }
}
