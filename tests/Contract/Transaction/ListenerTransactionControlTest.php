<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Transaction;

use Closure;
use LogicException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\ListenerTransactionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Throwable;

/**
 * No transaction control inside a listener that runs in the middle of a transaction:
 * beginTransaction(), commit(), rollback(), transaction() - and updateMultiple() where it would
 * begin its own transaction - called from inside a transaction.begin, transaction.commit or
 * transaction.rollback listener, or a statement listener entered inside a transaction, throw a
 * ListenerTransactionException and do nothing. A transaction.end listener and a statement listener
 * outside of a transaction run a transaction of their own.
 */
class ListenerTransactionControlTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create('ledger', ['id' => 'key', 'name' => 'text']);
    }

    /**
     * @return array<non-empty-string, Closure(DatabaseInterface): mixed>
     */
    private static function controls(): array
    {
        return [
            'beginTransaction()' => static function (DatabaseInterface $db): mixed {
                $db->beginTransaction();

                return null;
            },
            'commit()' => static function (DatabaseInterface $db): mixed {
                $db->commit();

                return null;
            },
            'rollback()' => static function (DatabaseInterface $db): mixed {
                $db->rollback();

                return null;
            },
            'transaction()' => static fn (DatabaseInterface $db): mixed => $db->transaction(static fn (DatabaseInterface $db): int => $db->insert('ledger', ['id' => 99, 'name' => 'by the listener'])),
        ];
    }

    /**
     * Every listener that runs in the middle of the caller's transaction: what is called inside it
     * is refused, and the caller's transaction is untouched - it is rolled back at the end, and
     * nothing the listener tried is there.
     */
    public function testEveryListenerInsideATransactionRefusesEveryTransactionControl(): void
    {
        foreach (self::controls() as $method => $control) {
            foreach (['query.before', 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback'] as $event) {
                $db = $this->connect();
                $refused = [];
                $armed = true;
                $db->on($event, static function () use ($db, $control, &$armed, &$refused): void {
                    if (!$armed) {
                        return;
                    }
                    $armed = false;
                    try {
                        $control($db);
                    } catch (Throwable $e) {
                        $refused[] = $e;
                    }
                });

                $this->runThrough($db, $event);

                $this->assertCount(1, $refused, "{$event}: {$method}");
                $this->assertInstanceOf(ListenerTransactionException::class, $refused[0], "{$event}: {$method}");
                $this->assertSame('Transaction control refused inside a listener', $refused[0]->getMessage());
                $this->assertStringStartsWith(
                    ($method === 'transaction()' ? 'beginTransaction()' : $method) . " was called from inside a {$event} listener",
                    (string) $refused[0]->getDebugMessage(),
                    "{$event}: {$method}"
                );
                $this->assertSame([null, null], [$refused[0]->sqlState, $refused[0]->driverCode]);
                $this->assertFalse($db->inTransaction(), "{$event}: {$method}");
                $this->assertSame([], $db->table('ledger')->where('name', 'by the listener')->get(), "{$event}: {$method}: nothing the listener tried");
            }
        }
    }

    /**
     * A transaction.end listener runs once the transaction has ended: it starts and commits a
     * transaction of its own, which gets its own number and its own end, inside the listener; the
     * outer outcome stays what it was - committed and rolled back alike.
     */
    public function testAnEndListenerRunsATransactionOfItsOwn(): void
    {
        foreach (['commit' => 'committed', 'rollback' => 'rolled_back'] as $end => $outcome) {
            $db = $this->connect();
            $db->delete('ledger', ['id' => 99]);
            $told = [];
            $armed = true;
            $db->on('transaction.end', static function (array $data) use ($db, &$told, &$armed): void {
                $told[] = [$data['outcome'], $data['transaction'], $data['depth']];
                if ($armed) {
                    $armed = false;
                    $db->transaction(static fn (DatabaseInterface $db): int => $db->insert('ledger', ['id' => 99, 'name' => 'by the end listener']));
                }
            });

            $db->beginTransaction();
            $outer = $db->currentTransaction();
            $db->insert('ledger', ['id' => 1, 'name' => 'the caller']);
            if ($end === 'commit') {
                $db->commit();
            } else {
                $db->rollback();
            }

            $this->assertIsInt($outer);
            $this->assertSame([[$outcome, $outer, 1], ['committed', $outer + 1, 1]], $told, "{$end}: the outer end, then the listener's own (told inside the listener)");
            $this->assertSame('by the end listener', $db->findOne('ledger', ['id' => 99])['name'] ?? null, $end);
            $this->assertSame($end === 'commit', $db->findOne('ledger', ['id' => 1]) !== null, "{$end}: the caller's transaction ended as it did");
            $this->assertNull($db->currentTransaction(), $end);
            $db->delete('ledger', ['id' => 1]);
        }
    }

    /**
     * The counterpart: commit() from a transaction.commit listener is still refused - and joins
     * the CommitHookException of the caller's commit, whose data is committed.
     */
    public function testACommitListenerStillCannotCommit(): void
    {
        $armed = true;
        $this->db->on('transaction.commit', function () use (&$armed): void {
            if ($armed) {
                $armed = false;
                $this->db->commit();
            }
        });

        try {
            $this->db->transaction(static fn (DatabaseInterface $db): int => $db->insert('ledger', ['id' => 1, 'name' => 'the caller']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $refusal = $e->getPrevious();
            $this->assertInstanceOf(ListenerTransactionException::class, $refusal);
            $this->assertStringStartsWith('commit() was called from inside a transaction.commit listener', (string) $refusal->getDebugMessage());
        }
        $this->assertNotNull($this->db->findOne('ledger', ['id' => 1]));
    }

    /**
     * A statement listener entered outside of a transaction runs a transaction of its own (here an
     * audit row for a statement in autocommit); the same listener, entered for the audit insert
     * inside that transaction, is refused.
     */
    public function testAStatementListenerOutsideATransactionRunsOneOfItsOwn(): void
    {
        $refused = null;
        $this->db->on('query', function (array $data) use (&$refused): void {
            if ($data['params'] === [1, 'in autocommit']) {
                $this->db->transaction(static fn (DatabaseInterface $db): int => $db->insert('ledger', ['id' => 2, 'name' => 'audit']));
            } elseif ($data['params'] === [2, 'audit']) {
                try {
                    $this->db->commit();
                } catch (ListenerTransactionException $e) {
                    $refused = $e;
                }
            }
        });

        $this->db->insert('ledger', ['id' => 1, 'name' => 'in autocommit']);

        $this->assertSame(['in autocommit', 'audit'], array_column($this->db->table('ledger')->orderBy('id')->get(), 'name'));
        $this->assertInstanceOf(ListenerTransactionException::class, $refused, "the audit insert ran inside the listener's transaction");
        $this->assertStringStartsWith('commit() was called from inside a query listener of this driver entered inside a transaction', (string) $refused->getDebugMessage());
    }

    /**
     * transaction.end listeners that each begin a transaction whose end runs them again are
     * stopped after 32 levels: beginTransaction() throws a LogicException there, which reaches each
     * enclosing transaction() as its end listener's failure.
     */
    public function testEndListenersThatKeepBeginningAreStoppedAfter32Levels(): void
    {
        $levels = 0;
        $this->db->on('transaction.end', function () use (&$levels): void {
            $levels++;
            $this->db->transaction(static fn (): null => null);
        });

        try {
            $this->db->transaction(static fn (): null => null);
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $cause = $e;
            while ($cause instanceof CommitHookException) {
                $cause = $cause->getPrevious();
            }
            $this->assertInstanceOf(LogicException::class, $cause);
            $this->assertStringStartsWith('Hook recursion: 32 transaction.end listeners', $cause->getMessage());
        }
        $this->assertSame(32, $levels, 'the outer end and 31 inner ones were told; the begin of the 32nd listener, one inside the other, was refused');
        $this->assertNull($this->db->currentTransaction());
        $this->assertFalse($this->db->inTransaction());
    }

    /**
     * updateMultiple() opens a transaction of its own when none is open - refused in a commit
     * listener -, and runs inside an open transaction otherwise (no transaction control: it goes
     * through).
     */
    public function testUpdateMultipleIsRefusedWhereItWouldBeginItsOwnTransaction(): void
    {
        $this->db->insert('ledger', ['id' => 1, 'name' => 'before']);
        $refused = null;
        $armed = true;
        $this->db->on('transaction.commit', function () use (&$armed, &$refused): void {
            if (!$armed) {
                return;
            }
            $armed = false;
            try {
                $this->db->updateMultiple('ledger', [['id' => 1, 'name' => 'refused']]);
            } catch (ListenerTransactionException $e) {
                $refused = $e;
            }
            $this->db->getPdo()->beginTransaction(); // an open transaction: the batch runs inside it
            $this->db->updateMultiple('ledger', [['id' => 1, 'name' => 'by the commit listener']]);
            $this->db->getPdo()->commit();
        });

        $this->db->transaction(static fn (): null => null);

        $this->assertInstanceOf(ListenerTransactionException::class, $refused);
        $this->assertSame('by the commit listener', $this->db->findOne('ledger', ['id' => 1])['name'] ?? null);
    }

    /**
     * The event, inside a transaction of the caller that ends rolled back where the event allows -
     * so that a commit or rollback the listener managed would show.
     */
    private function runThrough(DatabaseInterface $db, string $event): void
    {
        $db->beginTransaction();
        match ($event) {
            'query.before', 'query' => $db->insert('ledger', ['id' => 1, 'name' => 'the caller']),
            'error' => $this->failInside($db),
            default => null,
        };
        if ($event === 'transaction.commit') {
            $db->commit();
            $db->delete('ledger', ['id' => 1]);

            return;
        }
        $this->assertTrue($db->inTransaction(), "{$event}: the caller's transaction is still open");
        $db->rollback();
        $this->assertSame([], $db->table('ledger')->get(), "{$event}: the caller's transaction was rolled back, not committed by the listener");
    }

    private function failInside(DatabaseInterface $db): void
    {
        $db->insert('ledger', ['id' => 1, 'name' => 'the caller']);
        try {
            $db->query('SELECT * FROM ledger_missing');
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // the error listener ran
        }
    }
}
