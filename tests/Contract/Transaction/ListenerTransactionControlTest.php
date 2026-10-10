<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Transaction;

use Closure;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\ListenerTransactionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Throwable;

/**
 * No transaction control inside a listener: beginTransaction(), commit(), rollback(), transaction()
 * - and updateMultiple() where it would begin its own transaction - called from inside a listener
 * of the driver, of every event, throw a ListenerTransactionException and do nothing. The operation the listener was told about goes on
 * as if the listener had not called it (here the listener catches the refusal).
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
     * Every event: what is called inside one of its listeners is refused, and the caller's
     * transaction is untouched - it is rolled back at the end, and nothing the listener tried is
     * there.
     */
    public function testEveryEventRefusesEveryTransactionControl(): void
    {
        foreach (self::controls() as $method => $control) {
            foreach (['query.before', 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback', 'transaction.end'] as $event) {
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
                $this->assertStringStartsWith($method === 'transaction()' ? 'beginTransaction()' : $method, (string) $refused[0]->getDebugMessage(), "{$event}: {$method}");
                $this->assertSame([null, null], [$refused[0]->sqlState, $refused[0]->driverCode]);
                $this->assertFalse($db->inTransaction(), "{$event}: {$method}");
                $this->assertSame([], $db->table('ledger')->where('name', 'by the listener')->get(), "{$event}: {$method}: nothing the listener tried");
            }
        }
    }

    /**
     * updateMultiple() opens a transaction of its own when none is open - refused in a listener -,
     * and runs inside the caller's transaction otherwise (no transaction control: it goes through).
     */
    public function testUpdateMultipleIsRefusedWhereItWouldBeginItsOwnTransaction(): void
    {
        $this->db->insert('ledger', ['id' => 1, 'name' => 'before']);
        $refused = null;
        $armed = true;
        $this->db->on('transaction.end', function () use (&$armed, &$refused): void {
            if ($armed) {
                $armed = false;
                try {
                    $this->db->updateMultiple('ledger', [['id' => 1, 'name' => 'by the end listener']]);
                } catch (ListenerTransactionException $e) {
                    $refused = $e;
                }
            }
        });
        $this->db->on('transaction.commit', function (): void {
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
        if ($event === 'transaction.commit' || $event === 'transaction.end') {
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
