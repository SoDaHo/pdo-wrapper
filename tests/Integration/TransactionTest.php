<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use Error;
use Exception;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Throwable;

class TransactionTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = Database::sqlite();
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    }

    public function testManualTransactionCommit(): void
    {
        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Max']);
        $this->db->commit();

        $stmt = $this->db->query('SELECT * FROM users');
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
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public int $rollBackCalls = 0;

            public function rollBack(): bool
            {
                $this->rollBackCalls++;
                throw new PDOException('rollback failed');
            }
        };
        $db = new class ($pdo) extends SqliteDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
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
        $db = $this->falseReturningDriver(failBegin: true);
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
        $db = $this->falseReturningDriver(failBegin: true);
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
        $db = $this->falseReturningDriver(failRollback: true);
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
        $this->assertSame('Max', $user['name']);
    }

    // =========================================================================
    // Rollback Failure Tests
    // These test that the original exception is preserved when rollback itself
    // fails (e.g., due to connection loss). This is defensive code coverage.
    // =========================================================================

    /**
     * Test that transaction() preserves original exception when rollback fails.
     *
     * Uses a driver that throws on rollback to simulate connection loss.
     * The original exception should be re-thrown, not the rollback failure.
     */
    public function testTransactionPreservesOriginalExceptionWhenRollbackFails(): void
    {
        $failingDb = new class () extends \Sodaho\PdoWrapper\Driver\SqliteDriver {
            private bool $shouldFailRollback = false;

            public function __construct()
            {
                parent::__construct(':memory:');
                $this->execute('CREATE TABLE test (id INTEGER PRIMARY KEY)');
            }

            public function setShouldFailRollback(bool $fail): void
            {
                $this->shouldFailRollback = $fail;
            }

            public function rollback(): void
            {
                if ($this->shouldFailRollback) {
                    throw new \Sodaho\PdoWrapper\Exception\TransactionException('Rollback failed');
                }
                parent::rollback();
            }
        };

        $failingDb->setShouldFailRollback(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Original error');

        $failingDb->transaction(function ($driver) {
            $driver->execute('INSERT INTO test (id) VALUES (1)');
            throw new RuntimeException('Original error');
        });
    }

    /**
     * Test that updateMultiple() preserves original exception when rollback fails.
     *
     * Simulates connection loss by closing PDO mid-operation.
     * The original exception should be re-thrown, not the rollback failure.
     */
    public function testUpdateMultiplePreservesOriginalExceptionWhenRollbackFails(): void
    {
        // We use a custom driver that throws on rollback to simulate connection loss
        $failingDb = new class () extends \Sodaho\PdoWrapper\Driver\SqliteDriver {
            private bool $shouldFailRollback = false;

            public function __construct()
            {
                parent::__construct(':memory:');
                $this->execute('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
                $this->insert('test', ['id' => 1, 'name' => 'Original']);
            }

            public function setShouldFailRollback(bool $fail): void
            {
                $this->shouldFailRollback = $fail;
            }

            public function rollback(): void
            {
                if ($this->shouldFailRollback) {
                    throw new \Sodaho\PdoWrapper\Exception\TransactionException('Rollback failed');
                }
                parent::rollback();
            }
        };

        $failingDb->setShouldFailRollback(true);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Update failed');

        // This should throw QueryException for missing key, not TransactionException
        $failingDb->updateMultiple('test', [
            ['id' => 1, 'name' => 'Updated'],
            ['name' => 'No ID'], // Missing key column - triggers error
        ]);
    }

    // =========================================================================
    // Commit Phase Tests
    // A transaction.commit listener runs after the commit: its failure must not
    // look like a failed commit, and nothing committed may be rolled back.
    // =========================================================================

    public function testThrowingCommitHookKeepsCommittedDataWithoutRollback(): void
    {
        $db = $this->rollbackCountingDriver();
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
        $this->assertSame(0, $db->rollbackCalls);
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

    public function testFailedPdoCommitIsRolledBackAndNotACommitHookException(): void
    {
        $this->createDeferredChildrenTable($this->db);
        $events = [];
        $this->db->on('transaction.commit', static function () use (&$events): void {
            $events[] = 'commit';
        });
        $this->db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });

        try {
            $this->db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO children (id, user_id) VALUES (1, 99)'));
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
            $this->assertInstanceOf(CommitFailedException::class, $e);
            $this->assertSame(DatabaseInterface::TRANSACTION_ROLLED_BACK, $e->outcome, 'nothing is committed');
        }

        // SQLite keeps the transaction open after a rejected COMMIT; the wrapper rolls it back.
        $this->assertSame(['rollback'], $events);
        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS c FROM children')->fetch()['c']);
    }

    public function testCommitReturningFalseIsATransactionException(): void
    {
        $this->createDeferredChildrenTable($this->db);
        $this->db->getPdo()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $events = [];
        $this->db->on('transaction.commit', static function () use (&$events): void {
            $events[] = 'commit';
        });
        $this->db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });

        try {
            $this->db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO children (id, user_id) VALUES (1, 99)'));
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('PDO::commit() returned false', $e->getDebugMessage());
            $this->assertInstanceOf(CommitFailedException::class, $e);
            $this->assertSame(DatabaseInterface::TRANSACTION_ROLLED_BACK, $e->outcome);
        }

        $this->assertSame(['rollback'], $events);
        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS c FROM children')->fetch()['c']);
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
            $this->db->beginTransaction();
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
            $this->db->beginTransaction();
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

    private function assertUnreadableStateIsBundled(Throwable $stateError): void
    {
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public ?Throwable $stateError = null;

            public function inTransaction(): bool
            {
                if ($this->stateError !== null) {
                    throw $this->stateError;
                }

                return parent::inTransaction();
            }
        };
        $db = new class ($pdo) extends SqliteDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $first = new RuntimeException('first');
        $events = [];
        $db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $db->on('transaction.commit', static function () use ($pdo, $stateError, $first): void {
            $pdo->stateError = $stateError;
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

        $pdo->stateError = null;
        $this->assertSame([], $events);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(1, $this->userCount($db));
    }

    public function testCommitOverrideErrorInTransactionIsRolledBack(): void
    {
        $db = $this->failingCommitDriver();
        $commitError = $db->commitError;

        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO users (name) VALUES (?)', ['Max']));
            $this->fail('Expected the commit exception');
        } catch (RuntimeException $e) {
            $this->assertSame($commitError, $e);
        }

        $this->assertSame(1, $db->rollbackCalls);
        $this->assertFalse($db->getPdo()->inTransaction());
        $this->assertSame(0, $this->userCount($db));
    }

    public function testCommitOverrideErrorInUpdateMultipleIsRolledBack(): void
    {
        $db = $this->failingCommitDriver();
        $db->insert('users', ['id' => 1, 'name' => 'Max']);
        $commitError = $db->commitError;

        try {
            $db->updateMultiple('users', [['id' => 1, 'name' => 'Max Updated']]);
            $this->fail('Expected the commit exception');
        } catch (RuntimeException $e) {
            $this->assertSame($commitError, $e);
        }

        $this->assertSame(1, $db->rollbackCalls);
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
        $db = $this->rollbackCountingDriver();
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
        $this->assertSame(0, $db->rollbackCalls);
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    public function testCommitListenerRunningItsOwnTransaction(): void
    {
        $db = $this->rollbackCountingDriver();
        $innerError = new RuntimeException('inner listener');
        $nested = false;
        $thrown = false;
        $db->on('transaction.commit', static function () use ($db, &$nested): void {
            if (!$nested) {
                $nested = true;
                $db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO users (name) VALUES (?)', ['inner']));
            }
        });
        $db->on('transaction.commit', static function () use ($innerError, &$nested, &$thrown): void {
            if ($nested && !$thrown) {
                $thrown = true;
                throw $innerError;
            }
        });

        try {
            $db->transaction(static fn (DatabaseInterface $db) => $db->execute('INSERT INTO users (name) VALUES (?)', ['outer']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(1, $e->failures);
            $inner = $e->getPrevious();
            $this->assertInstanceOf(CommitHookException::class, $inner);
            $this->assertSame($innerError, $inner->getPrevious());
        }

        $this->assertSame(2, $this->userCount($db));
        $this->assertSame(0, $db->rollbackCalls);
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    /**
     * A listener throws and leaves a transaction open; the raw rollback returns false (null) or throws $rollbackError.
     */
    private function assertFailedCleanupSkipsRemainingListeners(?Throwable $rollbackError): void
    {
        $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
            public ?Throwable $rollBackError = null;
            public bool $failRollBack = false;
            public int $rollBackCalls = 0;

            public function rollBack(): bool
            {
                if (!$this->failRollBack) {
                    return parent::rollBack();
                }
                $this->rollBackCalls++;
                if ($this->rollBackError !== null) {
                    throw $this->rollBackError;
                }

                return false;
            }
        };
        // Same pattern as a driver built around an existing connection: no parent constructor.
        $db = new class ($pdo) extends SqliteDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->rollBackError = $rollbackError;
        $events = [];
        $first = new RuntimeException('first');
        $db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $db->on('transaction.commit', static function () use ($db, $pdo, $first): void {
            $pdo->failRollBack = true;
            $db->beginTransaction();
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
     */
    private function falseReturningDriver(bool $failBegin = false, bool $failRollback = false): DatabaseInterface
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public bool $failBegin = false;
            public bool $failRollback = false;

            public function beginTransaction(): bool
            {
                return $this->failBegin ? false : parent::beginTransaction();
            }

            public function rollBack(): bool
            {
                return $this->failRollback ? false : parent::rollBack();
            }
        };
        $pdo->failBegin = $failBegin;
        $pdo->failRollback = $failRollback;

        return new class ($pdo) extends AbstractDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
    }

    /**
     * SQLite driver counting rollback() calls: after a successful commit there must be none.
     */
    private function rollbackCountingDriver(): SqliteDriver
    {
        $db = new class () extends SqliteDriver {
            public int $rollbackCalls = 0;

            public function rollback(): void
            {
                $this->rollbackCalls++;
                parent::rollback();
            }
        };
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        return $db;
    }

    /**
     * Driver whose commit() override fails without committing (an allowed override), counting rollback() calls.
     */
    private function failingCommitDriver(): SqliteDriver
    {
        $db = new class () extends SqliteDriver {
            public int $rollbackCalls = 0;
            public RuntimeException $commitError;

            public function commit(): void
            {
                throw $this->commitError;
            }

            public function rollback(): void
            {
                $this->rollbackCalls++;
                parent::rollback();
            }
        };
        $db->commitError = new RuntimeException('commit override failed');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        return $db;
    }

    /**
     * Table whose foreign key is only checked at COMMIT, so SQLite can reject the COMMIT itself.
     */
    private function createDeferredChildrenTable(DatabaseInterface $db): void
    {
        $db->execute('CREATE TABLE children (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id) DEFERRABLE INITIALLY DEFERRED)');
    }

    private function userCount(DatabaseInterface $db): int
    {
        return (int) $db->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
    }
}
