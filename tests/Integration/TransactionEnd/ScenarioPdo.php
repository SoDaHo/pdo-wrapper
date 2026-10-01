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

    public bool $hideTransaction = false;

    public bool $stateUnreadable = false;

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
            throw new PDOException('commit failed (scenario)');
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

    /** What PDO really knows, regardless of the scenario flags. */
    public function reallyInTransaction(): bool
    {
        return parent::inTransaction();
    }
}
