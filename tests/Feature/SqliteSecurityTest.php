<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Feature;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Tests\Feature\Concerns\AbstractSecurityTest;

/**
 * Security tests for SQLite driver.
 */

class SqliteSecurityTest extends AbstractSecurityTest
{
    protected function createDatabase(): DatabaseInterface
    {
        return Database::sqlite(':memory:');
    }

    protected function tearDown(): void
    {
        // SQLite in-memory doesn't need cleanup
    }

    protected function getCreateUsersTableSql(): string
    {
        return 'CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            role TEXT DEFAULT "user"
        )';
    }

    protected function getCreateSecretsTableSql(): string
    {
        return 'CREATE TABLE secrets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            secret_data TEXT NOT NULL
        )';
    }

    // SQLite-specific: Test null byte handling (SQLite handles this differently)
    public function testNullByteInValue(): void
    {
        $nameWithNull = "Null\x00Byte";
        $this->db->insert('users', ['name' => $nameWithNull, 'email' => 'nullbyte@example.com']);

        // SQLite may truncate at null byte or store it - just verify no crash
        $user = $this->db->table('users')->where('email', 'nullbyte@example.com')->first();
        $this->assertNotNull($user);
    }
}
