<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\TransactionEnd;

use ArrayObject;
use LogicException;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\TransactionException;

/**
 * A transaction.end listener runs once the transaction has ended and may run a transaction of its
 * own through the driver: it is not nested in the one that ended, its end is told inside the
 * listener, a rollback() it calls is explicit, and beginTransaction() tells the end of such a
 * transaction that ended behind the library's back before the next one begins - bounded at two.
 */
class EndListenerTransactionScenariosTest extends TransactionEndTestCase
{
    public function testATransactionStartedInAnEndListenerEndsInsideThatListener(): void
    {
        $depth = 0;
        $started = false;
        $this->db->on('transaction.end', function () use (&$depth, &$started): void {
            $this->events[] = 'L1@' . $depth;
            if ($started) {
                return;
            }
            $started = true;
            $depth = 1;
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'inner']);
            });
            $depth = 0;
            $this->events[] = 'L1-done';
        });
        $this->db->on('transaction.end', function () use (&$depth): void {
            $this->events[] = 'L2@' . $depth;
        });

        $this->db->transaction(static function (DatabaseInterface $db): void {
            $db->insert(self::TABLE, ['id' => 1, 'name' => 'outer']);
        });

        $this->assertSame(['commit', 'end', 'L1@0', 'commit', 'end', 'L1@1', 'L2@1', 'L1-done', 'L2@0'], $this->events);
        $this->assertVisible([1, 2]);
    }

    /**
     * An end listener runs once the end is told: a transaction it runs is not nested in the one
     * that ended (depth 1, the next number).
     */
    public function testATransactionAnEndListenerRunsIsNotNested(): void
    {
        /** @var ArrayObject<int, string> $seen */
        $seen = new ArrayObject();
        foreach (['transaction.begin' => 'begin', 'transaction.commit' => 'commit'] as $event => $name) {
            $this->db->on($event, static function (array $data) use ($seen, $name): void {
                $seen[] = sprintf('%s %s/%s', $name, var_export($data['transaction'], true), var_export($data['depth'], true));
            });
        }
        $this->db->on('transaction.end', static function (array $data) use ($seen): void {
            $seen[] = sprintf('end %s %s/%s', var_export($data['outcome'], true), var_export($data['transaction'], true), var_export($data['depth'], true));
        });
        $once = true;
        $this->db->on('transaction.end', function () use (&$once): void {
            if ($once) {
                $once = false;
                $this->db->transaction(static fn (): null => null);
            }
        });

        $this->db->transaction(static fn (): null => null);

        $this->assertSame([
            'begin 1/1', 'commit 1/1', "end 'committed' 1/1",
            'begin 2/1', 'commit 2/1', "end 'committed' 2/1",
        ], $seen->getArrayCopy());
    }

    /**
     * During the automatic rollback an end listener starts a transaction and rolls it back itself:
     * that rollback() is explicit (error null) and reports its end listeners' failures as
     * TransactionException to the listener, not swallowed like the outer automatic one.
     */
    public function testAnExplicitRollbackInsideAnEndListenerDuringTheAutomaticRollbackIsExplicit(): void
    {
        $cause = new RuntimeException('domain error');
        $innerFailure = new LogicException('inner end listener failed');
        $state = new class () {
            public int $depth = 0;
        };
        $started = false;
        $innerException = null;
        $this->db->on('transaction.end', function () use ($state, &$started, &$innerException): void {
            if ($started) {
                return;
            }
            $started = true;
            $state->depth = 1;
            $this->db->beginTransaction();
            try {
                $this->db->rollback();
            } catch (TransactionException $e) {
                $innerException = $e;
            }
            $state->depth = 0;
        });
        $this->db->on('transaction.end', static function () use ($state, $innerFailure): void {
            if ($state->depth === 1) {
                throw $innerFailure;
            }
        });

        try {
            $this->db->transaction(static function () use ($cause): void {
                throw $cause;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e, 'the outer cause reaches the caller unchanged');
        }

        $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $cause], ['outcome' => self::ROLLED_BACK, 'error' => null]], $this->ends, 'outer automatic, inner explicit');
        $this->assertInstanceOf(TransactionException::class, $innerException);
        $this->assertSame($innerFailure, $innerException->getPrevious(), 'the inner end failure reached the listener as TransactionException');
        $this->assertSame([$innerFailure], array_column($this->errors, 'exception'), 'and the error hook');
    }

    /**
     * beginTransaction() tells the end of a transaction that ended behind the library's back. An
     * end listener that answers with a transaction of its own which ends the same way gets that
     * end told too, before the new transaction begins - not buried.
     */
    public function testAnEndListenersTransactionThatEndsOutsideTheLibraryIsToldBeforeTheNextBegins(): void
    {
        $this->endOnRawPdoInAnEndListener(times: 1);

        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $this->pdo->commit(); // behind the library's back

        $this->db->beginTransaction();
        $this->assertSame(['end', 'end'], $this->events, "the first transaction, then the listener's");
        $this->assertSame([self::LOST, self::LOST], array_column($this->ends, 'outcome'));
        $this->assertTrue($this->pdo->reallyInTransaction());

        $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'b']);
        $this->db->commit();
        $this->assertSame(['end', 'end', 'commit', 'end'], $this->events);
        $this->assertVisible([1, 2]);
    }

    /**
     * Listeners that answer every such end with another transaction of that kind do not keep
     * beginTransaction() in a loop: after two ends it throws, begins nothing, and the next call
     * tells the end that is still owed.
     */
    public function testEndListenersThatKeepLeavingSuchATransactionBehindStopTheBegin(): void
    {
        $left = $this->endOnRawPdoInAnEndListener(times: 5);

        $this->db->beginTransaction();
        $this->pdo->commit();

        try {
            $this->db->beginTransaction();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to begin transaction', $e->getMessage());
            $this->assertStringStartsWith('Not begun: the transaction.end listeners keep leaving behind', (string) $e->getDebugMessage());
        }
        $this->assertSame([self::LOST, self::LOST], array_column($this->ends, 'outcome'), 'two ends told, the third transaction still owes its');
        $this->assertFalse($this->pdo->reallyInTransaction(), 'nothing was begun');

        $left->times = 0;
        $this->db->beginTransaction();
        $this->assertSame([self::LOST, self::LOST, self::LOST], array_column($this->ends, 'outcome'));
        $this->db->rollback();
        $this->assertSame(['end', 'end', 'end', 'rollback', 'end'], $this->events);
        $this->assertVisible([]);
    }

    /**
     * An end listener runs a transaction on raw PDO, its commit through the library fails, the
     * listener catches that and rolls back raw. Thrown later by the callback, that exception is no
     * failed commit of the callback's transaction: no outcome - whichever way the transaction
     * before it ended.
     */
    public function testAFailedCommitInsideAnEndListenerGetsNoOutcomeFromALaterEnd(): void
    {
        $state = new class () {
            public bool $armed = false;
        };
        $caught = null;
        $this->db->on('transaction.end', function () use ($state, &$caught): void {
            if (!$state->armed) {
                return;
            }
            $state->armed = false;
            $this->pdo->hideTransaction = false;
            if ($this->pdo->reallyInTransaction()) {
                $this->pdo->rollBack(); // what the 'lost' simulation left open
            }
            $this->pdo->beginTransaction();
            $this->pdo->failCommit = true;
            $this->pdo->vanishOnFailedCommit = false; // this one stays open: the failed commit tells nothing
            try {
                $this->db->commit();
            } catch (CommitFailedException $e) {
                $caught = $e;
            }
            $this->pdo->rollBack();
        });

        $endings = [
            'after a commit' => static fn (DatabaseInterface $db) => $db->commit(),
            'after a rollback' => static fn (DatabaseInterface $db) => $db->rollback(),
            'after a lost end' => function (DatabaseInterface $db): void {
                $this->pdo->failCommit = true;
                $this->pdo->vanishOnFailedCommit = true;
                try {
                    $db->commit();
                } catch (CommitFailedException) {
                    // told as lost; the listener ran
                }
            },
        ];

        foreach ($endings as $how => $end) {
            $caught = null;
            try {
                $this->db->transaction(function (DatabaseInterface $db) use ($end, $state, &$caught): void {
                    $state->armed = true;
                    $end($db);
                    $this->assertInstanceOf(CommitFailedException::class, $caught);
                    throw $caught;
                });
                $this->fail('Expected CommitFailedException: ' . $how);
            } catch (CommitFailedException $e) {
                $this->assertSame($caught, $e, $how);
                $this->assertNull($e->outcome, $how);
            }
            $this->assertFalse($this->pdo->reallyInTransaction(), $how);
        }
    }

    /**
     * An end listener that answers a 'lost' with a transaction of its own and ends it on raw PDO,
     * as often as the returned object's $times says.
     */
    private function endOnRawPdoInAnEndListener(int $times): \stdClass
    {
        $left = new \stdClass();
        $left->times = $times;
        $this->db->on('transaction.end', function (array $data) use ($left): void {
            if ($data['outcome'] === self::LOST && $left->times > 0) {
                $left->times--;
                $this->db->beginTransaction();
                $this->pdo->commit();
            }
        });

        return $left;
    }
}
