<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Reconnect;

use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\NamedLocksHeldException;
use Sodaho\PdoWrapper\Exception\TransactionOpenException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;

/**
 * reconnect() while a transaction begun through the library is open: refused - nothing of it would
 * be committed, and what follows would run in autocommit on the new connection - unless
 * dropTransaction gives it up knowingly ('lost'). Decided by the library's bookkeeping alone
 * (currentTransaction()), never by PDO's report: after the end - a chained transaction PDO
 * reports, a state that cannot be read, a 'lost' told while the transaction may still be open -
 * reconnect() is the way out and goes through without the option.
 */
class ReconnectOpenTransactionTest extends ContractTestCase
{
    private const TABLE = 'reconnect_open';

    private ScenarioPdo $scenario;

    /** @var list<string> */
    private array $ends = [];

    private DatabaseInterface $observer;

    protected function setUp(): void
    {
        $this->create(self::TABLE, ['id' => 'key', 'name' => 'text']);
        $this->observer = $this->connect();
        $this->db = $this->connect(['pdoClass' => ScenarioPdo::class]);
        $pdo = $this->db->getPdo();
        $this->assertInstanceOf(ScenarioPdo::class, $pdo);
        $this->scenario = $pdo;
        $this->ends = [];
        $this->db->on('transaction.end', function (array $data): void {
            $this->ends[] = is_string($data['outcome']) ? $data['outcome'] : 'invalid';
        });
    }

    protected function closeConnections(): void
    {
        unset($this->observer, $this->scenario);
    }

    public function testAnOpenTransactionIsRefusedAndNothingChanges(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $pdo = $this->db->getPdo();

        try {
            $this->db->reconnect();
            $this->fail('Expected TransactionOpenException');
        } catch (TransactionOpenException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertStringStartsWith('reconnect() would discard transaction 1', (string) $e->getDebugMessage());
            $this->assertSame([null, null, null], [$e->sqlState, $e->driverCode, $e->refusal]);
        }

        $this->assertSame($pdo, $this->db->getPdo(), 'the old connection is still in place');
        $this->assertSame(1, $this->db->currentTransaction());
        $this->assertTrue($this->db->inTransaction());
        $this->db->commit();
        $this->assertSame(['committed'], $this->ends);
        $this->assertSame([1], array_column($this->observer->table(self::TABLE)->get(), 'id'));
    }

    public function testDropTransactionGivesItUpAsLost(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $pdo = $this->db->getPdo();

        $this->db->reconnect(dropTransaction: true);

        $this->assertNotSame($pdo, $this->db->getPdo());
        $this->assertSame(['lost'], $this->ends);
        $this->assertNull($this->db->currentTransaction());
        $this->assertFalse($this->db->inTransaction());
        unset($pdo);
        $this->assertSame([], $this->observer->table(self::TABLE)->get(), 'nothing of it was committed');
    }

    /**
     * After the commit on a session that chains transactions PDO reports the chained one, which
     * the library did not begin: reconnect() discards it without the option.
     */
    public function testAChainedTransactionAfterTheEndGoesWithoutTheOption(): void
    {
        $this->db->execute("SET SESSION completion_type = 'CHAIN'");
        try {
            $this->db->transaction(fn (DatabaseInterface $db): int => $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']));
            $this->fail('Expected CommitHookException: the chained transaction');
        } catch (CommitHookException $e) {
            $this->assertTrue($e->connectionInTransaction);
        }
        $this->assertTrue($this->db->inTransaction());
        $this->assertNull($this->db->currentTransaction());

        $this->db->reconnect();

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(['committed', 'lost'], $this->ends, 'the chained transaction, begun on raw PDO as far as the library knows, is told lost');
        $this->assertSame([1], array_column($this->observer->table(self::TABLE)->get(), 'id'));
    }

    /**
     * A state that cannot be read after the end, and a 'lost' told while the transaction may still be
     * open (its rollback failed): no transaction the library holds - reconnect() goes through.
     */
    public function testAnUnreadableStateAndAMayStillBeOpenLostGoWithoutTheOption(): void
    {
        $this->scenario->stateUnreadable = true;
        $this->db->reconnect();
        $this->assertNotSame($this->scenario, $this->db->getPdo());

        $pdo = $this->db->getPdo();
        $this->assertInstanceOf(ScenarioPdo::class, $pdo);
        $this->scenario = $pdo;
        $this->scenario->failRollBackAlways = true;
        try {
            $this->db->transaction(static function (): void {
                throw new RuntimeException('the callback fails, the rollback too');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // lost, and the transaction may still be open
        }
        $this->assertSame(['lost'], $this->ends);
        $this->assertNull($this->db->currentTransaction());

        $this->db->reconnect();

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(['lost'], $this->ends, 'no second end');
    }

    /**
     * A refusal of its own kind comes in order: named locks held are refused first, as before.
     */
    public function testNamedLocksAreRefusedFirst(): void
    {
        $db = $this->db;
        $this->assertTrue($db->namedLock('reconnect-open'));
        $db->beginTransaction();
        try {
            $db->reconnect();
            $this->fail('Expected NamedLocksHeldException');
        } catch (NamedLocksHeldException) {
            // first
        }
        try {
            $db->reconnect(dropNamedLocks: true);
            $this->fail('Expected TransactionOpenException');
        } catch (TransactionOpenException) {
            // then
        }
        $db->rollback();
        $this->assertTrue($db->releaseNamedLock('reconnect-open'));
    }
}
