<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Feature;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Tests\Feature\Concerns\AbstractWorkflowTest;

/**
 * Workflow tests for MySQL driver.
 *
 * @group mysql
 */
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
}
