<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\PostgresDriver;

#[Group('postgres')]
class PostgresQueryBuilderTest extends TestCase
{
    private PostgresDriver $db;

    protected function setUp(): void
    {
        $this->db = Database::postgres([
            'host' => $_ENV['POSTGRES_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
            'database' => $_ENV['POSTGRES_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['POSTGRES_USERNAME'] ?? 'postgres',
            'password' => $_ENV['POSTGRES_PASSWORD'] ?? 'postgres',
        ]);

        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
        $this->db->execute('DROP TABLE IF EXISTS qb_test');
        $this->db->execute('CREATE TABLE qb_test (id SERIAL PRIMARY KEY, name VARCHAR(255), age INT)');
        $this->db->execute('CREATE TABLE qb_profiles (id SERIAL PRIMARY KEY, user_id INT, bio VARCHAR(255))');

        $this->db->insert('qb_test', ['name' => 'Max', 'age' => 25]);
        $this->db->insert('qb_test', ['name' => 'Anna', 'age' => 30]);
        $this->db->insert('qb_profiles', ['user_id' => 1, 'bio' => 'Developer']);
        $this->db->insert('qb_profiles', ['user_id' => 999, 'bio' => 'Orphan']); // No matching user
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
        $this->db->execute('DROP TABLE IF EXISTS qb_test');
    }

    public function testRightJoin(): void
    {
        $results = $this->db->table('qb_test')
            ->rightJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')
            ->get();

        $this->assertCount(2, $results); // Both profiles, one with NULL user
    }

    public function testQueryBuilderWithPostgresQuoting(): void
    {
        $users = $this->db->table('qb_test')->where('name', 'Max')->get();

        $this->assertCount(1, $users);
    }

    public function testGroupByWithArray(): void
    {
        $results = $this->db->table('qb_test')
            ->select('age')
            ->groupBy(['age'])
            ->get();

        $this->assertCount(2, $results);
    }
}
