<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Transaction;

use Error;
use Exception;
use LogicException;
use PDO;
use PDOException;
use ReflectionMethod;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\ListenerTransactionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;
use Throwable;

/**
 * Transactions as every driver must carry them out: manual and callback transactions, the
 * transaction.* events and their order, what a throwing listener or callback leaves behind, and the
 * refusals (nesting, a commit without a transaction) - through DatabaseInterface and the binding alone.
 */
class TransactionTest extends ContractTestCase
{
    /** The connection of scenarioDriver(): its rollBack() calls are counted, its commit() and rollBack() can be made to fail */
    private ScenarioPdo $scenarioPdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->create('users', ['id' => 'id', 'name' => 'text']);
    }

    /**
     * The scenario connection may be left in a transaction that holds a lock on the tables (a
     * rollback made to fail): it is closed here, before they are dropped. So is a driver that only
     * a reference cycle keeps alive - a listener that uses its own driver -: the cycle collector
     * frees it now instead of some tests later (measured: otherwise every such test leaves one
     * connection open).
     */
    protected function closeConnections(): void
    {
        unset($this->scenarioPdo);
        gc_collect_cycles();
    }

    /**
     * What the library tells about a transaction is decided in these three methods: a custom
     * driver cannot override them (it has listeners and the protected hooks instead).
     */
    public function testTheTransactionMethodsOfTheBaseDriverAreFinal(): void
    {
        foreach (['beginTransaction', 'commit', 'rollback'] as $method) {
            $this->assertTrue(new ReflectionMethod(AbstractDriver::class, $method)->isFinal(), $method);
        }
    }

    public function testManualTransactionCommit(): void
    {
        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);
        $this->db->commit();

        $stmt = $this->db->query('SELECT * FROM users');
        /** @var list<array<string, mixed>> $users */
        $users = $stmt->fetchAll();

        $this->assertCount(1, $users);
        $this->assertSame('Max', $users[0]['name']);
    }

    public function testManualTransactionRollback(): void
    {
        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);
        $this->db->rollback();

        $stmt = $this->db->query('SELECT * FROM users');
        $users = $stmt->fetchAll();

        $this->assertCount(0, $users);
    }

    public function testTransactionCallbackCommitsOnSuccess(): void
    {
        $result = $this->db->transaction(function (DatabaseInterface $db) {
            $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);
            $db->execute('INSERT INTO users (name) VALUES (?)', ['Anna']);
            return 'success';
        });

        $this->assertSame('success', $result);

        $stmt = $this->db->query('SELECT * FROM users');
        $users = $stmt->fetchAll();

        $this->assertCount(2, $users);
    }

    public function testTransactionCallbackRollsBackOnException(): void
    {
        try {
            $this->db->transaction(function (DatabaseInterface $db) {
                $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);
                throw new Exception('Something went wrong');
            });
        } catch (Exception $e) {
            $this->assertSame('Something went wrong', $e->getMessage());
        }

        $stmt = $this->db->query('SELECT * FROM users');
        $users = $stmt->fetchAll();

        $this->assertCount(0, $users);
    }

    public function testTransactionHooksAreTriggered(): void
    {
        $events = [];

        $this->db->on('transaction.begin', function () use (&$events) {
            $events[] = 'begin';
        });

        $this->db->on('transaction.commit', function () use (&$events) {
            $events[] = 'commit';
        });

        $this->db->transaction(function (DatabaseInterface $db) {
            $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);
        });

        $this->assertSame(['begin', 'commit'], $events);
    }

    public function testTransactionRollbackHookIsTriggered(): void
    {
        $rollbackTriggered = false;

        $this->db->on('transaction.rollback', function () use (&$rollbackTriggered) {
            $rollbackTriggered = true;
        });

        try {
            $this->db->transaction(function () {
                throw new Exception('Fail');
            });
        } catch (Exception $e) {
            // Expected
        }

        $this->assertTrue($rollbackTriggered);
    }

    /**
     * Regression test: a throwing 'transaction.begin' hook left the transaction it was told about open.
     * It is now rolled back on raw PDO, without 'transaction.rollback' hooks, and the hook's exception
     * reaches the caller unchanged.
     */
    public function testThrowingBeginHookRollsBackTheOpenedTransaction(): void
    {
        $rollbackHookCalls = 0;
        // A writing listener before the throwing one: its row must be gone afterwards (rollback, not commit).
        $this->db->on('transaction.begin', function (): void {
            $this->db->execute('INSERT INTO users (name) VALUES (?)', ['from-hook']);
        });
        $this->db->on('transaction.begin', function (): void {
            throw new RuntimeException('begin hook failed');
        });
        $this->db->on('transaction.rollback', function () use (&$rollbackHookCalls): void {
            $rollbackHookCalls++;
        });

        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);
            });
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('begin hook failed', $e->getMessage());
        }

        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertSame(0, $rollbackHookCalls);
        $this->assertSame(0, $this->userCount($this->db));
    }

    /**
     * The quiet rollback after a throwing 'transaction.begin' hook is best effort: when it throws
     * itself, the hook's exception still reaches the caller and the transaction stays open.
     */
    public function testFailingQuietRollbackAfterAThrowingBeginHookKeepsTheHookException(): void
    {
        $db = $this->scenarioDriver();
        $pdo = $this->scenarioPdo;
        $pdo->failRollBackAlways = true;
        $db->on('transaction.begin', static function (): void {
            throw new RuntimeException('begin hook failed');
        });

        try {
            $db->beginTransaction();
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('begin hook failed', $e->getMessage());
        }

        $this->assertSame(1, $pdo->rollBackCalls);
        $this->assertTrue($pdo->inTransaction(), 'the failed rollback left the transaction open');
    }

    public function testBeginHookPdoExceptionIsStillATransactionExceptionAndRollsBack(): void
    {
        $this->db->on('transaction.begin', function (): void {
            $this->db->execute('INSERT INTO users (name) VALUES (?)', ['from-hook']);
        });
        $this->db->on('transaction.begin', function (): void {
            throw new PDOException('begin hook pdo failure');
        });

        try {
            $this->db->beginTransaction();
            $this->fail('Expected TransactionException was not thrown');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to begin transaction', $e->getMessage());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
        }

        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertSame(0, $this->userCount($this->db));
    }

    public function testBeginTransactionReturningFalseIsATransactionException(): void
    {
        $db = $this->falseReturningDriver('begin');
        $events = [];
        $db->on('transaction.begin', static function () use (&$events): void {
            $events[] = 'begin';
        });

        try {
            $db->beginTransaction();
            $this->fail('Expected TransactionException was not thrown');
        } catch (TransactionException $e) {
            $this->assertSame('PDO::beginTransaction() returned false', $e->getDebugMessage());
        }

        $this->assertSame([], $events);
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    public function testTransactionDoesNotRunTheCallbackWhenBeginReturnsFalse(): void
    {
        $db = $this->falseReturningDriver('begin');
        $callbackRan = false;

        try {
            $db->transaction(static function () use (&$callbackRan): void {
                $callbackRan = true;
            });
            $this->fail('Expected TransactionException was not thrown');
        } catch (TransactionException $e) {
            $this->assertSame('PDO::beginTransaction() returned false', $e->getDebugMessage());
        }

        $this->assertFalse($callbackRan);
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    public function testRollbackHookPdoExceptionIsStillATransactionException(): void
    {
        $this->db->on('transaction.rollback', static function (): void {
            throw new PDOException('rollback hook pdo failure');
        });
        $this->db->beginTransaction();

        try {
            $this->db->rollback();
            $this->fail('Expected TransactionException was not thrown');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to rollback transaction', $e->getMessage());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
        }

        $this->assertFalse($this->db->getPdo()->inTransaction());
    }

    public function testRollbackReturningFalseIsATransactionException(): void
    {
        $db = $this->falseReturningDriver('rollback');
        $events = [];
        $db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $db->beginTransaction();

        try {
            $db->rollback();
            $this->fail('Expected TransactionException was not thrown');
        } catch (TransactionException $e) {
            $this->assertSame('PDO::rollBack() returned false', $e->getDebugMessage());
        }

        $this->assertSame([], $events);
    }

    public function testConnectionIsUsableAfterThrowingBeginHook(): void
    {
        $attempts = 0;
        $this->db->on('transaction.begin', function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw new RuntimeException('begin hook failed once');
            }
        });

        try {
            $this->db->beginTransaction();
        } catch (RuntimeException) {
            // Expected on the first attempt
        }

        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);
        $this->db->commit();

        $this->assertSame(2, $attempts);
        $this->assertSame(1, $this->userCount($this->db));
    }

    public function testInTransactionReflectsTheConnectionState(): void
    {
        $this->assertFalse($this->db->inTransaction());

        $this->db->beginTransaction();
        $this->assertTrue($this->db->inTransaction());
        $this->db->commit();
        $this->assertFalse($this->db->inTransaction());

        $this->db->beginTransaction();
        $this->db->rollback();
        $this->assertFalse($this->db->inTransaction());

        $this->db->transaction(function (DatabaseInterface $db): void {
            $this->assertTrue($db->inTransaction());
        });
        $this->assertFalse($this->db->inTransaction());
    }

    /**
     * Regression test: updateMultiple must rollback all changes on failure.
     *
     * Previously, updateMultiple had no transaction wrapper, causing partial
     * updates when an error occurred mid-batch (e.g., 49/100 rows updated).
     */
    public function testUpdateMultipleRollsBackOnFailure(): void
    {
        // Insert test data
        $this->db->insert('users', ['id' => 1, 'name' => 'Max']);
        $this->db->insert('users', ['id' => 2, 'name' => 'Anna']);
        $this->db->insert('users', ['id' => 3, 'name' => 'Tom']);

        // Try to update with one row missing the key column (will fail)
        try {
            $this->db->updateMultiple('users', [
                ['id' => 1, 'name' => 'Max Updated'],
                ['id' => 2, 'name' => 'Anna Updated'],
                ['name' => 'Tom Updated'], // Missing 'id' - will throw
            ]);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            // Expected
        }

        // All rows should be unchanged (rollback)
        $users = $this->db->findAll('users');
        $this->assertSame('Max', $users[0]['name']);
        $this->assertSame('Anna', $users[1]['name']);
        $this->assertSame('Tom', $users[2]['name']);
    }

    public function testUpdateMultipleCommitsOnSuccess(): void
    {
        // Insert test data
        $this->db->insert('users', ['id' => 1, 'name' => 'Max']);
        $this->db->insert('users', ['id' => 2, 'name' => 'Anna']);

        // Update all rows
        $affected = $this->db->updateMultiple('users', [
            ['id' => 1, 'name' => 'Max Updated'],
            ['id' => 2, 'name' => 'Anna Updated'],
        ]);

        $this->assertSame(2, $affected);

        // All rows should be updated
        $users = $this->db->findAll('users');
        $this->assertSame('Max Updated', $users[0]['name']);
        $this->assertSame('Anna Updated', $users[1]['name']);
    }

    public function testUpdateMultipleRespectsExistingTransaction(): void
    {
        // Insert test data
        $this->db->insert('users', ['id' => 1, 'name' => 'Max']);

        // Start our own transaction
        $this->db->beginTransaction();

        // updateMultiple should not start its own transaction
        $this->db->updateMultiple('users', [
            ['id' => 1, 'name' => 'Max Updated'],
        ]);

        // Rollback our transaction - the update should be undone
        $this->db->rollback();

        $user = $this->db->findOne('users', ['id' => 1]);
        $this->assertNotNull($user);
        $this->assertSame('Max', $user['name']);
    }

    // =========================================================================
    // Rollback Failure Tests
    // These test that the original exception is preserved when rollback itself
    // fails (e.g., due to connection loss). This is defensive code coverage.
    // =========================================================================

    /**
     * transaction() preserves the original exception when the rollback fails (a lost connection):
     * the callback's exception is re-thrown, not the rollback failure.
     */
    public function testTransactionPreservesOriginalExceptionWhenRollbackFails(): void
    {
        $failingDb = $this->scenarioDriver();
        $this->scenarioPdo->failRollBackAlways = true;

        try {
            $failingDb->transaction(function (DatabaseInterface $driver): void {
                $driver->execute('INSERT INTO users (id) VALUES (1)');
                throw new RuntimeException('Original error');
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame('Original error', $e->getMessage());
        }

        $this->assertSame(1, $this->scenarioPdo->rollBackCalls, 'the rollback was tried');
    }

    /**
     * updateMultiple() preserves the original exception when the rollback fails (a lost
     * connection): the QueryException of the batch is re-thrown, not the rollback failure.
     */
    public function testUpdateMultiplePreservesOriginalExceptionWhenRollbackFails(): void
    {
        $failingDb = $this->scenarioDriver();
        $failingDb->insert('users', ['id' => 1, 'name' => 'Original']);
        $this->scenarioPdo->failRollBackAlways = true;

        try {
            $failingDb->updateMultiple('users', [
                ['id' => 1, 'name' => 'Updated'],
                ['id' => 1, 'no_such_column' => 'x'], // fails on the server, after the first update
            ]);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
        }

        $this->assertSame(1, $this->scenarioPdo->rollBackCalls, 'the rollback was tried');
    }

    // =========================================================================
    // Commit Phase Tests
    // A transaction.commit listener runs after the commit: its failure must not
    // look like a failed commit, and nothing committed may be rolled back.
    // =========================================================================

    public function testThrowingCommitHookKeepsCommittedDataWithoutRollback(): void
    {
        $db = $this->scenarioDriver();
        $events = [];
        $hookError = new RuntimeException('hook failed');
        $db->on('transaction.commit', static fn () => throw $hookError);
        $db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });

        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertSame($hookError, $e->getPrevious());
            $this->assertSame([$hookError], $e->failures);
            $this->assertFalse($e->connectionInTransaction);
        }

        $this->assertSame(1, $this->userCount($db));
        $this->assertSame([], $events);
        $this->assertSame(0, $this->scenarioPdo->rollBackCalls);
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    public function testCallbackErrorRollsBackWithoutCommitHook(): void
    {
        $events = [];
        $this->db->on('transaction.commit', static function () use (&$events): void {
            $events[] = 'commit';
        });
        $this->db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $original = new RuntimeException('callback');

        try {
            $this->db->transaction(static function (DatabaseInterface $db) use ($original): void {
                $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);
                throw $original;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($original, $e);
        }

        $this->assertSame(['rollback'], $events);
        $this->assertSame(0, $this->userCount($this->db));
    }

    public function testManualCommitReportsHookPdoExceptionAsCommitHookException(): void
    {
        $hookError = new PDOException('hook query failed');
        $this->db->on('transaction.commit', static fn () => throw $hookError);
        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);

        try {
            $this->db->commit();
            $this->fail('Expected CommitHookException');
        } catch (TransactionException) {
            $this->fail('A listener error must not be reported as a failed commit');
        } catch (CommitHookException $e) {
            $this->assertSame($hookError, $e->getPrevious());
            $this->assertSame([$hookError], $e->failures);
        }

        $this->assertSame(1, $this->userCount($this->db));
        $this->assertFalse($this->db->getPdo()->inTransaction());
    }

    public function testTransactionReturnsCallbackResultWithCommitListeners(): void
    {
        $calls = 0;
        $this->db->on('transaction.commit', static function () use (&$calls): void {
            $calls++;
        });

        $result = $this->db->transaction(static fn (DatabaseInterface $db) => $db->insert('users', ['name' => 'Max']));

        $this->assertSame('1', (string) $result);
        $this->assertSame(1, $calls);
    }

    /**
     * A COMMIT that fails leaves the transaction open; the wrapper rolls it back. The failure is the
     * PDO class's (ScenarioPdo::$failCommit): a database that checks every constraint at the
     * statement has nothing left to reject at the COMMIT.
     */
    public function testFailedPdoCommitIsRolledBackAndNotACommitHookException(): void
    {
        $db = $this->scenarioDriver();
        $this->createChildrenTable();
        $events = [];
        $db->on('transaction.commit', static function () use (&$events): void {
            $events[] = 'commit';
        });
        $db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $this->scenarioPdo->failCommit = true;

        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO children (id, user_id) VALUES (1, 99)'));
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
            $this->assertInstanceOf(CommitFailedException::class, $e);
            $this->assertSame(DatabaseInterface::TRANSACTION_ROLLED_BACK, $e->outcome, 'nothing is committed');
        }

        // The failed COMMIT left the transaction open; the wrapper rolls it back.
        $this->assertSame(['rollback'], $events);
        $this->assertFalse($db->getPdo()->inTransaction());
        $this->assertSame(0, $this->rowCount($db, 'children'));
    }

    /**
     * A COMMIT that fails in a non-exception error mode: PDO::commit() returns false (the PDO
     * class's failure, ScenarioPdo::$commitReturnsFalse, as in the test above).
     */
    public function testCommitReturningFalseIsATransactionException(): void
    {
        $db = $this->scenarioDriver();
        $this->createChildrenTable();
        $db->getPdo()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $events = [];
        $db->on('transaction.commit', static function () use (&$events): void {
            $events[] = 'commit';
        });
        $db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $this->scenarioPdo->commitReturnsFalse = true;

        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO children (id, user_id) VALUES (1, 99)'));
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('PDO::commit() returned false', $e->getDebugMessage());
            $this->assertInstanceOf(CommitFailedException::class, $e);
            $this->assertSame(DatabaseInterface::TRANSACTION_ROLLED_BACK, $e->outcome);
        }

        $this->assertSame(['rollback'], $events);
        $this->assertFalse($db->getPdo()->inTransaction());
        $this->assertSame(0, $this->rowCount($db, 'children'));
    }

    public function testAllCommitListenersRunAndFailuresKeepListenerOrder(): void
    {
        $first = new RuntimeException('first');
        $second = new LogicException('second');
        $thirdRan = false;
        $this->db->on('transaction.commit', static fn () => throw $first);
        $this->db->on('transaction.commit', static fn () => throw $second);
        $this->db->on('transaction.commit', static function () use (&$thirdRan): void {
            $thirdRan = true;
        });

        try {
            $this->db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertSame([$first, $second], $e->failures);
            $this->assertSame($first, $e->getPrevious());
        }

        $this->assertTrue($thirdRan);
        $this->assertSame(1, $this->userCount($this->db));
    }

    public function testTransactionLeftOpenByListenerIsRolledBackBeforeNextListener(): void
    {
        $events = [];
        $first = new RuntimeException('first');
        $second = new RuntimeException('second');
        $this->db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $this->db->on('transaction.commit', function () use ($first): void {
            $this->db->getPdo()->beginTransaction(); // on raw PDO: through the driver it is refused in a listener
            $this->db->execute('INSERT INTO users (name) VALUES (?)', ['from listener']);
            throw $first;
        });
        $this->db->on('transaction.commit', function () use (&$events, $second): void {
            $events[] = $this->db->getPdo()->inTransaction() ? 'still open' : 'closed';
            throw $second;
        });

        try {
            $this->db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(3, $e->failures);
            $this->assertSame($first, $e->failures[0]);
            $this->assertInstanceOf(LogicException::class, $e->failures[1]);
            $this->assertSame('listener left a transaction open', $e->failures[1]->getMessage());
            $this->assertNull($e->failures[1]->getPrevious());
            $this->assertSame($second, $e->failures[2]);
        }

        // Rolled back directly on PDO: rollback listeners never hear of it.
        $this->assertSame(['closed'], $events);
        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertSame(1, $this->userCount($this->db));
    }

    public function testQuietListenerLeavingTransactionOpenIsReported(): void
    {
        $this->db->on('transaction.commit', function (): void {
            $this->db->getPdo()->beginTransaction(); // on raw PDO: through the driver it is refused in a listener
        });

        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);

        try {
            $this->db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(1, $e->failures);
            $this->assertSame('listener left a transaction open', $e->failures[0]->getMessage());
            $this->assertFalse($e->connectionInTransaction, 'the open transaction was rolled back');
        }

        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertSame(1, $this->userCount($this->db));
    }

    public function testErrorFromListenerIsCollectedAndNextListenerRuns(): void
    {
        $error = new Error('listener error');
        $secondRan = false;
        $this->db->on('transaction.commit', static fn () => throw $error);
        $this->db->on('transaction.commit', static function () use (&$secondRan): void {
            $secondRan = true;
        });

        try {
            $this->db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertSame([$error], $e->failures);
            $this->assertSame($error, $e->getPrevious());
        }

        $this->assertTrue($secondRan);
        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertSame(1, $this->userCount($this->db));
    }

    public function testFailedCleanupReturningFalseSkipsRemainingListeners(): void
    {
        $this->assertFailedCleanupSkipsRemainingListeners(null);
    }

    public function testFailedCleanupThrowingSkipsRemainingListeners(): void
    {
        $this->assertFailedCleanupSkipsRemainingListeners(new PDOException('rollback failed'));
    }

    public function testFailedCleanupThrowingErrorSkipsRemainingListeners(): void
    {
        $this->assertFailedCleanupSkipsRemainingListeners(new Error('rollback error'));
    }

    public function testUnreadableConnectionStateAfterListenerIsBundled(): void
    {
        $this->assertUnreadableStateIsBundled(new PDOException('connection lost'));
    }

    public function testConnectionStateErrorAfterListenerIsBundled(): void
    {
        $this->assertUnreadableStateIsBundled(new Error('state error'));
    }

    /**
     * The first commit listener makes the connection state unreadable: the next inTransaction()
     * throws $stateError (ScenarioPdo::$duringInTransaction - the library reads the state once
     * after that listener; nothing reads it again before the state is readable again).
     */
    private function assertUnreadableStateIsBundled(Throwable $stateError): void
    {
        $db = $this->scenarioDriver();
        $pdo = $this->scenarioPdo;
        $first = new RuntimeException('first');
        $events = [];
        $db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $db->on('transaction.commit', static function () use ($pdo, $stateError, $first): void {
            $pdo->duringInTransaction = static fn () => throw $stateError;
            throw $first;
        });
        $db->on('transaction.commit', static function () use (&$events): void {
            $events[] = 'second listener';
        });

        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(3, $e->failures);
            $this->assertSame($first, $e->getPrevious());
            $this->assertSame($first, $e->failures[0]);
            $this->assertSame('connection state unknown after listener', $e->failures[1]->getMessage());
            $this->assertSame($stateError, $e->failures[1]->getPrevious());
            $this->assertSame('listener skipped: connection left in transaction', $e->failures[2]->getMessage());
            $this->assertSame($stateError, $e->failures[2]->getPrevious());
            $this->assertTrue($e->connectionInTransaction, 'unreadable state counts as in transaction (fail-closed)');
        }

        $this->assertNull($pdo->duringInTransaction, 'the state was read after the listener, and is readable again');
        $this->assertSame([], $events);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(1, $this->userCount($db));
    }

    /**
     * What leaves commit() without being PDO's failure - an error handler's exception for a PDO
     * warning - is no failed commit of this library, but the transaction is still open: it is
     * rolled back and the exception reaches the caller.
     */
    public function testAnExceptionFromTheCommitThatIsNotPdosIsRolledBackInTransaction(): void
    {
        $db = $this->scenarioDriver();
        $commitError = new RuntimeException('thrown inside PDO::commit()');
        $this->scenarioPdo->throwFromCommit = $commitError;

        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']));
            $this->fail('Expected the commit exception');
        } catch (RuntimeException $e) {
            $this->assertSame($commitError, $e);
        }

        $this->assertSame(1, $this->scenarioPdo->rollBackCalls);
        $this->assertFalse($db->getPdo()->inTransaction());
        $this->assertSame(0, $this->userCount($db));
    }

    public function testAnExceptionFromTheCommitThatIsNotPdosIsRolledBackInUpdateMultiple(): void
    {
        $db = $this->scenarioDriver();
        $db->insert('users', ['id' => 1, 'name' => 'Max']);
        $commitError = new RuntimeException('thrown inside PDO::commit()');
        $this->scenarioPdo->throwFromCommit = $commitError;

        try {
            $db->updateMultiple('users', [['id' => 1, 'name' => 'Max Updated']]);
            $this->fail('Expected the commit exception');
        } catch (RuntimeException $e) {
            $this->assertSame($commitError, $e);
        }

        $this->assertSame(1, $this->scenarioPdo->rollBackCalls);
        $this->assertFalse($db->getPdo()->inTransaction());
        $this->assertSame('Max', $db->findOne('users', ['id' => 1])['name'] ?? null);
    }

    public function testThrowingRollbackHookDoesNotMaskCallbackException(): void
    {
        $original = new RuntimeException('callback');
        $this->db->on('transaction.rollback', static fn () => throw new RuntimeException('rollback hook'));

        try {
            $this->db->transaction(static function (DatabaseInterface $db) use ($original): void {
                $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);
                throw $original;
            });
            $this->fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            $this->assertSame($original, $e);
        }

        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertSame(0, $this->userCount($this->db));
    }

    public function testUpdateMultipleReportsCommitHookErrorWithoutRollback(): void
    {
        $db = $this->scenarioDriver();
        $db->insert('users', ['id' => 1, 'name' => 'Max']);
        $hookError = new RuntimeException('hook');
        $db->on('transaction.commit', static fn () => throw $hookError);

        try {
            $db->updateMultiple('users', [['id' => 1, 'name' => 'Max Updated']]);
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertSame($hookError, $e->getPrevious());
        }

        $this->assertSame('Max Updated', $db->findOne('users', ['id' => 1])['name'] ?? null);
        $this->assertSame(0, $this->scenarioPdo->rollBackCalls);
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    /**
     * A commit listener that runs a transaction of its own through the driver is refused: the
     * transaction() inside it throws a ListenerTransactionException before anything is begun, and that
     * is the listener's failure. The outer transaction is committed; the inner insert never ran.
     */
    public function testCommitListenerRunningItsOwnTransactionIsRefused(): void
    {
        $db = $this->scenarioDriver();
        $ran = false;
        $db->on('transaction.commit', static function () use ($db, &$ran): void {
            $db->transaction(static function (DatabaseInterface $db) use (&$ran): void {
                $ran = true;
            });
        });

        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO users (name) VALUES (?)', ['outer']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(1, $e->failures);
            $this->assertInstanceOf(ListenerTransactionException::class, $e->getPrevious());
            $this->assertFalse($e->connectionInTransaction);
        }

        $this->assertFalse($ran, 'nothing was begun');
        $this->assertSame(1, $this->userCount($db));
        $this->assertSame(0, $this->scenarioPdo->rollBackCalls);
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    /**
     * A listener throws and leaves a transaction open; the raw rollback returns false (null) or
     * throws $rollbackError (ScenarioPdo::$duringRollBack: the rollback is tried once, as counted).
     */
    private function assertFailedCleanupSkipsRemainingListeners(?Throwable $rollbackError): void
    {
        $db = $this->scenarioDriver();
        $pdo = $this->scenarioPdo;
        $events = [];
        $first = new RuntimeException('first');
        $db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $db->on('transaction.commit', static function () use ($pdo, $rollbackError, $first): void {
            if ($rollbackError !== null) {
                $pdo->duringRollBack = static fn () => throw $rollbackError;
            } else {
                $pdo->rollBackReturnsFalse = true;
            }
            $pdo->beginTransaction(); // on raw PDO: through the driver it is refused in a listener
            throw $first;
        });
        $db->on('transaction.commit', static function () use (&$events): void {
            $events[] = 'second listener';
        });

        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(3, $e->failures);
            $this->assertSame($first, $e->failures[0]);
            $this->assertSame($first, $e->getPrevious());
            $this->assertSame('listener left a transaction open', $e->failures[1]->getMessage());
            $this->assertSame('listener skipped: connection left in transaction', $e->failures[2]->getMessage());
            $cleanupError = $e->failures[1]->getPrevious();
            if ($rollbackError !== null) {
                $this->assertSame($rollbackError, $cleanupError);
            } else {
                $this->assertInstanceOf(TransactionException::class, $cleanupError);
                $this->assertSame('PDO::rollBack() returned false', $cleanupError->getDebugMessage());
            }
            $this->assertSame($cleanupError, $e->failures[2]->getPrevious());
            $this->assertTrue($e->connectionInTransaction);
        }

        // No further rollback attempt: the connection is left as the failed cleanup left it.
        $this->assertSame([], $events);
        $this->assertSame(1, $pdo->rollBackCalls);
        $this->assertTrue($pdo->inTransaction());
    }

    /**
     * Driver whose PDO reports a failed BEGIN or ROLLBACK by returning false instead of throwing
     * (non-exception error mode), without changing the connection state.
     *
     * @param 'begin'|'rollback' $fail
     */
    private function falseReturningDriver(string $fail): DatabaseInterface
    {
        $db = $this->connect(['pdoClass' => FalseReturningPdo::class]);
        $pdo = $db->getPdo();
        $this->assertInstanceOf(FalseReturningPdo::class, $pdo);
        $pdo->fail = $fail;

        return $db;
    }

    /**
     * In a non-exception error mode PDO reports a failure by returning false and keeps what the
     * database said in errorInfo(): the exception carries SQLSTATE and driver code all the same,
     * as it would for a thrown PDOException.
     */
    public function testAFailureReportedByReturningFalseCarriesTheCodes(): void
    {
        $db = $this->connect(['pdoClass' => FalseReturningPdo::class]);
        $pdo = $db->getPdo();
        $this->assertInstanceOf(FalseReturningPdo::class, $pdo);
        $pdo->failureInfo = ['HY000', 4711, 'what the database said'];
        $failures = [];

        $pdo->fail = 'begin';
        try {
            $db->beginTransaction();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $failures['begin'] = $e;
        }

        $pdo->fail = '';
        $db->beginTransaction();
        $pdo->fail = 'commit';
        try {
            $db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $failures['commit'] = $e;
        }

        $pdo->fail = 'rollback';
        try {
            $db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $failures['rollback'] = $e;
        }
        $pdo->fail = '';
        $db->rollback();

        // what PDO recorded for the failed lastInsertId() is kept as it was at that moment: a 'query'
        // listener runs afterwards, and its own PDO calls replace it
        $db->on('query', static function () use ($pdo): void {
            $pdo->fail = '';
        });
        $pdo->fail = 'insert id';
        try {
            $db->insert('users', ['name' => 'Max']);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Insert failed', $e->getMessage());
            $failures['insert id'] = $e;
        }

        $this->assertSame(['begin', 'commit', 'rollback', 'insert id'], array_keys($failures));
        foreach ($failures as $what => $e) {
            $this->assertSame(['HY000', 4711], [$e->sqlState, $e->driverCode], $what);
            $this->assertInstanceOf(PDOException::class, $e->getPrevious(), $what);
        }
    }

    /**
     * The 'transaction.begin' listeners are called by beginTransaction() itself, with a payload
     * a listener may take by reference - as trigger() hands it over for the other events. What it
     * changes there is what the listeners after it are told, as in 2.0. The numbers are the
     * library's: one that changes them changes what the later begin listeners are told, not what
     * the library tells at the end.
     */
    public function testABeginListenerMayTakeItsPayloadByReference(): void
    {
        $seen = [];
        $this->db->on('transaction.begin', static function (array &$data) use (&$seen): void {
            $seen[] = $data;
            $data['marked'] = true;
            $data['transaction'] = 99;
        });
        $this->db->on('transaction.begin', static function (array $data) use (&$seen): void {
            $seen[] = $data;
        });
        $this->db->on('transaction.end', static function (array $data) use (&$seen): void {
            $seen[] = ['end' => $data['transaction']];
        });

        $this->db->beginTransaction();
        $this->assertTrue($this->db->inTransaction());
        $this->db->rollback();

        $this->assertSame([
            ['transaction' => 1, 'depth' => 1],
            ['transaction' => 99, 'depth' => 1, 'marked' => true],
            ['end' => 1],
        ], $seen);
    }

    /**
     * The events trigger() hands out ('query', 'error', 'transaction.rollback') pass one variable,
     * as in 2.0: what a listener that takes it by reference changes is what the next one is told -
     * a listener that redacts the parameters before a logger keeps working.
     */
    public function testAListenerTakingThePayloadByReferenceChangesWhatTheNextIsTold(): void
    {
        $seen = [];
        $this->db->on('query', static function (array &$data): void {
            $data['params'] = ['(redacted)'];
        });
        $this->db->on('query', static function (array $data) use (&$seen): void {
            $seen[] = $data['params'];
        });
        $this->db->on('transaction.rollback', static function (array &$data): void {
            $data['marked'] = true;
        });
        $this->db->on('transaction.rollback', static function (array $data) use (&$seen): void {
            $seen[] = $data;
        });

        $this->db->query('SELECT ?', ['secret']);
        $this->db->beginTransaction();
        $this->db->rollback();

        $this->assertSame([['(redacted)'], ['transaction' => 1, 'depth' => 1, 'marked' => true]], $seen);
    }

    /**
     * 'transaction.commit' and 'transaction.end' listeners get an array of their own each, as in
     * 2.0: one that takes it by reference cannot be called with it - PHP throws an Error, which is
     * that listener's failure. The others are told as always, and the transaction is committed.
     */
    public function testACommitOrEndListenerCannotTakeItsPayloadByReference(): void
    {
        $told = [];
        $this->db->on('transaction.commit', static function (array &$data): void {
            $data['marked'] = true;
        });
        $this->db->on('transaction.commit', static function (array $data) use (&$told): void {
            $told[] = $data;
        });
        $this->db->on('transaction.end', static function (array &$data): void {
            $data['marked'] = true;
        });
        $this->db->on('transaction.end', static function (array $data) use (&$told): void {
            $told[] = $data;
        });

        $this->db->beginTransaction();
        $this->db->insert('users', ['name' => 'kept']);
        try {
            $this->db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(2, $e->failures);
            foreach ($e->failures as $failure) {
                $this->assertInstanceOf(Error::class, $failure);
                $this->assertStringContainsString('could not be passed by reference', $failure->getMessage());
            }
        }

        $this->assertSame([
            ['transaction' => 1, 'depth' => 1],
            ['outcome' => 'committed', 'error' => null, 'transaction' => 1, 'depth' => 1],
        ], $told);
        $this->assertSame(1, $this->userCount($this->db));
    }

    /**
     * A driver on a ScenarioPdo ($this->scenarioPdo, through pdoClass): the ROLLBACKs sent are
     * counted there - after a successful commit there must be none -, and its commit() and
     * rollBack() fail on demand.
     */
    private function scenarioDriver(): DatabaseInterface
    {
        $db = $this->connect(['pdoClass' => ScenarioPdo::class]);
        $pdo = $db->getPdo();
        $this->assertInstanceOf(ScenarioPdo::class, $pdo);
        $this->scenarioPdo = $pdo;

        return $db;
    }

    /**
     * The table the row of a transaction whose COMMIT fails goes into. It has no foreign key: the
     * COMMIT is made to fail by the PDO class, and a database that checks the key at the INSERT
     * would reject the row there already.
     */
    private function createChildrenTable(): void
    {
        $this->create('children', ['id' => 'key', 'user_id' => 'int']);
    }

    private function userCount(DatabaseInterface $db): int
    {
        return $this->rowCount($db, 'users');
    }

    private function rowCount(DatabaseInterface $db, string $table): int
    {
        $row = $db->query('SELECT COUNT(*) AS c FROM ' . $table)->fetch();
        $this->assertIsArray($row);
        $this->assertIsInt($row['c'], 'COUNT() arrives as int');

        return $row['c'];
    }
}
