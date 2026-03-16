<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Feature;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Tests\Feature\Concerns\AbstractSecurityTest;

/**
 * Security tests for MySQL driver.
 *
 * @group mysql
 */

class MySqlSecurityTest extends AbstractSecurityTest
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
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT "user"
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    protected function getCreateSecretsTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS secrets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            secret_data TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    // MySQL-specific: Test backslash escaping (MySQL uses backslash as escape)
    public function testMySqlBackslashEscaping(): void
    {
        $nameWithBackslash = 'Test\\Name';
        $this->db->insert('users', ['name' => $nameWithBackslash, 'email' => 'backslash@example.com']);

        $user = $this->db->table('users')->where('name', $nameWithBackslash)->first();
        $this->assertNotNull($user);
        $this->assertSame($nameWithBackslash, $user['name']);
    }

    // MySQL-specific: Test that binary data in utf8mb4 TEXT field is rejected
    // (This is expected - for binary data use BLOB type)
    public function testMySqlBinaryDataInTextFieldIsRejected(): void
    {
        $binaryData = "\xFF\xFE"; // Invalid UTF-8 sequence

        $this->expectException(\Sodaho\PdoWrapper\Exception\QueryException::class);
        $this->db->insert('secrets', ['secret_data' => $binaryData]);
    }
}
