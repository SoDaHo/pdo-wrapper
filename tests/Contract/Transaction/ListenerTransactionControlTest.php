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
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;
use Throwable;

/**
 * Transaction control from inside a listener, in three sentences: a transaction.end listener may
 * steer transactions. A query.before, query or error listener may run transaction() or
 * updateMultiple() - which begin and end a transaction of their own inside the listener - when no
 * transaction was open as it was entered, and never beginTransaction(), commit() or rollback(). A
 * transaction.begin, transaction.commit or transaction.rollback listener may do none of it, nor
 * may a listener of a custom event (tests/Unit/Driver/CustomEventListenerTest). Refused calls
 * throw a ListenerTransactionException and do nothing.
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
                    "{$method} was called from inside a {$event} listener",
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
     * The closed forms from a statement listener entered while no transaction was open:
     * transaction() and updateMultiple() run a transaction of their own inside the listener, with
     * its own number and end, before (query.before) or after (query, error) the caller's statement,
     * which runs in autocommit. Committed, the listener's work is there; rolled back, it is gone -
     * and the caller's statement is not taken out with it.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function closedFormsOutsideATransaction(): iterable
    {
        foreach (['query.before', 'query', 'error'] as $event) {
            foreach (['transaction()', 'updateMultiple()'] as $method) {
                foreach ([true, false] as $commits) {
                    yield sprintf('%s: %s %s', $event, $method, $commits ? 'committed' : 'rolled back') => [$event, $method, $commits];
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedFormsOutsideATransaction')]
    public function testAStatementListenerOutsideATransactionRunsTheClosedForms(string $event, string $method, bool $commits): void
    {
        $observer = $this->connect();
        $this->db->insert('ledger', ['id' => 50, 'name' => 'old']);
        $ends = [];
        $this->db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = [$data['outcome'], $data['transaction']];
        });
        $armed = true;
        $openAtEntry = null;
        $this->db->on($event, function () use (&$armed, &$openAtEntry, $method, $commits): void {
            if (!$armed) {
                return; // the listener's own statements tell the event again
            }
            $armed = false;
            $openAtEntry = $this->db->currentTransaction() !== null || $this->db->inTransaction();
            try {
                if ($method === 'transaction()') {
                    $this->db->transaction(static function (DatabaseInterface $db) use ($commits): void {
                        $db->update('ledger', ['name' => 'by the listener'], ['id' => 50]);
                        if (!$commits) {
                            throw new LogicException('the listener\'s transaction fails');
                        }
                    });
                } else {
                    $rows = [['id' => 50, 'name' => 'by the listener']];
                    if (!$commits) {
                        $rows[] = ['id' => 50, 'no_such_column' => 'fails on the server'];
                    }
                    $this->db->updateMultiple('ledger', $rows);
                }
            } catch (LogicException | QueryException) {
                // the listener's transaction was rolled back
            }
        });

        if ($event === 'error') {
            try {
                $this->db->query('SELECT * FROM ledger_missing');
                $this->fail('Expected QueryException');
            } catch (QueryException) {
                // the caller's statement failed in autocommit; the error listener ran
            }
        } else {
            $this->db->insert('ledger', ['id' => 1, 'name' => 'the caller']);
        }

        $this->assertFalse($openAtEntry, 'no transaction was open as the listener was entered');
        $this->assertSame([[$commits ? 'committed' : 'rolled_back', 1]], $ends, 'the listener\'s own transaction, told once');
        $this->assertNull($this->db->currentTransaction());
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame($commits ? 'by the listener' : 'old', $observer->findOne('ledger', ['id' => 50])['name'] ?? null);
        if ($event !== 'error') {
            $this->assertSame('the caller', $observer->findOne('ledger', ['id' => 1])['name'] ?? null, 'the caller\'s statement ran in autocommit, outside the listener\'s transaction');
        }
    }

    /**
     * beginTransaction(), commit() and rollback() stay refused in a statement listener without an
     * open transaction: a transaction begun in query.before and left open would take the caller's
     * statement in, and a rollback in query take it back out. So does the raw control of the
     * callback of a transaction() such a listener runs - it runs inside the listener. The
     * caller's statement goes through in autocommit, nothing of the listener's is there.
     */
    public function testAStatementListenerOutsideATransactionRefusesTheRawControl(): void
    {
        $controls = self::controls();
        unset($controls['transaction()']);
        $controls['commit() in the callback of transaction()'] = static fn (DatabaseInterface $db): mixed => $db->transaction(static function (DatabaseInterface $db): void {
            $db->insert('ledger', ['id' => 99, 'name' => 'by the listener']);
            $db->commit();
        });
        foreach ($controls as $method => $control) {
            foreach (['query.before', 'query', 'error'] as $event) {
                $db = $this->connect();
                $db->delete('ledger', ['id' => 1]);
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

                if ($event === 'error') {
                    try {
                        $db->query('SELECT * FROM ledger_missing');
                    } catch (QueryException) {
                        // the error listener ran
                    }
                } else {
                    $db->insert('ledger', ['id' => 1, 'name' => 'in autocommit']);
                    $this->assertNotNull($db->findOne('ledger', ['id' => 1]), "{$event}: {$method}: the statement went through");
                }

                $this->assertCount(1, $refused, "{$event}: {$method}");
                $e = $refused[0];
                $this->assertInstanceOf(ListenerTransactionException::class, $e, "{$event}: {$method}");
                $this->assertStringStartsWith(
                    (str_starts_with($method, 'commit()') ? 'commit()' : $method) . " was called from inside a {$event} listener of this driver:",
                    (string) $e->getDebugMessage(),
                    "{$event}: {$method}"
                );
                $this->assertNull($db->currentTransaction(), "{$event}: {$method}");
                $this->assertFalse($db->inTransaction(), "{$event}: {$method}");
                $this->assertSame([], $db->table('ledger')->where('name', 'by the listener')->get(), "{$event}: {$method}: nothing the listener tried");
            }
        }
    }

    /**
     * A statement listener entered while PDO cannot tell its state: counted as open, the closed
     * forms are refused (fail-closed); the caller's statement goes through.
     */
    public function testAStateThatCannotBeReadAtTheListenerCountsAsOpen(): void
    {
        $db = $this->connect(['pdoClass' => ScenarioPdo::class]);
        $pdo = $db->getPdo();
        $this->assertInstanceOf(ScenarioPdo::class, $pdo);
        $refused = null;
        $armed = true;
        $db->on('query.before', static function () use ($db, $pdo, &$armed, &$refused): void {
            if (!$armed) {
                return;
            }
            $armed = false;
            $pdo->stateUnreadable = false; // read once, as the listener was entered
            try {
                $db->transaction(static fn (DatabaseInterface $db): int => $db->insert('ledger', ['id' => 99, 'name' => 'by the listener']));
            } catch (ListenerTransactionException $e) {
                $refused = $e;
            }
        });

        $pdo->stateUnreadable = true;
        $db->insert('ledger', ['id' => 1, 'name' => 'the caller']);

        $this->assertInstanceOf(ListenerTransactionException::class, $refused);
        $this->assertStringStartsWith('transaction() was called from inside a query.before listener of this driver entered inside a transaction', (string) $refused->getDebugMessage());
        $this->assertNotNull($db->findOne('ledger', ['id' => 1]));
        $this->assertNull($db->findOne('ledger', ['id' => 99]));
    }

    /**
     * A listener that may not steer refuses for every listener inside it, an end listener included:
     * a query.before listener entered outside a transaction runs transaction() - allowed -, and the
     * end listener of that transaction, running inside the statement listener, may not begin one
     * raw. Were the innermost listener asked alone, that transaction would stay open and take the
     * caller's statement in, in autocommit - and a rollback would take it back out.
     */
    public function testAnEndListenerInsideAStatementListenerCannotBeginRaw(): void
    {
        $db = $this->connect();
        $observer = $this->connect();
        $listenersOwn = null;
        $refused = null;
        $armed = true;
        $db->on('query.before', static function () use ($db, &$armed, &$listenersOwn): void {
            if (!$armed) {
                return; // the listener's own statements tell the event again
            }
            $armed = false;
            $db->transaction(static function (DatabaseInterface $db) use (&$listenersOwn): void {
                $listenersOwn = $db->currentTransaction();
                $db->insert('ledger', ['id' => 100, 'name' => 'by the listener']);
            });
        });
        $db->on('transaction.end', static function (array $data) use ($db, &$listenersOwn, &$refused): void {
            if ($data['transaction'] !== $listenersOwn) {
                return; // armed for the end of the statement listener's own transaction alone
            }
            try {
                $db->beginTransaction();
            } catch (ListenerTransactionException $e) {
                $refused = $e;
            }
        });

        try {
            $db->insert('ledger', ['id' => 1, 'name' => 'the caller']);

            $this->assertInstanceOf(ListenerTransactionException::class, $refused);
            $this->assertStringStartsWith('beginTransaction() was called from inside a query.before listener of this driver:', (string) $refused->getDebugMessage());
            $this->assertNull($db->currentTransaction());
            $this->assertFalse($db->inTransaction());
            $this->assertSame([1, 100], array_column($observer->table('ledger')->orderBy('id')->get(), 'id'), 'the caller\'s statement ran in autocommit, outside any transaction of the listeners');
        } finally {
            if ($db->getPdo()->inTransaction()) {
                $db->getPdo()->rollBack(); // a broken rule leaves the end listener's transaction open: not left for the DROP of the tables
            }
        }
    }

    /**
     * The same from a statement listener entered inside the caller's transaction, which may run no
     * transaction at all: its reconnect(dropTransaction: true) gives the caller's transaction up
     * ('lost'), and the end listener of that 'lost', running inside it, may not run transaction()
     * either - nothing of it is written.
     */
    public function testAnEndListenerInsideAStatementListenerEnteredInATransactionCannotRunOne(): void
    {
        $db = $this->connect();
        $ends = [];
        $refused = null;
        $armed = true;
        $db->on('query', static function () use ($db, &$armed): void {
            if (!$armed) {
                return;
            }
            $armed = false;
            $db->reconnect(dropTransaction: true);
        });
        $db->on('transaction.end', static function (array $data) use ($db, &$ends, &$refused): void {
            $ends[] = $data['outcome'];
            try {
                $db->transaction(static fn (DatabaseInterface $db): int => $db->insert('ledger', ['id' => 99, 'name' => 'by the end listener']));
            } catch (ListenerTransactionException $e) {
                $refused = $e;
            }
        });

        $db->beginTransaction();
        $db->insert('ledger', ['id' => 1, 'name' => 'the caller']); // its query listener reconnects

        $this->assertSame([DatabaseInterface::TRANSACTION_LOST], $ends, 'the caller\'s transaction, given up with the session; no transaction of the end listener');
        $this->assertInstanceOf(ListenerTransactionException::class, $refused);
        $this->assertStringStartsWith('transaction() was called from inside a query listener of this driver entered inside a transaction:', (string) $refused->getDebugMessage());
        $this->assertNull($db->currentTransaction());
        $this->assertFalse($db->inTransaction());
        $this->assertSame([], $db->findAll('ledger'), 'nothing of the end listener, nothing of the caller\'s lost transaction');
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
