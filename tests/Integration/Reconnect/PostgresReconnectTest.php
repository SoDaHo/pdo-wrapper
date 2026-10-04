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
