<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\MySqlDriver;

#[Group('mysql')]
class MySqlQueryBuilderTest extends TestCase
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

        $this->db->execute('DROP TABLE IF EXISTS qb_test');
        $this->db->execute('CREATE TABLE qb_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), age INT)');

        $this->db->insert('qb_test', ['name' => 'Max', 'age' => 25]);
        $this->db->insert('qb_test', ['name' => 'Anna', 'age' => 30]);
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qb_test');
    }

    public function testQueryBuilderWithMySqlQuoting(): void
    {
        $users = $this->db->table('qb_test')->where('name', 'Max')->get();

        $this->assertCount(1, $users);
        $this->assertSame('Max', $users[0]['name']);
    }

    public function testToSqlUsesMySqlBackticks(): void
    {
        [$sql] = $this->db->table('qb_test')->where('id', 1)->toSql();

        $this->assertStringContainsString('`qb_test`', $sql);
        $this->assertStringContainsString('`id`', $sql);
    }

    public function testQueryBuilderJoinWithMySql(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
        $this->db->execute('CREATE TABLE qb_profiles (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, bio VARCHAR(255))');
        $this->db->insert('qb_profiles', ['user_id' => 1, 'bio' => 'Developer']);

        $results = $this->db->table('qb_test')
            ->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')
            ->get();

        $this->assertCount(2, $results);

        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
    }

    public function testRightJoin(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
        $this->db->execute('CREATE TABLE qb_profiles (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, bio VARCHAR(255))');
        $this->db->insert('qb_profiles', ['user_id' => 1, 'bio' => 'Developer']);
        $this->db->insert('qb_profiles', ['user_id' => 999, 'bio' => 'Orphan']);

        $results = $this->db->table('qb_test')
            ->rightJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')
            ->get();

        $this->assertCount(2, $results);

        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
    }
}
