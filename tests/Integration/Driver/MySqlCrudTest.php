<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\MySqlDriver;

/**
 * @group mysql
 */
class MySqlCrudTest extends TestCase
{
    private MySqlDriver $db;

    protected function setUp(): void
    {
        $this->db = Database::mysql([
            'host' => $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
            'database' => $_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['MYSQL_USERNAME'] ?? 'root',
            'password' => $_ENV['MYSQL_PASSWORD'] ?? 'root',
        ]);

        $this->db->execute('DROP TABLE IF EXISTS crud_test');
        $this->db->execute('CREATE TABLE crud_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), email VARCHAR(255))');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS crud_test');
    }

    public function testInsertAndFindOne(): void
    {
        $id = $this->db->insert('crud_test', [
            'name' => 'Max',
            'email' => 'max@example.com',
        ]);

        $this->assertSame('1', $id);

        $row = $this->db->findOne('crud_test', ['id' => 1]);

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

        $this->assertSame('Maximilian', $row1['name']);
        $this->assertSame('Annette', $row2['name']);
    }
}
