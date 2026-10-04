<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Pdo\Mysql;
use PDOException;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Fetched;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;
use WeakReference;

/**
 * reconnect() on MariaDB: row locks, session settings, the implicit commit of a failing DDL
 * statement, and a driver hook that remembers a failure as the end of the transaction.
 */
class ReconnectTest extends ContractTestCase
{
    private const TABLE = 'reconnect_mariadb';

    /** A second, independent connection to the same database */
    private DatabaseInterface $observer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->create(self::TABLE, ['id' => 'key', 'name' => 'text']);
        $this->observer = $this->connect();
    }

    protected function closeConnections(): void
    {
        unset($this->observer);
    }

    /** @return array<int, int> */
    private function visible(): array
    {
        return array_map(static fn (array $row): int => Fetched::int($row['id']), $this->observer->table(self::TABLE)->orderBy('id')->get());
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
     * A failing DDL statement commits the transaction implicitly: commit() is refused, finds the
     * transaction gone and tells its 'lost' at once - and an end listener that reconnects on 'lost'
     * discards the old connection while commit() is still under way. The refusal keeps the failed
     * statement in its trace (with arguments kept), the statement its PDO object: the driver does
     * not keep the refusal, so the old connection is closed once the caller drops it - after
     * commit() and after transaction().
     */
    public function testARefusedCommitWhoseEndListenerReconnectsDoesNotKeepTheOldConnectionOpen(): void
    {
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $db = $this->connect(); // no listener of the test's own: nothing here keeps the refusal
            $outcomes = [];
            $db->on('transaction.end', static function (array $end) use ($db, &$outcomes): void {
                $outcomes[] = $end['outcome'];
                if ($end['outcome'] === 'lost') {
                    $db->reconnect();
                }
            });
            $failDdl = static function () use ($db): void {
                try {
                    $db->execute('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY)'); // exists: fails, and commits
                } catch (QueryException) {
                    // swallowed: the commit finds out
                }
            };

            $old = WeakReference::create($db->getPdo());
            (function () use ($db, $failDdl): void {
                $db->beginTransaction();
                $failDdl();
                try {
                    $db->commit();
                    $this->fail('Expected CommitFailedException');
                } catch (CommitFailedException $e) {
                    $this->assertSame('lost', $e->outcome);
                }
            })();
            $this->assertSame(['lost'], $outcomes);
            $this->assertNull($old->get(), 'commit(): the old connection is closed');

            $old = WeakReference::create($db->getPdo());
            (function () use ($db, $failDdl): void {
                try {
                    $db->transaction($failDdl);
                    $this->fail('Expected CommitFailedException');
                } catch (CommitFailedException $e) {
                    $this->assertSame('lost', $e->outcome);
                }
            })();
            $this->assertSame(['lost', 'lost'], $outcomes);
            $this->assertNull($old->get(), 'transaction(): the old connection is closed');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
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

    /**
     * A driver that remembers a failure as the end of its transaction - as the MySQL driver does
     * for a deadlock - refuses a later commit on that failure without asking the server. A failure
     * of the old connection says nothing about the new one: reconnect() forgets it, also when it
     * was kept with a 'lost' told while the transaction might still be open.
     */
    public function testAFailureRememberedOnTheOldConnectionRefusesNothingOnTheNewOne(): void
    {
        $db = new class (TestEnvironment::mysql() + ['pdoClass' => ScenarioPdo::class]) extends MySqlDriver {
            protected function failureToRemember(?PDOException $remembered, PDOException $failure): PDOException
            {
                return $failure;
            }

            protected function transactionEndedBy(PDOException $failure): ?string
            {
                return str_contains($failure->getMessage(), 'fatal_table') ? 'ended by the server (scenario)' : null;
            }
        };
        try {
            $db->transaction(static function () use ($db): void {
                try {
                    $db->query('SELECT * FROM fatal_table');
                } catch (QueryException) {
                    // swallowed: remembered as the end of the transaction
                }
                $pdo = $db->getPdo();
                if ($pdo instanceof ScenarioPdo) {
                    $pdo->failRollBackAlways = true; // 'lost', the transaction may still be open: the failure is kept
                }
                throw new RuntimeException('callback failed');
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame('callback failed', $e->getMessage());
        }

        $db->reconnect();
        $db->getPdo()->beginTransaction(); // begun on raw PDO: commit() checks the remembered failure
        $db->insert(self::TABLE, ['id' => 1, 'name' => 'committed']);
        $db->commit();

        $this->assertSame([1], $this->visible());
    }
}
