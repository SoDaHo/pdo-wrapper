<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Feature;

use Sodaho\PdoWrapper\Database;

/**
 * The tables of the security tests - users and a table of secrets an injection would reach - and
 * their rows. For a ContractTestCase.
 */
trait SecuritySchema
{
    private function createSecuritySchema(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text NOT NULL', 'email' => 'text NOT NULL', 'role' => "text DEFAULT 'user'"]);
        $this->create('secrets', ['id' => 'id', 'secret_data' => 'text NOT NULL']);
        $this->seedData();
    }

    private function seedData(): void
    {
        $this->db->insert('users', ['name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'admin']);
        $this->db->insert('users', ['name' => 'User', 'email' => 'user@example.com', 'role' => 'user']);
        $this->db->insert('secrets', ['secret_data' => 'TOP SECRET DATA']);
    }

    private function seedLikeNames(): void
    {
        foreach (['100% sure', '100 percent', 'under_score', 'underXscore', 'back\\slash'] as $i => $name) {
            $this->db->insert('users', ['name' => $name, 'email' => "like{$i}@example.com"]);
        }
    }

    /**
     * @param list<string> $expected Names a prefix search for the escaped $literal must find
     */
    private function assertLikeFinds(array $expected, string $literal): void
    {
        $pattern = Database::escapeLike($literal) . '%';

        $rows = $this->db->table('users')->whereLike('name', $pattern)->orderBy('id')->get();
        $this->assertSame($expected, array_column($rows, 'name'), "whereLike() with escaped \"{$literal}\"");

        $rows = $this->db->table('users')->where('name', 'LIKE', $pattern)->orderBy('id')->get();
        $this->assertSame($expected, array_column($rows, 'name'), "where(LIKE) with escaped \"{$literal}\"");
    }
}
