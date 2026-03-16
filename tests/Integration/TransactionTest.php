<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;

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
}
