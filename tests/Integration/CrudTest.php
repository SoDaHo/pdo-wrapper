<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;

class CrudTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = Database::sqlite();
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, active INTEGER DEFAULT 1)');
    }

    // =========================================================================
    // INSERT
    // =========================================================================

    public function testInsertReturnsLastInsertId(): void
    {
        $id = $this->db->insert('users', [
            'name' => 'Max',
            'email' => 'max@example.com',
        ]);

        $this->assertSame('1', $id);
    }

    public function testInsertMultipleRows(): void
    {
        $id1 = $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);
        $id2 = $this->db->insert('users', ['name' => 'Anna', 'email' => 'anna@example.com']);

        $this->assertSame('1', $id1);
        $this->assertSame('2', $id2);
    }

    public function testInsertEmptyDataThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->insert('users', []);
    }

    public function testInsertEmptyDataExceptionHasDebugMessage(): void
    {
        try {
            $this->db->insert('users', []);
        } catch (QueryException $e) {
            $this->assertSame('Insert failed', $e->getMessage());
            $this->assertStringContainsString('empty', $e->getDebugMessage());
            return;
        }

        $this->fail('Expected QueryException was not thrown');
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    public function testUpdateReturnsAffectedRows(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);

        $affected = $this->db->update('users', ['name' => 'Maximilian'], ['id' => 1]);

        $this->assertSame(1, $affected);
    }

    public function testUpdateChangesData(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);

        $this->db->update('users', ['name' => 'Maximilian'], ['id' => 1]);

        $user = $this->db->findOne('users', ['id' => 1]);
        $this->assertSame('Maximilian', $user['name']);
    }

    public function testUpdateMultipleColumns(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);

        $this->db->update('users', ['name' => 'Maximilian', 'email' => 'maximilian@example.com'], ['id' => 1]);

        $user = $this->db->findOne('users', ['id' => 1]);
        $this->assertSame('Maximilian', $user['name']);
        $this->assertSame('maximilian@example.com', $user['email']);
    }

    public function testUpdateWithMultipleWhereConditions(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com', 'active' => 1]);
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max2@example.com', 'active' => 0]);

        $affected = $this->db->update('users', ['email' => 'updated@example.com'], ['name' => 'Max', 'active' => 1]);

        $this->assertSame(1, $affected);
    }

    public function testUpdateEmptyDataThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->update('users', [], ['id' => 1]);
    }

    public function testUpdateEmptyWhereThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->update('users', ['name' => 'Max'], []);
    }

    public function testUpdateEmptyWhereExceptionHasDebugMessage(): void
    {
        try {
            $this->db->update('users', ['name' => 'Max'], []);
        } catch (QueryException $e) {
            $this->assertSame('Update failed', $e->getMessage());
            $this->assertStringContainsString('WHERE', $e->getDebugMessage());
            return;
        }

        $this->fail('Expected QueryException was not thrown');
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    public function testDeleteReturnsAffectedRows(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);

        $affected = $this->db->delete('users', ['id' => 1]);

        $this->assertSame(1, $affected);
    }

    public function testDeleteRemovesRow(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);

        $this->db->delete('users', ['id' => 1]);

        $user = $this->db->findOne('users', ['id' => 1]);
        $this->assertNull($user);
    }

    public function testDeleteWithMultipleWhereConditions(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com', 'active' => 1]);
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max2@example.com', 'active' => 0]);

        $affected = $this->db->delete('users', ['name' => 'Max', 'active' => 0]);

        $this->assertSame(1, $affected);

        $users = $this->db->findAll('users');
        $this->assertCount(1, $users);
    }

    public function testDeleteEmptyWhereThrowsException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->delete('users', []);
    }

    public function testDeleteEmptyWhereExceptionHasDebugMessage(): void
    {
        try {
            $this->db->delete('users', []);
        } catch (QueryException $e) {
            $this->assertSame('Delete failed', $e->getMessage());
            $this->assertStringContainsString('WHERE', $e->getDebugMessage());
            return;
        }

        $this->fail('Expected QueryException was not thrown');
    }

    // =========================================================================
    // FIND ONE
    // =========================================================================

    public function testFindOneReturnsRow(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);

        $user = $this->db->findOne('users', ['id' => 1]);

        $this->assertSame('Max', $user['name']);
        $this->assertSame('max@example.com', $user['email']);
    }

    public function testFindOneReturnsNullWhenNotFound(): void
    {
        $user = $this->db->findOne('users', ['id' => 999]);

        $this->assertNull($user);
    }

    public function testFindOneWithMultipleConditions(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com', 'active' => 1]);
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max2@example.com', 'active' => 0]);

        $user = $this->db->findOne('users', ['name' => 'Max', 'active' => 1]);

        $this->assertSame('max@example.com', $user['email']);
    }

    public function testFindOneReturnsOnlyFirstRow(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max1@example.com']);
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max2@example.com']);

        $user = $this->db->findOne('users', ['name' => 'Max']);

        $this->assertSame('max1@example.com', $user['email']);
    }

    // =========================================================================
    // FIND ALL
    // =========================================================================

    public function testFindAllReturnsAllRows(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);
        $this->db->insert('users', ['name' => 'Anna', 'email' => 'anna@example.com']);

        $users = $this->db->findAll('users');

        $this->assertCount(2, $users);
    }

    public function testFindAllWithWhereCondition(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com', 'active' => 1]);
        $this->db->insert('users', ['name' => 'Anna', 'email' => 'anna@example.com', 'active' => 0]);

        $users = $this->db->findAll('users', ['active' => 1]);

        $this->assertCount(1, $users);
        $this->assertSame('Max', $users[0]['name']);
    }

    public function testFindAllReturnsEmptyArrayWhenNoMatch(): void
    {
        $users = $this->db->findAll('users', ['active' => 1]);

        $this->assertSame([], $users);
    }

    public function testFindAllWithoutWhereReturnsAll(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);
        $this->db->insert('users', ['name' => 'Anna', 'email' => 'anna@example.com']);

        $users = $this->db->findAll('users', []);

        $this->assertCount(2, $users);
    }

    // =========================================================================
    // UPDATE MULTIPLE
    // =========================================================================

    public function testUpdateMultipleReturnsAffectedRows(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);
        $this->db->insert('users', ['name' => 'Anna', 'email' => 'anna@example.com']);

        $affected = $this->db->updateMultiple('users', [
            ['id' => 1, 'name' => 'Maximilian'],
            ['id' => 2, 'name' => 'Annette'],
        ]);

        $this->assertSame(2, $affected);
    }

    public function testUpdateMultipleChangesData(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);
        $this->db->insert('users', ['name' => 'Anna', 'email' => 'anna@example.com']);

        $this->db->updateMultiple('users', [
            ['id' => 1, 'name' => 'Maximilian'],
            ['id' => 2, 'name' => 'Annette'],
        ]);

        $user1 = $this->db->findOne('users', ['id' => 1]);
        $user2 = $this->db->findOne('users', ['id' => 2]);

        $this->assertSame('Maximilian', $user1['name']);
        $this->assertSame('Annette', $user2['name']);
    }

    public function testUpdateMultipleWithCustomKeyColumn(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);
        $this->db->insert('users', ['name' => 'Anna', 'email' => 'anna@example.com']);

        $affected = $this->db->updateMultiple('users', [
            ['email' => 'max@example.com', 'name' => 'Maximilian'],
            ['email' => 'anna@example.com', 'name' => 'Annette'],
        ], 'email');

        $this->assertSame(2, $affected);
    }

    public function testUpdateMultipleEmptyArrayReturnsZero(): void
    {
        $affected = $this->db->updateMultiple('users', []);

        $this->assertSame(0, $affected);
    }

    public function testUpdateMultipleMissingKeyColumnThrowsException(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);

        $this->expectException(QueryException::class);

        $this->db->updateMultiple('users', [
            ['name' => 'Maximilian'], // Missing 'id'
        ]);
    }

    public function testUpdateMultipleSkipsRowsWithOnlyKeyColumn(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'email' => 'max@example.com']);

        $affected = $this->db->updateMultiple('users', [
            ['id' => 1], // Only key, no data to update
        ]);

        $this->assertSame(0, $affected);
    }
}
