<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Exception\UniqueViolationException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Contract\Feature\WorkflowSchema;
use Sodaho\PdoWrapper\Tests\Support\Fetched;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

/**
 * The workflows on MariaDB alone: insertIgnore() and ATTR_FOUND_ROWS or an update trigger, key
 * names with a dot, a COMMIT that fails through a PDO subclass, database-qualified tables, LIKE
 * under NO_BACKSLASH_ESCAPES.
 */
class WorkflowTest extends ContractTestCase
{
    use WorkflowSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWorkflowSchema();
    }

    /**
     * With the driver's ATTR_FOUND_ROWS option the server counts matched rows instead of changed
     * ones: an update that changes nothing reports 1 - and so would a skipped insertIgnore(). It
     * refuses instead of answering "inserted" for a row that existed.
     */
    public function testInsertIgnoreRefusesAConnectionThatCountsFoundRows(): void
    {
        $option = \Pdo\Mysql::ATTR_FOUND_ROWS;
        $config = TestEnvironment::mysql();
        $this->db->insert('users', ['email' => 'a@test.com', 'name' => 'A']);

        $counting = Database::mysql($config + ['options' => [$option => true]]);
        $this->assertSame(0, $this->db->update('users', ['name' => 'A'], ['email' => 'a@test.com']), 'the default: changed rows');
        $this->assertSame(1, $counting->update('users', ['name' => 'A'], ['email' => 'a@test.com']), 'with the option: matched rows');

        $sent = 0;
        $counting->on('query', static function () use (&$sent): void {
            $sent++;
        });
        foreach ([
            static fn (): int => $counting->insertIgnore('users', ['email' => 'a@test.com', 'name' => 'B']),
            static fn (): int => $counting->table('users')->insertIgnore(['email' => 'new@test.com', 'name' => 'New']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected QueryException');
            } catch (QueryException $e) {
                $this->assertSame('Insert failed', $e->getMessage());
                $this->assertStringContainsString('ATTR_FOUND_ROWS', (string) $e->getDebugMessage());
            }
        }
        $this->assertSame(0, $sent, 'nothing is sent');

        $explicitlyOff = Database::mysql($config + ['options' => [$option => false]]);
        $this->assertSame(0, $explicitlyOff->insertIgnore('users', ['email' => 'a@test.com', 'name' => 'B']));
        $this->assertSame(1, $this->db->table('users')->count());
    }

    /**
     * MySQL since 8.0.19 prints `table.key`, MariaDB (and older MySQL) `key`. A dot inside the
     * table or the key name makes the first form ambiguous: no name then, rather than a wrong
     * one. The second form has no such doubt.
     */
    public function testAKeyOrTableNameWithADotIsNotGuessed(): void
    {
        $version = (string) $this->db->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        $mariaDb = stripos($version, 'MariaDB') !== false || version_compare($version, '8.0.19', '<');
        $this->db->execute('DROP TABLE IF EXISTS `dotted.table`');
        $this->db->execute('CREATE TABLE `dotted.table` (id INT PRIMARY KEY, email VARCHAR(50), nick VARCHAR(50), UNIQUE KEY `email` (email), UNIQUE KEY `my.key` (nick))');

        try {
            $this->db->execute("INSERT INTO `dotted.table` VALUES (1, 'a', 'n')");
            foreach ([
                "INSERT INTO `dotted.table` VALUES (2, 'a', 'm')" => $mariaDb ? 'email' : null,
                "INSERT INTO `dotted.table` VALUES (2, 'b', 'n')" => $mariaDb ? 'my.key' : null,
                "INSERT INTO `dotted.table` VALUES (1, 'b', 'm')" => $mariaDb ? 'PRIMARY' : null,
            ] as $sql => $expected) {
                try {
                    $this->db->execute($sql);
                    $this->fail('Expected UniqueViolationException');
                } catch (UniqueViolationException $e) {
                    $this->assertSame($expected, $e->constraint, $sql);
                }
            }
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS `dotted.table`');
        }
    }

    /**
     * The MySQL form of insertIgnore() is an upsert that updates nothing: for an existing row the
     * server runs the update triggers. One that changes the row makes the server report 2
     * affected rows - which is still "not inserted".
     */
    public function testInsertIgnoreReturnsZeroWhenAnUpdateTriggerChangesTheExistingRow(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS ignore_seen');
        $this->db->execute('CREATE TABLE ignore_seen (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(50) UNIQUE, seen INT NOT NULL DEFAULT 0)');
        $this->db->getPdo()->exec('CREATE TRIGGER ignore_seen_bu BEFORE UPDATE ON ignore_seen FOR EACH ROW SET NEW.seen = OLD.seen + 1');

        try {
            $this->assertSame(1, $this->db->insertIgnore('ignore_seen', ['email' => 'a@test.com']));
            $this->assertSame(0, $this->db->insertIgnore('ignore_seen', ['email' => 'a@test.com']));
            $this->assertSame(0, $this->db->table('ignore_seen')->insertIgnore(['email' => 'a@test.com']));
            $this->assertSame(2, Fetched::int($this->db->findOne('ignore_seen', ['email' => 'a@test.com'])['seen'] ?? 0), 'the trigger ran for both skipped inserts');
            $this->assertSame(1, $this->db->table('ignore_seen')->count());
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS ignore_seen');
        }
    }



    /**
     * MySQL and MariaDB cannot be made to reject a COMMIT in a test. A PDO subclass fails
     * commit() and keeps the transaction open: this covers the wrapper's branch (rollback,
     * TransactionException), not how MySQL or MariaDB behave.
     */
    public function testFailedCommitWrapperBranchWithPdoSubclass(): void
    {
        $c = TestEnvironment::mysql();
        $pdo = new class (
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']),
            $c['username'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        ) extends PDO {
            public function commit(): bool
            {
                throw new PDOException('Simulated commit failure');
            }
        };
        $db = new class ($pdo) extends MySqlDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
        $events = [];
        $db->on('transaction.commit', static function () use (&$events) {
            $events[] = 'commit';
        });
        $db->on('transaction.rollback', static function () use (&$events) {
            $events[] = 'rollback';
        });

        try {
            $db->transaction(fn () => $db->insert('users', ['email' => 'pdo-subclass@test.com', 'name' => 'PDO Subclass']));
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertSame('Simulated commit failure', $e->getPrevious()?->getMessage());
        }

        $this->assertSame(['rollback'], $events);
        $this->assertFalse($pdo->inTransaction());
        $this->assertNull($this->db->table('users')->where('email', 'pdo-subclass@test.com')->first());
    }

    /**
     * Test database-qualified table names (MySQL specific).
     * This tests that "database.table" is properly quoted as `database`.`table`.
     */
    public function testDatabaseQualifiedTableName(): void
    {
        // MySQL tables can be accessed with database.table syntax
        $id = $this->db->insert('pdo_wrapper_test.users', [
            'email' => 'dbqualified@test.com',
            'name' => 'DB Qualified Test',
        ]);

        $this->assertNotEmpty($id);

        // findOne with database prefix
        $user = $this->db->findOne('pdo_wrapper_test.users', ['id' => $id]);
        $this->assertNotNull($user);
        $this->assertSame('DB Qualified Test', $user['name']);

        // update with database prefix
        $affected = $this->db->update('pdo_wrapper_test.users', ['name' => 'Updated'], ['id' => $id]);
        $this->assertSame(1, $affected);

        // findAll with database prefix
        $users = $this->db->findAll('pdo_wrapper_test.users', ['id' => $id]);
        $this->assertCount(1, $users);
        $this->assertSame('Updated', $users[0]['name']);

        // delete with database prefix
        $deleted = $this->db->delete('pdo_wrapper_test.users', ['id' => $id]);
        $this->assertSame(1, $deleted);
    }

    /**
     * Test QueryBuilder with database-qualified table name.
     */
    public function testQueryBuilderWithDatabaseQualifiedTable(): void
    {
        $id = $this->db->insert('users', [
            'email' => 'qb-dbqualified@test.com',
            'name' => 'QB DB Qualified Test',
        ]);

        // Query using database.table
        $result = $this->db->table('pdo_wrapper_test.users')
            ->where('id', $id)
            ->first();

        $this->assertNotNull($result);
        $this->assertSame('QB DB Qualified Test', $result['name']);

        // Clean up
        $this->db->delete('users', ['id' => $id]);
    }

    /**
     * Under NO_BACKSLASH_ESCAPES the backslash is no escape character for MySQL unless the
     * statement says so: the join condition says so, with a bound value.
     */
    public function testLikeInAJoinConditionHoldsUnderNoBackslashEscapes(): void
    {
        $this->db->execute("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_BACKSLASH_ESCAPES')");

        $this->assertJoinLikeMatchesTheEscapedPatternOnly();
    }
}
