<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Tests\Feature\Concerns\AbstractSecurityTest;

/**
 * Security tests for PostgreSQL driver.
 */
#[Group('postgres')]
class PostgresSecurityTest extends AbstractSecurityTest
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
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT \'user\'
        )';
    }

    protected function getCreateSecretsTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS secrets (
            id SERIAL PRIMARY KEY,
            secret_data TEXT NOT NULL
        )';
    }

    // PostgreSQL-specific: Test dollar quoting attempt
    public function testPostgresDollarQuotingInjection(): void
    {
        $maliciousInput = 'test$$; DROP TABLE users; $$';

        $this->db->insert('users', ['name' => $maliciousInput, 'email' => 'dollar@example.com']);

        // Table should still exist
        $users = $this->db->table('users')->get();
        $this->assertGreaterThan(0, count($users));

        // Value should be stored literally
        $user = $this->db->table('users')->where('name', $maliciousInput)->first();
        $this->assertNotNull($user);
    }

    // PostgreSQL-specific: Test bytea (binary) data
    public function testPostgresBinaryData(): void
    {
        $binaryData = "\x00\x01\x02\xFF\xFE";
        $this->db->insert('secrets', ['secret_data' => $binaryData]);

        // Just verify it doesn't crash - PostgreSQL may encode differently
        $secrets = $this->db->table('secrets')->get();
        $this->assertGreaterThan(0, count($secrets));
    }
}
