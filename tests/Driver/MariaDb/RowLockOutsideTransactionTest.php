<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Closure;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\LockOutsideTransactionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Tests\Contract\TransactionEnd\TransactionEndTestCase;

/**
 * A locking read (lockForUpdate(), sharedLock()) is only a lock inside a transaction: outside of
 * one it would end with its own statement. get(), first(), exists() and the aggregates refuse it
 * there before anything is sent; inside a transaction - the library's or one begun on raw PDO - the
 * lock is taken and holds until the transaction ends.
 */
class RowLockOutsideTransactionTest extends TransactionEndTestCase
{
    /**
     * @return array<string, Closure(QueryBuilder): mixed>
     */
    private static function reads(): array
    {
        return [
            'get' => static fn (QueryBuilder $q): mixed => $q->get(),
            'first' => static fn (QueryBuilder $q): mixed => $q->first(),
            'exists' => static fn (QueryBuilder $q): mixed => $q->exists(),
            'count' => static fn (QueryBuilder $q): mixed => $q->count(),
            'sum' => static fn (QueryBuilder $q): mixed => $q->sum('id'),
            'avg' => static fn (QueryBuilder $q): mixed => $q->avg('id'),
            'min' => static fn (QueryBuilder $q): mixed => $q->min('id'),
            'max' => static fn (QueryBuilder $q): mixed => $q->max('id'),
        ];
    }

    public function testALockingReadOutsideOfATransactionIsRefusedBeforeAnythingIsSent(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $sent = [];
        $this->db->on('query.before', static function (array $data) use (&$sent): void {
            $sent[] = $data['sql'];
        });

        foreach (['lockForUpdate', 'sharedLock'] as $lock) {
            foreach (self::reads() as $name => $read) {
                $query = $this->db->table(self::TABLE)->where('id', 1)->{$lock}();
                try {
                    $read($query);
                    $this->fail("Expected LockOutsideTransactionException: {$lock}()->{$name}");
                } catch (LockOutsideTransactionException $e) {
                    $this->assertSame('Query refused: a row lock outside of a transaction', $e->getMessage());
                    $this->assertStringStartsWith($lock . '() outside of a transaction', (string) $e->getDebugMessage(), $name);
                    $this->assertSame([null, null, null], [$e->sqlState, $e->driverCode, $e->getPrevious()]);
                }
            }
        }

        $this->assertSame([], $sent, 'nothing was sent, no query.before fired');
        $this->assertStringEndsWith(' FOR UPDATE', $this->db->table(self::TABLE)->lockForUpdate()->toSql()[0], 'toSql() renders it all the same');
    }

    /**
     * Inside a transaction the lock is taken - get(), exists() and an aggregate alike - and holds:
     * another connection cannot take the row until the transaction ends.
     */
    public function testInsideATransactionTheLockHolds(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        foreach (['get' => self::reads()['get'], 'exists' => self::reads()['exists'], 'count' => self::reads()['count']] as $name => $read) {
            $this->db->transaction(function (DatabaseInterface $db) use ($read, $name): void {
                $read($db->table(self::TABLE)->where('id', 1)->lockForUpdate());
                try {
                    $this->observer->query('SELECT id FROM ' . self::TABLE . ' WHERE id = 1 FOR UPDATE NOWAIT');
                    $this->fail("Expected QueryException: the row is locked ({$name})");
                } catch (QueryException $e) {
                    $this->assertNotNull($e->driverCode, $name);
                }
            });
        }
        $this->assertSame([['id' => 1]], $this->observer->query('SELECT id FROM ' . self::TABLE . ' WHERE id = 1 FOR UPDATE NOWAIT')->fetchAll(), 'free again once the transaction ended');
    }

    /**
     * A transaction begun on raw PDO is one: the lock is taken.
     */
    public function testATransactionBegunOnRawPdoCounts(): void
    {
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->pdo->beginTransaction();
        $this->assertSame([['id' => 1, 'name' => 'a']], $this->db->table(self::TABLE)->where('id', 1)->sharedLock()->get());
        $this->pdo->rollBack();
    }

    /**
     * A transaction state that cannot be read is no transaction: refused, with what PDO threw as
     * previous.
     */
    public function testAnUnreadableStateIsRefused(): void
    {
        $this->pdo->stateUnreadable = true;
        try {
            $this->db->table(self::TABLE)->lockForUpdate()->first();
            $this->fail('Expected LockOutsideTransactionException');
        } catch (LockOutsideTransactionException $e) {
            $this->assertNotNull($e->getPrevious());
            $this->assertStringContainsString('could not be read', (string) $e->getDebugMessage());
            $this->assertSame([null, null], [$e->sqlState, $e->driverCode]);
        } finally {
            $this->pdo->stateUnreadable = false;
        }
    }

    /**
     * With autocommit switched off and nothing sent yet, PDO reports no transaction although the
     * locking read would open one: refused as well (fail-closed) - begin the transaction explicitly.
     */
    public function testWithAutocommitOffTheReadIsRefusedUntilATransactionIsBegun(): void
    {
        $this->db->execute('SET autocommit = 0');
        try {
            $this->db->table(self::TABLE)->lockForUpdate()->get();
            $this->fail('Expected LockOutsideTransactionException');
        } catch (LockOutsideTransactionException) {
            // refused
        }
        $this->db->beginTransaction();
        $this->assertSame([], $this->db->table(self::TABLE)->lockForUpdate()->get());
        $this->db->rollback();
        $this->db->execute('SET autocommit = 1');
    }
}
