<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Reconnect;

use PDO;
use PDOStatement;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Fetched;
use Sodaho\PdoWrapper\Tests\Support\RefusingPdo;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;
use Throwable;
use WeakReference;

/**
 * reconnect(): the driver discards its connection and continues on a new one, opened with the
 * settings it was created with, through the factory a consumer calls.
 */
class ReconnectTest extends ContractTestCase
{
    protected const TABLE = 'reconnect_scenarios';

    /** A second, independent connection to the same database */
    final protected DatabaseInterface $observer;

    /** @var list<string> */
    protected array $events = [];

    /** @var list<array{outcome: string, error: ?Throwable, transaction: ?int, depth: ?int}> */
    protected array $ends = [];

    protected function setUp(): void
    {
        RefusingPdo::$made = 0;
        RefusingPdo::$refuseFrom = PHP_INT_MAX;
        $this->create(self::TABLE, ['id' => 'key', 'name' => 'text']);
        $this->observer = $this->connect();
        $this->db = $this->listenTo($this->connect(['pdoClass' => ScenarioPdo::class]));
    }

    protected function tearDown(): void
    {
        RefusingPdo::$refuseFrom = PHP_INT_MAX;
        parent::tearDown();
    }

    protected function closeConnections(): void
    {
        unset($this->observer);
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

    /**
     * Foreign code inside the ROLLBACK on the old connection - an error handler for a PDO warning -
     * ends the transaction through the driver: that call tells its end, reconnect() tells no second
     * one.
     */
    public function testAnErrorHandlerThatEndsTheTransactionDuringTheOldRollbackLeavesOneEnd(): void
    {
        $this->db->beginTransaction();
        $this->scenarioPdo()->duringRollBack = function (): void {
            $this->db->rollback();
        };

        $this->db->reconnect();

        $this->assertSame(['begin 1', 'rollback 1', 'end rolled_back 1'], $this->events, 'one end, told by the handler');
        $this->assertFalse($this->db->inTransaction());
        $this->db->transaction(static fn (): null => null);
        $this->assertSame(['begin 1', 'rollback 1', 'end rolled_back 1', 'begin 2', 'commit 2', 'end committed 2'], $this->events);
        $this->assertSame(1, $this->ends[1]['depth'], 'nothing is owed any more');
    }

    /**
     * The handler ends the transaction and begins another one through the driver - on the old
     * connection: that one goes with it, and ends as 'lost'.
     */
    public function testATransactionAnErrorHandlerBeginsOnTheOldConnectionEndsAsLost(): void
    {
        $this->db->beginTransaction();
        $this->scenarioPdo()->duringRollBack = function (): void {
            $this->db->rollback();
            $this->db->beginTransaction();
        };

        $this->db->reconnect();

        $this->assertSame(['begin 1', 'rollback 1', 'end rolled_back 1', 'begin 2', 'end lost 2'], $this->events);
        $this->assertFalse($this->db->inTransaction());
        $this->db->transaction(static fn (): null => null);
        $this->assertSame(1, $this->ends[2]['depth'], 'nothing is owed any more');
    }

    /**
     * A transaction begun on raw PDO that the handler ends through the driver tells its end there;
     * reconnect() tells no second one.
     */
    public function testAnErrorHandlerThatEndsARawTransactionDuringTheOldRollbackLeavesOneEnd(): void
    {
        $this->db->getPdo()->beginTransaction();
        $this->scenarioPdo()->duringRollBack = function (): void {
            $this->db->rollback();
        };

        $this->db->reconnect();

        $this->assertSame(['rollback -', 'end rolled_back -'], $this->events);
    }

    /**
     * The handler inside the ROLLBACK on the old connection reconnects itself and begins a
     * transaction on its new connection: the outer reconnect() tells nothing more and keeps that
     * connection and its transaction.
     */
    public function testAReconnectInsideTheOldRollbackKeepsItsNewConnection(): void
    {
        $this->db->beginTransaction();
        $this->scenarioPdo()->duringRollBack = function (): void {
            $this->db->reconnect();
            $this->db->beginTransaction();
        };

        $this->db->reconnect();

        $this->assertSame(['begin 1', 'end lost 1', 'begin 2'], $this->events, 'one end for the first, told by the inner reconnect()');
        $this->assertTrue($this->db->inTransaction(), "the handler's transaction is still open on its connection");
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'kept']);
        $this->db->commit();
        $this->assertSame(['begin 1', 'end lost 1', 'begin 2', 'commit 2', 'end committed 2'], $this->events);
        $this->assertSame([1], $this->visible());
    }

    /**
     * The same when the foreign code runs inside the first question reconnect() asks the old PDO
     * object: the transaction begun on the new connection is not taken for the old one.
     */
    public function testAReconnectInsideTheFirstQuestionKeepsItsNewConnection(): void
    {
        $this->scenarioPdo()->duringInTransaction = function (): void {
            $this->db->reconnect();
            $this->db->beginTransaction();
        };

        $this->db->reconnect();

        $this->assertSame(['begin 1'], $this->events);
        $this->assertTrue($this->db->inTransaction(), "the handler's transaction is still open on its connection");
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'kept']);
        $this->db->commit();
        $this->assertSame(['begin 1', 'commit 1', 'end committed 1'], $this->events);
        $this->assertSame([1], $this->visible());
    }

    /**
     * The old connection is closed at the swap, before the end listeners run: what its session
     * held - a lock the ROLLBACK does not free, say - does not outlive it while they work on the
     * new connection (unless someone else holds the old PDO object).
     */
    public function testTheOldConnectionIsClosedBeforeTheEndListenersRun(): void
    {
        $this->db->beginTransaction();
        $old = WeakReference::create($this->db->getPdo());
        $alive = [];
        $this->db->on('transaction.end', static function () use ($old, &$alive): void {
            $alive[] = $old->get() !== null;
        });

        $this->db->reconnect();

        $this->assertSame([false], $alive);
    }

    /**
     * The driver keeps nothing of a commit that failed - thrown, or reported by returning false -
     * once its caller drops the exception: what its trace holds with arguments kept (here a
     * statement of the old connection, an argument of the call commit() was made from) goes with
     * it, and the old connection closes at reconnect().
     */
    public function testAFailedCommitIsNotKeptByTheDriver(): void
    {
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach (['thrown' => true, 'returned false' => false] as $case => $thrown) {
                $old = WeakReference::create($this->db->getPdo());
                (function () use ($thrown): void {
                    $this->db->beginTransaction();
                    if ($thrown) {
                        $this->scenarioPdo()->failCommit = true;
                    } else {
                        $this->scenarioPdo()->commitReturnsFalse = true;
                    }
                    $commit = function (PDOStatement $held): void {
                        $this->db->commit();
                    };
                    try {
                        $commit($this->db->getPdo()->prepare('SELECT 1'));
                        $this->fail('Expected CommitFailedException');
                    } catch (CommitFailedException) {
                        // dropped here
                    }
                })();

                $this->db->reconnect();

                $this->assertNull($old->get(), $case);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    }

    /**
     * The handler inside the first question reconnects while the ROLLBACK of the old connection
     * fails: the old transaction is still there when the outer call goes on, and only the old
     * connection is rolled back - not the transaction the handler began on its new one.
     */
    public function testOnlyTheOldConnectionIsRolledBackWhenAHandlerReconnectedInTheFirstQuestion(): void
    {
        $this->db->beginTransaction();
        $old = $this->scenarioPdo();
        $old->failRollBackAlways = true;
        $old->duringInTransaction = function (): void {
            $this->db->reconnect();
            $this->db->beginTransaction();
        };
        unset($old);

        $this->db->reconnect();

        $this->assertSame(['begin 1', 'end lost 1', 'begin 2'], $this->events);
        $this->assertTrue($this->db->inTransaction(), "the handler's transaction on its connection");
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'kept']);
        $this->db->commit();
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

    // ---- what reconnect() needs ------------------------------------------------------------------

    /**
     * A driver that set its PDO object itself has no settings to open a new connection with.
     */
    public function testADriverWithoutItsConnectionSettingsCannotReconnect(): void
    {
        $pdo = $this->db->getPdo();
        $custom = new class ($pdo) extends AbstractDriver {
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
