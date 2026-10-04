<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Reconnect;

use PDO;
use PDOException;
use RuntimeException;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Integration\TransactionEnd\ScenarioPdo;

/**
 * SQLite in a file: a second connection sees what was committed.
 */
class SqliteReconnectTest extends AbstractReconnectScenarios
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/pdo-wrapper-reconnect-' . bin2hex(random_bytes(4)) . '.db';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->observer);
        @unlink($this->file);
    }

    protected function connect(array $extra = []): AbstractDriver
    {
        return Database::sqlite($this->file, ($extra['options'] ?? []) + [PDO::ATTR_TIMEOUT => 2], $extra['pdoClass'] ?? PDO::class);
    }

    protected function createTableSql(): string
    {
        return 'CREATE TABLE ' . self::TABLE . ' (id INTEGER PRIMARY KEY, name TEXT)';
    }

    /**
     * A driver that remembers a failure as the end of its transaction - as the MySQL driver does
     * for a deadlock - refuses a later commit on that failure without asking the server. A failure
     * of the old connection says nothing about the new one: reconnect() forgets it, also when it
     * was kept with a 'lost' told while the transaction might still be open.
     */
    public function testAFailureRememberedOnTheOldConnectionRefusesNothingOnTheNewOne(): void
    {
        $db = new class ($this->file) extends SqliteDriver {
            public function __construct(string $path)
            {
                parent::__construct($path, [PDO::ATTR_TIMEOUT => 2], ScenarioPdo::class);
            }

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

    /**
     * PDO hands a persistent connection back for the same settings: nothing could be discarded,
     * reconnect() refuses instead of pretending.
     */
    public function testAPersistentConnectionCannotBeDiscarded(): void
    {
        $db = $this->connect(['options' => [PDO::ATTR_PERSISTENT => true]]);
        $pdo = $db->getPdo();
        $db->beginTransaction();

        try {
            $db->reconnect();
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertStringContainsString('cannot discard a persistent connection', (string) $e->getDebugMessage());
        }
        $this->assertSame($pdo, $db->getPdo(), 'nothing changed');
        $this->assertTrue($db->inTransaction());
        $db->rollback();
    }

    /**
     * A new connection to ':memory:' is a new, empty database.
     */
    public function testAnInMemoryDatabaseIsEmptyAfterReconnect(): void
    {
        $memory = Database::sqlite(':memory:');
        $memory->execute('CREATE TABLE kept (id INTEGER)');
        $memory->reconnect();

        try {
            $memory->query('SELECT * FROM kept');
            $this->fail('Expected QueryException: the table went with the old database');
        } catch (QueryException $e) {
            $this->assertStringContainsString('no such table: kept', (string) $e->getDebugMessage());
        }
        $this->assertSame([['foreign_keys' => 1]], $memory->query('PRAGMA foreign_keys')->fetchAll(), 'the new connection is set up as the first one was');
    }
}
