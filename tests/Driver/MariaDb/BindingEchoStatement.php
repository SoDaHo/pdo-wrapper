<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDOStatement;

/**
 * A statement class (installed through StatementClassPdo) whose fetchColumn() delivers the first
 * value the statement was executed with instead of the server's answer: a named-lock statement then
 * answers with the bound name of its lock - a statement class of the caller's may deliver anything.
 */
final class BindingEchoStatement extends PDOStatement
{
    private mixed $first = null;

    private function __construct()
    {
    }

    /**
     * @param array<mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        $this->first = $params === null ? null : (array_values($params)[0] ?? null);

        return parent::execute($params);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        parent::fetchColumn($column); // the server's answer, read and dropped

        return $this->first;
    }
}
