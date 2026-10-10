<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\TransactionEnd;

use PDOException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;

/**
 * updateMultiple() begins a transaction of its own only where none is open - and a transaction the
 * library began that ended behind its back (raw PDO, a DDL statement) is still open for it: its rows
 * are refused like any other statement there, instead of a transaction of its own that told the old
 * one 'lost' in passing and left what the caller sent afterwards to autocommit. A state PDO cannot
 * tell is refused before anything is sent.
 */
class UpdateMultipleAfterAnEndTest extends TransactionEndTestCase
{
    /** @var list<mixed> The numbers the transaction.begin listeners were told */
    private array $begun = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'old']);
        $this->begun = [];
        $this->db->on('transaction.begin', function (array $data): void {
            $this->begun[] = $data['transaction'];
        });
    }

    /**
     * Inside transaction(), the callback ends the transaction on raw PDO and goes on: the batch and
     * the insert after it are not sent, no second transaction is begun, the end is told once
     * ('lost', by the commit that finds the transaction gone), and only what the raw COMMIT committed
     * is there.
     */
    public function testInsideTransactionTheBatchIsRefusedAfterAnEndBehindTheLibrarysBack(): void
    {
        $refused = [];
        try {
            $this->db->transaction(function (DatabaseInterface $db) use (&$refused): void {
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'before the raw end']);
                $db->getPdo()->commit();
                foreach ([
                    static fn (): int => $db->updateMultiple(self::TABLE, [['id' => 1, 'name' => 'by the batch']]),
                    static fn (): int => $db->insert(self::TABLE, ['id' => 3, 'name' => 'after the batch']),
                ] as $statement) {
                    try {
                        $statement();
                        $this->fail('Expected QueryException: not sent');
                    } catch (QueryException $e) {
                        $refused[] = $e;
                    }
                }
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
        }

        $this->assertCount(2, $refused);
        foreach ($refused as $e) {
            $this->assertStringStartsWith('Not sent: PDO reports no transaction any more', (string) $e->getDebugMessage());
        }
        $this->assertCount(1, $this->begun, 'no transaction of its own');
        $this->assertSame([self::LOST], array_column($this->ends, 'outcome'));
        $this->assertSame([1, 2], array_column($this->rows(), 'id'), 'the raw COMMIT committed row 2; row 3 was never sent');
        $this->assertSame('old', $this->rows()[0]['name'] ?? null, 'the batch was never sent');
        $this->assertVisible([1, 2]);
    }

    /**
     * A manual transaction ended on raw PDO: the batch is refused, the library still holds the
     * transaction (currentTransaction() its number), and rollback() ends it as 'lost'.
     */
    public function testInAManualTransactionTheBatchIsRefusedAndRollbackEndsIt(): void
    {
        $this->db->beginTransaction();
        $number = $this->db->currentTransaction();
        $this->db->getPdo()->commit();

        try {
            $this->db->updateMultiple(self::TABLE, [['id' => 1, 'name' => 'by the batch']]);
            $this->fail('Expected QueryException: not sent');
        } catch (QueryException $e) {
            $this->assertStringStartsWith('Not sent: PDO reports no transaction any more', (string) $e->getDebugMessage());
        }
        $this->assertSame($number, $this->db->currentTransaction());
        $this->assertSame([$number], $this->begun, 'no transaction of its own');
        $this->assertSame([], $this->ends);

        $this->db->rollback();
        $this->assertSame([self::LOST], array_column($this->ends, 'outcome'));
        $this->assertSame('old', $this->rows()[0]['name'] ?? null);
        $this->assertVisible([1]);
    }

    /**
     * Without a transaction of the library, a state PDO cannot tell: the rows would run in a
     * transaction nobody can confirm, or in one of its own over one that is open - refused with a
     * TransactionException, PDO's exception as previous, nothing sent, nothing begun. The refusal
     * carries no codes: those of PDO's exception (a lost connection, 2006) are not the batch's -
     * a caller that reconnects or retries on 2006 would take a refusal for a database failure.
     */
    public function testAStateThatCannotBeReadIsRefusedBeforeAnythingIsSent(): void
    {
        $sent = [];
        $this->db->on('query.before', static function (array $data) use (&$sent): void {
            $sent[] = $data['sql'];
        });
        $this->pdo->stateUnreadable = true;
        $this->pdo->stateFailureInfo = ['HY000', 2006, 'MySQL server has gone away'];
        try {
            $this->db->updateMultiple(self::TABLE, [['id' => 1, 'name' => 'by the batch']]);
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Connection state unknown', $e->getMessage());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
            $this->assertSame('state unreadable (scenario)', $e->getPrevious()->getMessage());
            $this->assertSame(['HY000', 2006], [$e->getPrevious()->errorInfo[0] ?? null, $e->getPrevious()->errorInfo[1] ?? null], 'PDO\'s exception keeps its codes');
            $this->assertSame([null, null], [$e->sqlState, $e->driverCode], 'the refusal carries none');
        } finally {
            $this->pdo->stateUnreadable = false;
        }

        $this->assertSame([], $sent, 'nothing was sent');
        $this->assertSame([], $this->begun, 'nothing was begun');
        $this->assertNull($this->db->currentTransaction());
        $this->assertSame('old', $this->rows()[0]['name'] ?? null);
        $this->assertVisible([1]);
    }
}
