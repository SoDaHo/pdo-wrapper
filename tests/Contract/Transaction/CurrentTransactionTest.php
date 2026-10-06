<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Transaction;

use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;

/**
 * currentTransaction(): the number of the open transaction begun through the driver - the
 * 'transaction' its events carry -, null when none is open.
 */
class CurrentTransactionTest extends ContractTestCase
{
    /** @var list<int|null> The 'transaction' of every transaction.begin */
    private array $begun = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $this->db->on('transaction.begin', function (array $data): void {
            $this->assertIsInt($data['transaction']);
            $this->begun[] = $data['transaction'];
        });
    }

    public function testTheNumberOfTheOpenTransaction(): void
    {
        $this->assertNull($this->db->currentTransaction(), 'none begun');

        $this->db->beginTransaction();
        $this->assertSame($this->begun[0], $this->db->currentTransaction());
        $this->db->commit();
        $this->assertNull($this->db->currentTransaction(), 'committed');

        $this->db->beginTransaction();
        $this->assertSame($this->begun[1], $this->db->currentTransaction());
        $this->assertNotSame($this->begun[0], $this->begun[1], 'each transaction has its own');
        $this->db->rollback();
        $this->assertNull($this->db->currentTransaction(), 'rolled back');

        $inside = $this->db->transaction(fn (DatabaseInterface $db): ?int => $db->currentTransaction());
        $this->assertSame($this->begun[2], $inside, 'inside transaction()');
        $this->assertNull($this->db->currentTransaction());
    }

    public function testATransactionBegunOnRawPdoHasNoNumber(): void
    {
        $this->db->getPdo()->beginTransaction();
        try {
            $this->assertTrue($this->db->inTransaction());
            $this->assertNull($this->db->currentTransaction());
        } finally {
            $this->db->getPdo()->rollBack();
        }
    }

    /**
     * A transaction whose end was told as 'lost' is no longer the driver's open transaction: here
     * the callback of transaction() committed it on raw PDO.
     */
    public function testATransactionToldAsLostHasEnded(): void
    {
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert('users', ['name' => 'Max']);
                $db->getPdo()->commit();
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome);
        }

        $this->assertNull($this->db->currentTransaction());
    }

    /**
     * 'lost' told while the transaction may still be open - the COMMIT failed, and the ROLLBACK
     * after it too: the driver has told its end, it is no longer its open transaction.
     */
    public function testATransactionToldAsLostWhileItMayStillBeOpenHasEnded(): void
    {
        $db = $this->connect(['pdoClass' => ScenarioPdo::class]);
        $pdo = $db->getPdo();
        $this->assertInstanceOf(ScenarioPdo::class, $pdo);
        $pdo->failCommit = true;
        $pdo->failRollBackAlways = true;

        try {
            $db->transaction(static function (DatabaseInterface $db): void {
                $db->insert('users', ['name' => 'Max']);
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome);
        }

        try {
            $this->assertTrue($pdo->reallyInTransaction(), 'still open on the server');
            $this->assertNull($db->currentTransaction());
        } finally {
            $pdo->failRollBackAlways = false;
            $pdo->rollBack();
        }
    }
}
