<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Reconnect;

use PDO;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\Group;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

#[Group('mysql')]
class MySqlReconnectTest extends AbstractReconnectScenarios
{
    protected function connect(array $extra = []): AbstractDriver
    {
        return Database::mysql(TestEnvironment::mysql() + $extra);
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
        $this->observer->execute('SET SESSION innodb_lock_wait_timeout = 1');

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

    /**
     * What belongs to the session goes with the old connection. A setting given as a connection
     * option is made again on every connect; one made with SQL is not.
     */
    public function testSettingsInTheOptionsAreMadeAgainAndSettingsMadeWithSqlAreNot(): void
    {
        $db = $this->connect(['options' => [Mysql::ATTR_INIT_COMMAND => "SET SESSION sql_mode = 'ANSI_QUOTES'"]]);
        $mode = static fn (): mixed => $db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->assertSame('ANSI_QUOTES', $mode(), 'made by the option');

        $db->execute("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
        $db->execute('SET @marker = 42');
        $this->assertSame('STRICT_TRANS_TABLES', $mode());

        $db->reconnect();

        $this->assertSame('ANSI_QUOTES', $mode(), 'the option is made again; the SQL setting is gone');
        $this->assertNull($db->query('SELECT @marker')->fetchColumn(), 'so is everything else of the old session');
    }
}
