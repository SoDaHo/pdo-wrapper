<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Feature;

use Sodaho\PdoWrapper\Database;

/**
 * The tables of the workflow tests: users, their posts and comments, tags. For a ContractTestCase.
 */
trait WorkflowSchema
{
    private function createWorkflowSchema(): void
    {
        $this->create('users', ['id' => 'id', 'email' => 'text UNIQUE NOT NULL', 'name' => 'text NOT NULL', 'role' => "text DEFAULT 'user'", 'active' => 'int DEFAULT 1', 'created_at' => 'timestamp DEFAULT CURRENT_TIMESTAMP']);
        $this->create('posts', ['id' => 'id', 'user_id' => 'bigint NOT NULL', 'title' => 'text NOT NULL', 'content' => 'text', 'status' => "text DEFAULT 'draft'", 'views' => 'int DEFAULT 0', 'created_at' => 'timestamp DEFAULT CURRENT_TIMESTAMP', 'FOREIGN KEY (user_id) REFERENCES users(id)']);
        $this->create('comments', ['id' => 'id', 'post_id' => 'bigint NOT NULL', 'user_id' => 'bigint NOT NULL', 'content' => 'text NOT NULL', 'created_at' => 'timestamp DEFAULT CURRENT_TIMESTAMP', 'FOREIGN KEY (post_id) REFERENCES posts(id)', 'FOREIGN KEY (user_id) REFERENCES users(id)']);
        $this->create('tags', ['id' => 'id', 'name' => 'text UNIQUE NOT NULL']);
        $this->create('post_tags', ['post_id' => 'bigint NOT NULL', 'tag_id' => 'bigint NOT NULL', 'PRIMARY KEY (post_id, tag_id)', 'FOREIGN KEY (post_id) REFERENCES posts(id)', 'FOREIGN KEY (tag_id) REFERENCES tags(id)']);
    }

    private function assertJoinLikeMatchesTheEscapedPatternOnly(): void
    {
        foreach ([['a@test.com', '100% sure'], ['b@test.com', '1000 lines'], ['c@test.com', '100% done']] as [$email, $name]) {
            $this->db->insert('users', ['email' => $email, 'name' => $name]);
        }
        $this->db->insert('tags', ['name' => Database::escapeLike('100%') . '%']);

        $matching = fn () => $this->db->table('users')->join('tags', 'users.name', 'LIKE', 'tags.name');

        $this->assertSame(['100% sure', '100% done'], array_column($matching()->select('users.name')->orderBy('users.id')->get(), 'name'));
        $this->assertSame(2, $matching()->count());
        $this->assertTrue($matching()->where('users.email', 'c@test.com')->exists());
        $this->assertFalse($matching()->where('users.email', 'b@test.com')->exists());
        $this->assertSame(2, $matching()->select('users.name')->distinct()->count());
        $this->assertSame(2, $matching()->groupBy('users.id')->count());
        $this->assertSame(
            ['1000 lines'],
            array_column($this->db->table('users')->select('users.name')->join('tags', 'users.name', 'NOT LIKE', 'tags.name')->get(), 'name')
        );
    }
}
