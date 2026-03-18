<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Feature\Concerns\AbstractWorkflowTest;

/**
 * Workflow tests for PostgreSQL driver.
 */
#[Group('postgres')]
class PostgresWorkflowTest extends AbstractWorkflowTest
{
    protected function createDatabase(): DatabaseInterface
    {
        return Database::postgres([
            'host' => $_ENV['POSTGRES_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
            'database' => $_ENV['POSTGRES_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['POSTGRES_USERNAME'] ?? 'postgres',
            'password' => $_ENV['POSTGRES_PASSWORD'] ?? 'postgres',
        ]);
    }

    protected function getCreateUsersTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            name VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT \'user\',
            active INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )';
    }

    protected function getCreatePostsTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS posts (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id),
            title VARCHAR(255) NOT NULL,
            content TEXT,
            status VARCHAR(50) DEFAULT \'draft\',
            views INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )';
    }

    protected function getCreateCommentsTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS comments (
            id SERIAL PRIMARY KEY,
            post_id INTEGER NOT NULL REFERENCES posts(id),
            user_id INTEGER NOT NULL REFERENCES users(id),
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )';
    }

    protected function getCreateTagsTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS tags (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) UNIQUE NOT NULL
        )';
    }

    protected function getCreatePostTagsTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS post_tags (
            post_id INTEGER NOT NULL REFERENCES posts(id),
            tag_id INTEGER NOT NULL REFERENCES tags(id),
            PRIMARY KEY (post_id, tag_id)
        )';
    }

    /**
     * Test schema-qualified table names (PostgreSQL specific).
     * This tests that "public.users" is properly quoted as "public"."users".
     */
    public function testSchemaQualifiedTableName(): void
    {
        // PostgreSQL tables are in 'public' schema by default
        // Test CRUD operations with schema-qualified name
        $id = $this->db->insert('public.users', [
            'email' => 'schema@test.com',
            'name' => 'Schema Test',
        ]);

        $this->assertNotEmpty($id);

        // findOne with schema
        $user = $this->db->findOne('public.users', ['id' => $id]);
        $this->assertSame('Schema Test', $user['name']);

        // update with schema
        $affected = $this->db->update('public.users', ['name' => 'Updated'], ['id' => $id]);
        $this->assertSame(1, $affected);

        // findAll with schema
        $users = $this->db->findAll('public.users', ['id' => $id]);
        $this->assertCount(1, $users);
        $this->assertSame('Updated', $users[0]['name']);

        // delete with schema
        $deleted = $this->db->delete('public.users', ['id' => $id]);
        $this->assertSame(1, $deleted);
    }

    /**
     * Test QueryBuilder with schema-qualified table name.
     */
    public function testQueryBuilderWithSchemaQualifiedTable(): void
    {
        $id = $this->db->insert('users', [
            'email' => 'qb-schema@test.com',
            'name' => 'QB Schema Test',
        ]);

        // Query using schema.table
        $result = $this->db->table('public.users')
            ->where('id', $id)
            ->first();

        $this->assertNotNull($result);
        $this->assertSame('QB Schema Test', $result['name']);

        // Clean up
        $this->db->delete('users', ['id' => $id]);
    }

    /**
     * Test that insert() with empty data throws QueryException.
     * This tests the PostgreSQL-specific insert() override.
     */
    public function testInsertEmptyDataThrowsException(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Insert failed');

        $this->db->insert('users', []);
    }
}
