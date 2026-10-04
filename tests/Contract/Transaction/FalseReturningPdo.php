<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Transaction;

use PDO;

/**
 * A PDO class (pdoClass) whose calls report a failure the way PDO does in a non-exception error
 * mode: by returning false, without changing the connection state - for as long as $fail names
 * the call, or for the next such call only ($once). What ScenarioPdo does not offer: a BEGIN or
 * an insert ID that fails, a ROLLBACK that fails once, and what the database said about it.
 */
final class FalseReturningPdo extends PDO
{
    /** Which call fails: 'begin', 'commit', 'rollback', 'insert id', or '' for none */
    public string $fail = '';

    /** The failure is reported once: the call that returned false clears $fail */
    public bool $once = false;

    /**
     * What errorInfo() reports while $fail is set: what the database said about the failure. Null:
     * what PDO recorded.
     *
     * @var array<int, mixed>|null
     */
    public ?array $failureInfo = null;

    public function beginTransaction(): bool
    {
        return $this->fails('begin') ? false : parent::beginTransaction();
    }

    public function commit(): bool
    {
        return $this->fails('commit') ? false : parent::commit();
    }

    public function rollBack(): bool
    {
        return $this->fails('rollback') ? false : parent::rollBack();
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->fails('insert id') ? false : parent::lastInsertId($name);
    }

    /**
     * @return array<int, mixed>
     */
    public function errorInfo(): array
    {
        return $this->fail !== '' && $this->failureInfo !== null ? $this->failureInfo : parent::errorInfo();
    }

    private function fails(string $call): bool
    {
        if ($this->fail !== $call) {
            return false;
        }
        if ($this->once) {
            $this->fail = '';
        }

        return true;
    }
}
