<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\TransactionEnd;

use PDO;
use PDOException;

/**
 * A real connection whose commit(), rollBack() and inTransaction() can be made to fail or lie on
 * demand, so that the same scenarios run against SQLite, MySQL/MariaDB and PostgreSQL. These are
 * simulations of what a driver reports; the real engine behaviour (deadlock, lost connection,
 * COMMIT rejected by the server) is measured in the driver integration tests.
 */
final class ScenarioPdo extends PDO
{
    public bool $failRollBackAlways = false;

    public bool $failCommit = false;

    /** The failure as a non-exception error mode reports it: commit() returns false, once */
    public bool $commitReturnsFalse = false;

    /** After the failed commit the driver reports no transaction any more (as PostgreSQL does after a COMMIT it rejected) */
    public bool $vanishOnFailedCommit = false;

    public bool $hideTransaction = false;

    public bool $stateUnreadable = false;

    /** exec() fails: what a driver's own question to the server (a statement on raw PDO) runs into on a broken connection */
    public bool $failExec = false;

    /** exec() makes the driver report no transaction from then on: a driver's question to the server that learns the server ended it */
    public bool $vanishOnExec = false;

    public function exec(string $statement): int|false
    {
        if ($this->failExec) {
            throw new PDOException('exec failed (scenario)');
        }
        if ($this->vanishOnExec) {
            $this->vanishOnExec = false;
            $this->hideTransaction = true;
        }

        return parent::exec($statement);
    }

    public function rollBack(): bool
    {
        if ($this->failRollBackAlways) {
            throw new PDOException('rollback failed (scenario)');
        }

        return parent::rollBack();
    }

    public function commit(): bool
    {
        if ($this->failCommit) {
            $this->failCommit = false;
            $this->vanish();
            throw new PDOException('commit failed (scenario)');
        }
        if ($this->commitReturnsFalse) {
            $this->commitReturnsFalse = false;
            $this->vanish();

            return false;
        }

        return parent::commit();
    }

    public function inTransaction(): bool
    {
        if ($this->stateUnreadable) {
            throw new PDOException('state unreadable (scenario)');
        }

        return $this->hideTransaction ? false : parent::inTransaction();
    }

    /** After a failed commit with $vanishOnFailedCommit: the state is readable again and reports no transaction. */
    private function vanish(): void
    {
        if ($this->vanishOnFailedCommit) {
            $this->hideTransaction = true;
            $this->stateUnreadable = false;
        }
    }

    /** What PDO really knows, regardless of the scenario flags. */
    public function reallyInTransaction(): bool
    {
        return parent::inTransaction();
    }
}
