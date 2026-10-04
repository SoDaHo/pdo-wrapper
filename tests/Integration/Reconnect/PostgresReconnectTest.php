<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Reconnect;

use PHPUnit\Framework\Attributes\Group;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

#[Group('postgres')]
class PostgresReconnectTest extends AbstractReconnectScenarios
{
    protected function connect(array $extra = []): AbstractDriver
    {
        return Database::postgres(TestEnvironment::postgres() + $extra);
    }

    protected function createTableSql(): string
    {
        return 'CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, name VARCHAR(80))';
    }

    /**
     * A refused commit keeps its failed statement in its trace (with arguments kept), and the
     * statement its PDO object: reconnect() forgets what the driver keeps of it, so the old
     * connection is closed before the end listeners run all the same.
     */
    public function testARefusedCommitDoesNotKeepTheOldConnectionOpen(): void
    {
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            (function (): void {
                $this->db->beginTransaction();
                try {
                    $this->db->query('SELECT * FROM no_such_table_for_reconnect');
                } catch (\Sodaho\PdoWrapper\Exception\QueryException) {
                    // aborts the transaction on PostgreSQL: the commit is refused
                }
                try {
                    $this->db->commit();
                    $this->fail('Expected CommitFailedException');
                } catch (\Sodaho\PdoWrapper\Exception\CommitFailedException) {
                    // refused; the driver keeps it
                }
            })();
            $old = \WeakReference::create($this->db->getPdo());
            $alive = [];
            $this->db->on('transaction.end', static function () use ($old, &$alive): void {
                $alive[] = $old->get() !== null;
            });

            $this->db->reconnect();

            $this->assertSame([false], $alive);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    }

    /**
     * The locks of the discarded transaction are released - also while someone still holds the
     * old PDO object, which keeps the old connection open: the ROLLBACK reconnect() sends there.
     */
    public function testTheOldTransactionsLocksAreReleased(): void
    {
        $this->observer->insert(self::TABLE, ['id' => 1, 'name' => 'row']);
        $this->observer->execute("SET lock_timeout = '1s'");

        foreach (['nothing holds the old PDO object' => false, 'a reference to the old PDO object is held' => true] as $case => $hold) {
            $this->db->beginTransaction();
            $this->db->query('SELECT * FROM ' . self::TABLE . ' WHERE id = 1 FOR UPDATE');
            $held = $hold ? $this->db->getPdo() : null;

            $this->db->reconnect();

            $this->assertSame(1, $this->observer->update(self::TABLE, ['name' => $case], ['id' => 1]), $case . ': no lock wait');
            if ($held !== null) {
                $this->assertFalse($held->inTransaction(), 'the old connection is still open, its transaction rolled back');
            }
            unset($held);
        }
    }
}
