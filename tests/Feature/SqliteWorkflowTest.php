<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Feature;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Tests\Feature\Concerns\AbstractWorkflowTest;

/**
 * Workflow tests for SQLite driver.
 */
class SqliteWorkflowTest extends AbstractWorkflowTest
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
            email TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            role TEXT DEFAULT "user",
            active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )';
    }

    protected function getCreatePostsTableSql(): string
    {
        return 'CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            content TEXT,
            status TEXT DEFAULT "draft",
            views INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )';
    }

    protected function getCreateCommentsTableSql(): string
    {
        return 'CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (post_id) REFERENCES posts(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        )';
    }

    protected function getCreateTagsTableSql(): string
    {
        return 'CREATE TABLE tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL
        )';
    }

    protected function getCreatePostTagsTableSql(): string
    {
        return 'CREATE TABLE post_tags (
            post_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            PRIMARY KEY (post_id, tag_id),
            FOREIGN KEY (post_id) REFERENCES posts(id),
            FOREIGN KEY (tag_id) REFERENCES tags(id)
        )';
    }
}
