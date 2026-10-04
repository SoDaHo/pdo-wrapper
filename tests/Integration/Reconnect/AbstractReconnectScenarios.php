<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Reconnect;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Integration\TransactionEnd\ScenarioPdo;
use Sodaho\PdoWrapper\Tests\Support\Fetched;
use Sodaho\PdoWrapper\Tests\Support\RefusingPdo;
use Throwable;

/**
 * reconnect(): the driver discards its connection and continues on a new one, opened with the
 * settings it was created with. Run on every engine, through the factories a consumer calls.
 */
abstract class AbstractReconnectScenarios extends TestCase
{
    protected const TABLE = 'reconnect_scenarios';

    final protected AbstractDriver $db;

    /** A second, independent connection to the same database */
    final protected DatabaseInterface $observer;

    /** @var list<string> */
    protected array $events = [];

    /** @var list<array{outcome: string, error: ?Throwable, transaction: ?int, depth: ?int}> */
    protected array $ends = [];

    /**
     * A driver of this engine, created through its factory, on the test database.
     *
     * @param array{pdoClass?: class-string<PDO>, options?: array<int, mixed>} $extra
     */
    abstract protected function connect(array $extra = []): AbstractDriver;

    abstract protected function createTableSql(): string;

    protected function setUp(): void
    {
        RefusingPdo::$made = 0;
        RefusingPdo::$refuseFrom = PHP_INT_MAX;
        $this->observer = $this->connect();
        $this->observer->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->observer->execute($this->createTableSql());
        $this->db = $this->listenTo($this->connect(['pdoClass' => ScenarioPdo::class]));
    }

    protected function tearDown(): void
    {
        RefusingPdo::$refuseFrom = PHP_INT_MAX;
        unset($this->db); // closes its connection first: the DROP must not wait on its locks
        $this->observer->execute('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    protected function listenTo(AbstractDriver $db): AbstractDriver
    {
        $this->events = [];
        $this->ends = [];
        foreach (['transaction.begin' => 'begin', 'transaction.commit' => 'commit', 'transaction.rollback' => 'rollback'] as $event => $name) {
            $db->on($event, function (array $data) use ($name): void {
                $this->events[] = sprintf('%s %s', $name, is_int($data['transaction']) ? $data['transaction'] : '-');
            });
        }
        $db->on('transaction.end', function (array $data): void {
            $outcome = is_string($data['outcome']) ? $data['outcome'] : 'invalid';
            $transaction = is_int($data['transaction']) ? $data['transaction'] : null;
            $this->events[] = sprintf('end %s %s', $outcome, $transaction ?? '-');
            $this->ends[] = [
                'outcome' => $outcome,
                'error' => $data['error'] instanceof Throwable ? $data['error'] : null,
                'transaction' => $transaction,
                'depth' => is_int($data['depth']) ? $data['depth'] : null,
            ];
        });

        return $db;
    }

    protected function scenarioPdo(): ScenarioPdo
    {
        $pdo = $this->db->getPdo();
        $this->assertInstanceOf(ScenarioPdo::class, $pdo);

        return $pdo;
    }

    /** @return array<int, int> */
    protected function visible(): array
    {
        return array_map(static fn (array $row): int => Fetched::int($row['id']), $this->observer->table(self::TABLE)->orderBy('id')->get());
    }

    // ---- the new connection ----------------------------------------------------------------------

    /**
     * Without a transaction: a new PDO object of the same class, which works; the numbers of the
     * transactions go on counting.
     */
    public function testReconnectContinuesOnANewConnection(): void
    {
        $this->db->transaction(fn (): int => $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before']));
        $old = $this->db->getPdo();
        $this->events = [];

        $this->db->reconnect();

        $this->assertNotSame($old, $this->db->getPdo());
        $this->assertInstanceOf(ScenarioPdo::class, $this->db->getPdo(), 'opened with the same settings, the PDO class included');
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame([], $this->events, 'nothing was owed: nothing is told');

        $this->db->transaction(fn (): int => $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'after']));
        $this->assertSame(['begin 2', 'commit 2', 'end committed 2'], $this->events, 'the numbers go on counting');
        $this->assertSame([1, 2], $this->visible());
    }

    /**
     * An open transaction is discarded with its connection: its end is 'lost', with its number;
     * no rollback listener runs; nothing of it is committed.
     */
    public function testAnOpenTransactionEndsAsLostAndNothingOfItIsCommitted(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'discarded']);

        $this->db->reconnect();

        $this->assertSame(['begin 1', 'end lost 1'], $this->events);
        $this->assertSame(1, $this->ends[0]['depth']);
        $this->assertInstanceOf(TransactionException::class, $this->ends[0]['error']);
        $this->assertSame('Transaction discarded with its connection', $this->ends[0]['error']->getMessage());
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame([], $this->visible(), 'nothing of it is committed');

        $this->db->getPdo()->beginTransaction();
        $this->db->commit();
        $this->assertSame(['begin 1', 'end lost 1', 'commit -', 'end committed -'], $this->events, 'it is gone, not "may still be open": a transaction begun on raw PDO afterwards tells its end');

        $this->db->transaction(fn (): int => $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'after']));
        $this->assertSame(['begin 1', 'end lost 1', 'commit -', 'end committed -', 'begin 2', 'commit 2', 'end committed 2'], $this->events);
        $this->assertSame(1, $this->ends[2]['depth'], 'nothing is owed any more: back at depth 1');
        $this->assertSame([2], $this->visible());
    }

    /**
     * A transaction begun on raw PDO owes its end as well (its commit or rollback through the
     * driver would tell one): 'lost', without a number.
     */
    public function testATransactionBegunOnRawPdoEndsAsLostWithoutANumber(): void
    {
        $this->db->getPdo()->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'discarded']);

        $this->db->reconnect();

        $this->assertSame(['end lost -'], $this->events);
        $this->assertNull($this->ends[0]['depth']);
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame([], $this->visible());
    }

    /**
     * A transaction begun through the driver that PDO no longer reports (it ended behind the
     * driver's back) still owes its end: reconnect() tells it as 'lost'.
     */
    public function testATransactionThatVanishedBehindTheDriversBackEndsAsLost(): void
    {
        $this->db->beginTransaction();
        $this->db->getPdo()->rollBack();

        $this->db->reconnect();

        $this->assertSame(['begin 1', 'end lost 1'], $this->events);
        $this->db->transaction(static fn (): null => null);
        $this->assertSame(['begin 1', 'end lost 1', 'begin 2', 'commit 2', 'end committed 2'], $this->events);
        $this->assertSame(1, $this->ends[1]['depth'], 'nothing is owed any more');
    }

    /**
     * A statement that failed on the old connection is forgotten with it: it does not refuse the
     * commit of a transaction on the new one (on PostgreSQL every failed statement would).
     */
    public function testAFailedStatementOfTheOldConnectionIsForgotten(): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT * FROM no_such_table_for_reconnect');
            $this->fail('Expected QueryException');
        } catch (\Sodaho\PdoWrapper\Exception\QueryException) {
        }

        $this->db->reconnect();
        $this->db->getPdo()->beginTransaction(); // begun on raw PDO: commit() asks about a remembered failure
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'committed']);
        $this->db->commit();

        $this->assertSame([1], $this->visible());
    }

    /**
     * The new connection is opened first: when that fails, a ConnectionException reaches the
     * caller and nothing has changed - the old connection, its transaction and its owed end are
     * still there.
     */
    public function testWhenTheNewConnectionCannotBeOpenedNothingChanges(): void
    {
        $this->db = $this->listenTo($this->connect(['pdoClass' => RefusingPdo::class]));
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'kept']);
        $old = $this->db->getPdo();
        RefusingPdo::$refuseFrom = RefusingPdo::$made + 1;

        try {
            $this->db->reconnect();
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertSame('connection refused (fixture)', $e->getPrevious()?->getMessage());
        }

        $this->assertSame($old, $this->db->getPdo(), 'the old connection is still in place');
        $this->assertTrue($this->db->inTransaction());
        $this->assertSame(['begin 1'], $this->events, 'nothing was told');

        $this->db->commit();
        $this->assertSame(['begin 1', 'commit 1', 'end committed 1'], $this->events, 'the transaction is the caller\'s, as before');
        $this->assertSame([1], $this->visible());
    }

    /**
     * transaction() whose ROLLBACK failed told 'lost' while the transaction might still be open.
     * reconnect() discards that connection: the end was told already, no second one is. A statement
     * that failed in it is forgotten with it - on PostgreSQL it would refuse every later commit.
     */
    public function testALostToldWhileTheTransactionMightStillBeOpenIsNotToldAgain(): void
    {
        $cause = new RuntimeException('callback failed');
        try {
            $this->db->transaction(function () use ($cause): void {
                $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'discarded']);
                try {
                    $this->db->query('SELECT * FROM no_such_table_for_reconnect');
                } catch (\Sodaho\PdoWrapper\Exception\QueryException) {
                    // swallowed: remembered by the driver as a failure that may have ended the transaction
                }
                $this->scenarioPdo()->failRollBackAlways = true;
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }
        $this->assertSame(['begin 1', 'end lost 1'], $this->events);

        $this->db->reconnect();

        $this->assertSame(['begin 1', 'end lost 1'], $this->events, 'no second end');
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame([], $this->visible());

        $this->db->getPdo()->beginTransaction();
        $this->db->commit();
        $this->assertSame(['begin 1', 'end lost 1', 'commit -', 'end committed -'], $this->events, 'the mark of that lost is gone: a transaction begun on raw PDO tells its end');
        $this->db->transaction(static fn (): null => null);
        $this->assertSame(['begin 1', 'end lost 1', 'commit -', 'end committed -', 'begin 2', 'commit 2', 'end committed 2'], $this->events, 'no rollback() is owed any more');
    }

    // ---- called in the middle of the library's own work -------------------------------------------

    /**
     * In the callback of transaction(): the transaction it began ends as 'lost' there, and
     * transaction() sends no COMMIT for it - nothing is committed.
     */
    public function testReconnectInTheCallbackOfTransaction(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'discarded']);
                $this->db->reconnect();
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame('lost', $e->outcome);
        }

        $this->assertSame(['begin 1', 'end lost 1'], $this->events, 'one end, told by reconnect()');
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame([], $this->visible());
    }

    /**
     * In a commit listener: the transaction is committed, its end is told as such; the driver
     * goes on on the new connection.
     */
    public function testReconnectInACommitListener(): void
    {
        $once = true;
        $this->db->on('transaction.commit', function () use (&$once): void {
            if ($once) {
                $once = false;
                $this->db->reconnect();
            }
        });
        $old = $this->db->getPdo();

        $this->db->transaction(fn (): int => $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'committed']));

        $this->assertSame(['begin 1', 'commit 1', 'end committed 1'], $this->events);
        $this->assertNotSame($old, $this->db->getPdo());
        $this->assertSame([1], $this->visible());
    }

    /**
     * In a begin listener: the transaction that was just begun ends as 'lost', and the begin
     * fails - the caller would otherwise work outside of the transaction it asked for.
     */
    public function testReconnectInABeginListener(): void
    {
        $once = true;
        $this->db->on('transaction.begin', function () use (&$once): void {
            if ($once) {
                $once = false;
                $this->db->reconnect();
            }
        });

        try {
            $this->db->beginTransaction();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to begin transaction', $e->getMessage());
            $this->assertStringContainsString('A transaction.begin listener ended the transaction', (string) $e->getDebugMessage());
        }

        $this->assertSame(['begin 1', 'end lost 1'], $this->events);
        $this->assertFalse($this->db->inTransaction());
    }

    /**
     * In an end listener: the transaction that ended is over; the driver goes on on the new
     * connection, and a transaction the listener runs there is its own.
     */
    public function testReconnectInAnEndListener(): void
    {
        $once = true;
        $this->db->on('transaction.end', function () use (&$once): void {
            if ($once) {
                $once = false;
                $this->db->reconnect();
                $this->db->transaction(fn (): int => $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'listener']));
            }
        });

        $this->db->transaction(fn (): int => $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'committed']));

        $this->assertSame(['begin 1', 'commit 1', 'end committed 1', 'begin 2', 'commit 2', 'end committed 2'], $this->events);
        $this->assertSame([1, 2], $this->visible());
    }

    // ---- what reconnect() needs ------------------------------------------------------------------

    /**
     * A driver that set its PDO object itself has no settings to open a new connection with.
     */
    public function testADriverWithoutItsConnectionSettingsCannotReconnect(): void
    {
        $pdo = $this->db->getPdo();
        $custom = new class ($pdo) extends \Sodaho\PdoWrapper\Driver\SqliteDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };

        try {
            $custom->reconnect();
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertStringContainsString('reconnect() needs the connection settings the driver was created with', (string) $e->getDebugMessage());
        }
        $this->assertSame($pdo, $custom->getPdo());
    }

}
