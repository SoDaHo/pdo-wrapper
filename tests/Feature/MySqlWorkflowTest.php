<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Feature;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Feature\Concerns\AbstractWorkflowTest;

/**
 * Workflow tests for MySQL driver.
 */
#[Group('mysql')]
class MySqlWorkflowTest extends AbstractWorkflowTest
{
    protected function createDatabase(): DatabaseInterface
    {
        return Database::mysql([
            'host' => $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
            'database' => $_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['MYSQL_USERNAME'] ?? 'root',
            'password' => $_ENV['MYSQL_PASSWORD'] ?? 'root',
        ]);
    }

    protected function getCreateUsersTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            name VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT "user",
            active TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    protected function getCreatePostsTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT,
            status VARCHAR(50) DEFAULT "draft",
            views INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    protected function getCreateCommentsTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (post_id) REFERENCES posts(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    protected function getCreateTagsTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS tags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) UNIQUE NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    protected function getCreatePostTagsTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS post_tags (
            post_id INT NOT NULL,
            tag_id INT NOT NULL,
            PRIMARY KEY (post_id, tag_id),
            FOREIGN KEY (post_id) REFERENCES posts(id),
            FOREIGN KEY (tag_id) REFERENCES tags(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    protected function getCreateDeferredChildrenTableSql(): ?string
    {
        // MySQL and MariaDB check foreign keys immediately: no deferred constraints.
        return null;
    }

    protected function failedCommitKeepsTransactionOpen(): bool
    {
        // Not used: the database cannot reject a COMMIT here (see the PDO subclass test below).
        return false;
    }

    /**
     * MySQL and MariaDB cannot be made to reject a COMMIT in a test. A PDO subclass fails
     * commit() and keeps the transaction open: this covers the wrapper's branch (rollback,
     * TransactionException), not how MySQL or MariaDB behave.
     */
    public function testFailedCommitWrapperBranchWithPdoSubclass(): void
    {
        $pdo = new class (
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $_ENV['MYSQL_HOST'] ?? '127.0.0.1', (int) ($_ENV['MYSQL_PORT'] ?? 3306), $_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test'),
            $_ENV['MYSQL_USERNAME'] ?? 'root',
            $_ENV['MYSQL_PASSWORD'] ?? 'root',
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
