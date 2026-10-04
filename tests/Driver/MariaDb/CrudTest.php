<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

class CrudTest extends TestCase
{
    private MySqlDriver $db;

    protected function setUp(): void
    {
        $this->db = Database::mysql(TestEnvironment::mysql());

        $this->db->execute('DROP TABLE IF EXISTS crud_test');
        $this->db->execute('CREATE TABLE crud_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), email VARCHAR(255))');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS crud_test');
    }

    /**
     * A BIGINT UNSIGNED column counts on above PHP_INT_MAX, and the server reports that ID as it
     * is: insert() throws instead of returning a cut number. The row is there.
     */
    public function testInsertThrowsForAnIdAbovePhpIntMax(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS crud_big');
        $this->db->execute('CREATE TABLE crud_big (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20))');

        try {
            $this->assertSame(PHP_INT_MAX, $this->db->insert('crud_big', ['id' => PHP_INT_MAX, 'name' => 'fits']));

            try {
                $this->db->insert('crud_big', ['name' => 'one more']);
                $this->fail('Expected QueryException');
            } catch (QueryException $e) {
                $this->assertSame('Insert ID out of range', $e->getMessage());
                $this->assertStringContainsString('The row was inserted', $e->getDebugMessage() ?? '');
                $this->assertStringContainsString('"9223372036854775808"', $e->getDebugMessage() ?? '');
                $this->assertNull($e->sqlState, 'no failure of the database');
            }

            $this->assertSame(2, $this->db->table('crud_big')->count(), 'the row is there');
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS crud_big');
        }
    }

    /**
     * The server reports a negative ID written into an AUTO_INCREMENT column as an unsigned
     * number (-5 as 2^64 - 5), which nobody can tell from an ID of that size: it throws as well.
     */
    public function testInsertThrowsForANegativeIdInAnAutoIncrementColumn(): void
    {
        try {
            $this->db->insert('crud_test', ['id' => -5, 'name' => 'Minus']);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Insert ID out of range', $e->getMessage());
            $this->assertStringContainsString('"18446744073709551611"', $e->getDebugMessage() ?? '');
        }

        $this->assertSame('Minus', $this->db->findOne('crud_test', ['id' => -5])['name'] ?? null, 'the row is there');
    }

    public function testInsertReturnsZeroForATableWithoutAutoIncrement(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS crud_plain');
        $this->db->execute('CREATE TABLE crud_plain (id INT PRIMARY KEY, name VARCHAR(20))');

        try {
            $this->assertSame(0, $this->db->insert('crud_plain', ['id' => 7, 'name' => 'Plain']));
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS crud_plain');
        }
    }

    public function testInsertAndFindOne(): void
    {
        $id = $this->db->insert('crud_test', [
            'name' => 'Max',
            'email' => 'max@example.com',
        ]);

        $this->assertSame(1, $id);

        $row = $this->db->findOne('crud_test', ['id' => 1]);

        $this->assertNotNull($row);
        $this->assertSame('Max', $row['name']);
        $this->assertSame('max@example.com', $row['email']);
    }

    public function testUpdateAndFindAll(): void
    {
        $this->db->insert('crud_test', ['name' => 'Max', 'email' => 'max@example.com']);
        $this->db->insert('crud_test', ['name' => 'Anna', 'email' => 'anna@example.com']);

        $this->db->update('crud_test', ['name' => 'Maximilian'], ['id' => 1]);

        $rows = $this->db->findAll('crud_test');

        $this->assertCount(2, $rows);
        $this->assertSame('Maximilian', $rows[0]['name']);
    }

    public function testDelete(): void
    {
        $this->db->insert('crud_test', ['name' => 'Max', 'email' => 'max@example.com']);

        $affected = $this->db->delete('crud_test', ['id' => 1]);

        $this->assertSame(1, $affected);
        $this->assertNull($this->db->findOne('crud_test', ['id' => 1]));
    }

    public function testUpdateMultiple(): void
    {
        $this->db->insert('crud_test', ['name' => 'Max', 'email' => 'max@example.com']);
        $this->db->insert('crud_test', ['name' => 'Anna', 'email' => 'anna@example.com']);

        $affected = $this->db->updateMultiple('crud_test', [
            ['id' => 1, 'name' => 'Maximilian'],
            ['id' => 2, 'name' => 'Annette'],
        ]);

        $this->assertSame(2, $affected);

        $row1 = $this->db->findOne('crud_test', ['id' => 1]);
        $row2 = $this->db->findOne('crud_test', ['id' => 2]);

        $this->assertNotNull($row1);
        $this->assertNotNull($row2);
        $this->assertSame('Maximilian', $row1['name']);
        $this->assertSame('Annette', $row2['name']);
    }
}
