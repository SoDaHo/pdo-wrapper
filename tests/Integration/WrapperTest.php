<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use PDOStatement;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;

class WrapperTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = Database::sqlite();

        // Create test table
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
    }

    public function testImplementsDatabaseInterface(): void
    {
        $this->assertInstanceOf(DatabaseInterface::class, $this->db);
    }

    public function testQueryReturnsStatement(): void
    {
        $stmt = $this->db->query('SELECT 1 as test');

        $this->assertInstanceOf(PDOStatement::class, $stmt);
    }

    public function testQueryWithParams(): void
    {
        $this->db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Max', 'max@example.com']);

        $stmt = $this->db->query('SELECT * FROM users WHERE name = ?', ['Max']);
        $result = $stmt->fetch();

        $this->assertSame('Max', $result['name']);
        $this->assertSame('max@example.com', $result['email']);
    }

    public function testExecuteReturnsAffectedRows(): void
    {
        $affected = $this->db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Max', 'max@example.com']);

        $this->assertSame(1, $affected);
    }

    public function testLastInsertId(): void
    {
        $this->db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Max', 'max@example.com']);

        $id = $this->db->lastInsertId();

        $this->assertSame('1', $id);
    }

    public function testInvalidQueryThrowsQueryException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->query('SELECT * FROM nonexistent_table');
    }

    public function testQueryExceptionHasDebugMessage(): void
    {
        try {
            $this->db->query('INVALID SQL');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
            $this->assertStringContainsString('INVALID SQL', $e->getDebugMessage());
            return;
        }

        $this->fail('Expected QueryException was not thrown');
    }

    public function testQueryHookIsTriggered(): void
    {
        $hookData = null;

        $this->db->on('query', function (array $data) use (&$hookData) {
            $hookData = $data;
        });

        $this->db->query('SELECT 1 as test');

        $this->assertNotNull($hookData);
        $this->assertSame('SELECT 1 as test', $hookData['sql']);
        $this->assertArrayHasKey('duration', $hookData);
        $this->assertArrayHasKey('rows', $hookData);
    }

    public function testErrorHookIsTriggered(): void
    {
        $hookData = null;

        $this->db->on('error', function (array $data) use (&$hookData) {
            $hookData = $data;
        });

        try {
            $this->db->query('INVALID SQL');
        } catch (QueryException $e) {
            // Expected
        }

        $this->assertNotNull($hookData);
        $this->assertSame('INVALID SQL', $hookData['sql']);
        $this->assertArrayHasKey('error', $hookData);
    }
}
