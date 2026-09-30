<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Feature\Concerns;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;

/**
 * Abstract base class for workflow tests.
 * Runs identical tests against all database drivers.
 */
abstract class AbstractWorkflowTest extends TestCase
{
    protected DatabaseInterface $db;

    abstract protected function createDatabase(): DatabaseInterface;
    abstract protected function getCreateUsersTableSql(): string;
    abstract protected function getCreatePostsTableSql(): string;
    abstract protected function getCreateCommentsTableSql(): string;
    abstract protected function getCreateTagsTableSql(): string;
    abstract protected function getCreatePostTagsTableSql(): string;

    /**
     * Table "deferred_children" whose foreign key to users is checked at COMMIT,
     * or null where the database has no deferred constraints.
     */
    abstract protected function getCreateDeferredChildrenTableSql(): ?string;

    /**
     * Whether the transaction is still open after the database rejected a COMMIT.
     */
    abstract protected function failedCommitKeepsTransactionOpen(): bool;

    protected function setUp(): void
    {
        $this->db = $this->createDatabase();
        $this->createSchema();
    }

    protected function createSchema(): void
    {
        $this->db->execute($this->getCreateUsersTableSql());
        $this->db->execute($this->getCreatePostsTableSql());
        $this->db->execute($this->getCreateCommentsTableSql());
        $this->db->execute($this->getCreateTagsTableSql());
        $this->db->execute($this->getCreatePostTagsTableSql());
    }

    protected function tearDown(): void
    {
        // A transaction left open by a failing test must not block the DROPs (the job would hang, not fail).
        if ($this->db->getPdo()->inTransaction()) {
            $this->db->getPdo()->rollBack();
        }

        // Clean up tables in reverse order (due to foreign keys)
        $this->db->execute('DROP TABLE IF EXISTS deferred_children');
        $this->db->execute('DROP TABLE IF EXISTS post_tags');
        $this->db->execute('DROP TABLE IF EXISTS tags');
        $this->db->execute('DROP TABLE IF EXISTS comments');
        $this->db->execute('DROP TABLE IF EXISTS posts');
        $this->db->execute('DROP TABLE IF EXISTS users');
    }

    // =========================================================================
    // USER REGISTRATION WORKFLOW
    // =========================================================================

    public function testUserRegistrationWorkflow(): void
    {
        // 1. Check if email exists
        $existing = $this->db->table('users')
            ->where('email', 'new@example.com')
            ->first();
        $this->assertNull($existing);

        // 2. Register user
        $userId = $this->db->insert('users', [
            'email' => 'new@example.com',
            'name' => 'New User',
            'role' => 'user',
        ]);
        $this->assertNotEmpty($userId);

        // 3. Fetch user
        $user = $this->db->table('users')
            ->where('id', $userId)
            ->first();
        $this->assertSame('new@example.com', $user['email']);

        // 4. Verify can't register duplicate email
        $this->expectException(QueryException::class);
        $this->db->insert('users', [
            'email' => 'new@example.com',
            'name' => 'Duplicate User',
        ]);
    }

    // =========================================================================
    // BLOG POST WORKFLOW
    // =========================================================================

    public function testBlogPostCreationAndPublishingWorkflow(): void
    {
        // Setup: Create author
        $authorId = $this->db->insert('users', [
            'email' => 'author@example.com',
            'name' => 'Author',
        ]);

        // 1. Create draft post
        $postId = $this->db->insert('posts', [
            'user_id' => $authorId,
            'title' => 'My First Post',
            'content' => 'Hello World!',
            'status' => 'draft',
        ]);

        // 2. Verify it's a draft
        $post = $this->db->findOne('posts', ['id' => $postId]);
        $this->assertSame('draft', $post['status']);

        // 3. Publish the post
        $this->db->table('posts')
            ->where('id', $postId)
            ->update(['status' => 'published']);

        // 4. Verify published
        $post = $this->db->findOne('posts', ['id' => $postId]);
        $this->assertSame('published', $post['status']);

        // 5. Increment views
        $this->db->execute(
            'UPDATE posts SET views = views + 1 WHERE id = ?',
            [$postId]
        );

        $post = $this->db->findOne('posts', ['id' => $postId]);
        $this->assertSame(1, (int)$post['views']);
    }

    public function testBlogPostWithTagsWorkflow(): void
    {
        // Setup
        $authorId = $this->db->insert('users', ['email' => 'a@b.com', 'name' => 'A']);
        $postId = $this->db->insert('posts', [
            'user_id' => $authorId,
            'title' => 'Tagged Post',
            'content' => 'Content',
        ]);

        // 1. Create tags
        $tag1Id = $this->db->insert('tags', ['name' => 'PHP']);
        $tag2Id = $this->db->insert('tags', ['name' => 'Database']);

        // 2. Associate tags with post (junction table has no 'id' column, use execute)
        $this->db->execute('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$postId, $tag1Id]);
        $this->db->execute('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$postId, $tag2Id]);

        // 3. Get post with tags using join
        $postTags = $this->db->table('post_tags')
            ->select('tags.name')
            ->join('tags', 'tags.id', '=', 'post_tags.tag_id')
            ->where('post_tags.post_id', $postId)
            ->get();

        $this->assertCount(2, $postTags);
        $tagNames = array_column($postTags, 'name');
        $this->assertContains('PHP', $tagNames);
        $this->assertContains('Database', $tagNames);
    }

    // =========================================================================
    // COMMENT SYSTEM WORKFLOW
    // =========================================================================

    public function testCommentingWorkflow(): void
    {
        // Setup
        $authorId = $this->db->insert('users', ['email' => 'author@test.com', 'name' => 'Author']);
        $commenterId = $this->db->insert('users', ['email' => 'commenter@test.com', 'name' => 'Commenter']);
        $postId = $this->db->insert('posts', [
            'user_id' => $authorId,
            'title' => 'Post',
            'content' => 'Content',
            'status' => 'published',
        ]);

        // 1. Add comments
        $this->db->insert('comments', [
            'post_id' => $postId,
            'user_id' => $commenterId,
            'content' => 'Great post!',
        ]);

        $this->db->insert('comments', [
            'post_id' => $postId,
            'user_id' => $authorId,
            'content' => 'Thanks!',
        ]);

        // 2. Get comments count
        $count = $this->db->table('comments')
            ->where('post_id', $postId)
            ->count();
        $this->assertSame(2, $count);

        // 3. Get comments with user names
        $comments = $this->db->table('comments')
            ->select(['comments.content', 'users.name as author_name'])
            ->leftJoin('users', 'users.id', '=', 'comments.user_id')
            ->where('comments.post_id', $postId)
            ->orderBy('comments.id')
            ->get();

        $this->assertCount(2, $comments);
        $this->assertSame('Commenter', $comments[0]['author_name']);
        $this->assertSame('Author', $comments[1]['author_name']);
    }

    // =========================================================================
    // TRANSACTION WORKFLOW
    // =========================================================================

    public function testTransactionCommitWorkflow(): void
    {
        $this->db->transaction(function () {
            $userId = $this->db->insert('users', [
                'email' => 'transaction@test.com',
                'name' => 'Transaction User',
            ]);

            $this->db->insert('posts', [
                'user_id' => $userId,
                'title' => 'Transaction Post',
                'content' => 'Created in transaction',
            ]);
        });

        // Both should be committed
        $user = $this->db->table('users')->where('email', 'transaction@test.com')->first();
        $this->assertNotNull($user);

        $post = $this->db->table('posts')->where('user_id', $user['id'])->first();
        $this->assertNotNull($post);
    }

    public function testTransactionRollbackWorkflow(): void
    {
        try {
            $this->db->transaction(function () {
                $this->db->insert('users', [
                    'email' => 'rollback@test.com',
                    'name' => 'Rollback User',
                ]);

                // Force an error
                throw new \RuntimeException('Simulated error');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // User should NOT exist due to rollback
        $user = $this->db->table('users')->where('email', 'rollback@test.com')->first();
        $this->assertNull($user);
    }

    // =========================================================================
    // COMMIT PHASE WORKFLOW
    // =========================================================================

    public function testCommitHookFailureKeepsCommittedData(): void
    {
        $events = [];
        $hookError = new \RuntimeException('Simulated hook error');
        $this->db->on('transaction.commit', static fn () => throw $hookError);
        $this->db->on('transaction.rollback', static function () use (&$events) {
            $events[] = 'rollback';
        });

        try {
            $this->db->transaction(function () {
                $this->db->insert('users', ['email' => 'hook@test.com', 'name' => 'Hook User']);
            });
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertSame($hookError, $e->getPrevious());
            $this->assertSame([$hookError], $e->failures);
        }

        $this->assertSame([], $events);
        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertNotNull($this->db->table('users')->where('email', 'hook@test.com')->first());
    }

    public function testCallbackFailureRunsNoCommitHook(): void
    {
        $events = [];
        $original = new \RuntimeException('Simulated error');
        $this->db->on('transaction.commit', static function () use (&$events) {
            $events[] = 'commit';
        });

        try {
            $this->db->transaction(function () use ($original) {
                $this->db->insert('users', ['email' => 'callback@test.com', 'name' => 'Callback User']);
                throw $original;
            });
            $this->fail('Expected the callback exception');
        } catch (\RuntimeException $e) {
            $this->assertSame($original, $e);
        }

        $this->assertSame([], $events);
        $this->assertNull($this->db->table('users')->where('email', 'callback@test.com')->first());
    }

    public function testManualCommitReportsCommitHookFailure(): void
    {
        $this->db->on('transaction.commit', static fn () => throw new \LogicException('Simulated hook error'));
        $this->db->beginTransaction();
        $this->db->insert('users', ['email' => 'manual@test.com', 'name' => 'Manual User']);

        try {
            $this->db->commit();
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(1, $e->failures);
        }

        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertNotNull($this->db->table('users')->where('email', 'manual@test.com')->first());
    }

    public function testTransactionReturnValueWithCommitListener(): void
    {
        $calls = 0;
        $this->db->on('transaction.commit', static function () use (&$calls) {
            $calls++;
        });

        $result = $this->db->transaction(fn () => $this->db->insert('users', ['email' => 'result@test.com', 'name' => 'Result User']));

        $user = $this->db->table('users')->where('email', 'result@test.com')->first();
        $this->assertSame((string) $user['id'], (string) $result);
        $this->assertSame(1, $calls);
    }

    public function testFailedCommitIsATransactionException(): void
    {
        $sql = $this->getCreateDeferredChildrenTableSql();

        if ($sql === null) {
            $this->markTestSkipped('No deferred constraints: the database cannot reject a COMMIT here (see the PDO subclass test of this driver).');
        }

        $this->db->execute($sql);
        $events = [];
        $this->db->on('transaction.commit', static function () use (&$events) {
            $events[] = 'commit';
        });
        $this->db->on('transaction.rollback', static function () use (&$events) {
            $events[] = 'rollback';
        });

        try {
            $this->db->transaction(fn () => $this->db->execute('INSERT INTO deferred_children (id, user_id) VALUES (1, 999999)'));
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
        }

        // A rollback (and its hook) only where the driver keeps the rejected transaction open.
        $this->assertSame($this->failedCommitKeepsTransactionOpen() ? ['rollback'] : [], $events);
        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertSame(0, $this->db->table('deferred_children')->count());
    }

    public function testAllCommitListenersRun(): void
    {
        $first = new \RuntimeException('first');
        $second = new \RuntimeException('second');
        $this->db->on('transaction.commit', static fn () => throw $first);
        $this->db->on('transaction.commit', static fn () => throw $second);

        try {
            $this->db->transaction(function () {
                $this->db->insert('users', ['email' => 'all@test.com', 'name' => 'All Listeners']);
            });
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertSame([$first, $second], $e->failures);
            $this->assertSame($first, $e->getPrevious());
        }

        $this->assertNotNull($this->db->table('users')->where('email', 'all@test.com')->first());
    }

    public function testTransactionLeftOpenByCommitListenerIsRolledBack(): void
    {
        $events = [];
        $this->db->on('transaction.rollback', static function () use (&$events) {
            $events[] = 'rollback';
        });
        $this->db->on('transaction.commit', function () {
            $this->db->beginTransaction();
            $this->db->insert('users', ['email' => 'listener@test.com', 'name' => 'Listener User']);
        });

        try {
            $this->db->transaction(function () {
                $this->db->insert('users', ['email' => 'owner@test.com', 'name' => 'Owner User']);
            });
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(1, $e->failures);
            $this->assertSame('listener left a transaction open', $e->failures[0]->getMessage());
        }

        $this->assertSame([], $events);
        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertNotNull($this->db->table('users')->where('email', 'owner@test.com')->first());
        $this->assertNull($this->db->table('users')->where('email', 'listener@test.com')->first());
    }

    public function testThrowingRollbackHookKeepsCallbackException(): void
    {
        $original = new \RuntimeException('Simulated error');
        $this->db->on('transaction.rollback', static fn () => throw new \RuntimeException('Simulated rollback hook error'));

        try {
            $this->db->transaction(function () use ($original) {
                $this->db->insert('users', ['email' => 'rollbackhook@test.com', 'name' => 'Rollback Hook']);
                throw $original;
            });
            $this->fail('Expected the callback exception');
        } catch (\RuntimeException $e) {
            $this->assertSame($original, $e);
        }

        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertNull($this->db->table('users')->where('email', 'rollbackhook@test.com')->first());
    }

    public function testUpdateMultipleReportsCommitHookFailure(): void
    {
        $id = $this->db->insert('users', ['email' => 'bulk@test.com', 'name' => 'Before']);
        $this->db->on('transaction.commit', static fn () => throw new \RuntimeException('Simulated hook error'));

        try {
            $this->db->updateMultiple('users', [['id' => $id, 'name' => 'After']]);
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $this->assertCount(1, $e->failures);
        }

        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertSame('After', $this->db->findOne('users', ['id' => $id])['name'] ?? null);
    }

    public function testCommitListenerRunningItsOwnTransaction(): void
    {
        $events = [];
        $innerError = new \RuntimeException('inner listener');
        $nested = false;
        $thrown = false;
        $this->db->on('transaction.rollback', static function () use (&$events) {
            $events[] = 'rollback';
        });
        $this->db->on('transaction.commit', function () use (&$nested) {
            if (!$nested) {
                $nested = true;
                $this->db->transaction(fn () => $this->db->insert('users', ['email' => 'inner@test.com', 'name' => 'Inner']));
            }
        });
        $this->db->on('transaction.commit', static function () use ($innerError, &$nested, &$thrown) {
            if ($nested && !$thrown) {
                $thrown = true;
                throw $innerError;
            }
        });

        try {
            $this->db->transaction(fn () => $this->db->insert('users', ['email' => 'outer@test.com', 'name' => 'Outer']));
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $inner = $e->getPrevious();
            $this->assertInstanceOf(CommitHookException::class, $inner);
            $this->assertSame($innerError, $inner->getPrevious());
        }

        $this->assertSame([], $events);
        $this->assertFalse($this->db->getPdo()->inTransaction());
        $this->assertSame(2, $this->db->table('users')->whereIn('email', ['inner@test.com', 'outer@test.com'])->count());
    }

    // =========================================================================
    // BULK OPERATIONS WORKFLOW
    // =========================================================================

    public function testBulkUpdateWorkflow(): void
    {
        // Setup: Create multiple users
        $this->db->insert('users', ['email' => 'user1@test.com', 'name' => 'User 1', 'active' => 1]);
        $this->db->insert('users', ['email' => 'user2@test.com', 'name' => 'User 2', 'active' => 1]);
        $this->db->insert('users', ['email' => 'user3@test.com', 'name' => 'User 3', 'active' => 0]);

        // Deactivate all users with @test.com emails
        $affected = $this->db->table('users')
            ->whereLike('email', '%@test.com')
            ->where('active', 1)
            ->update(['active' => 0]);

        $this->assertSame(2, $affected);

        // Verify
        $activeCount = $this->db->table('users')
            ->where('active', 1)
            ->count();
        $this->assertSame(0, $activeCount);
    }

    public function testBulkDeleteWorkflow(): void
    {
        // Setup
        $userId = $this->db->insert('users', ['email' => 'delete@test.com', 'name' => 'Delete Me']);

        for ($i = 0; $i < 5; $i++) {
            $this->db->insert('posts', [
                'user_id' => $userId,
                'title' => "Post $i",
                'content' => 'Content',
                'status' => 'draft',
            ]);
        }

        // Delete all drafts for this user
        $deleted = $this->db->table('posts')
            ->where('user_id', $userId)
            ->where('status', 'draft')
            ->delete();

        $this->assertSame(5, $deleted);

        // Verify no posts remain
        $remaining = $this->db->table('posts')->where('user_id', $userId)->count();
        $this->assertSame(0, $remaining);
    }

    // =========================================================================
    // PAGINATION WORKFLOW
    // =========================================================================

    public function testPaginationWorkflow(): void
    {
        // Setup: Create 25 users
        for ($i = 1; $i <= 25; $i++) {
            $this->db->insert('users', [
                'email' => "user{$i}@test.com",
                'name' => "User {$i}",
            ]);
        }

        $perPage = 10;

        // Page 1
        $page1 = $this->db->table('users')
            ->orderBy('id')
            ->limit($perPage)
            ->offset(0)
            ->get();
        $this->assertCount(10, $page1);
        $this->assertSame('User 1', $page1[0]['name']);

        // Page 2
        $page2 = $this->db->table('users')
            ->orderBy('id')
            ->limit($perPage)
            ->offset(10)
            ->get();
        $this->assertCount(10, $page2);
        $this->assertSame('User 11', $page2[0]['name']);

        // Page 3 (partial)
        $page3 = $this->db->table('users')
            ->orderBy('id')
            ->limit($perPage)
            ->offset(20)
            ->get();
        $this->assertCount(5, $page3);
        $this->assertSame('User 21', $page3[0]['name']);

        // Total count for pagination info
        $total = $this->db->table('users')->count();
        $this->assertSame(25, $total);
    }

    // =========================================================================
    // SEARCH WORKFLOW
    // =========================================================================

    public function testSearchWorkflow(): void
    {
        // Setup
        $this->db->insert('users', ['email' => 'john.doe@test.com', 'name' => 'John Doe']);
        $this->db->insert('users', ['email' => 'jane.doe@test.com', 'name' => 'Jane Doe']);
        $this->db->insert('users', ['email' => 'bob.smith@test.com', 'name' => 'Bob Smith']);

        // Search by name pattern
        $doeUsers = $this->db->table('users')
            ->whereLike('name', '%Doe')
            ->orderBy('name')
            ->get();

        $this->assertCount(2, $doeUsers);
        $this->assertSame('Jane Doe', $doeUsers[0]['name']);
        $this->assertSame('John Doe', $doeUsers[1]['name']);

        // Search by email domain
        $testUsers = $this->db->table('users')
            ->whereLike('email', '%@test.com')
            ->count();

        $this->assertSame(3, $testUsers);
    }

    // =========================================================================
    // AGGREGATION WORKFLOW
    // =========================================================================

    public function testAggregationWorkflow(): void
    {
        // Setup
        $userId = $this->db->insert('users', ['email' => 'stats@test.com', 'name' => 'Stats User']);

        $this->db->insert('posts', ['user_id' => $userId, 'title' => 'Post 1', 'content' => 'C', 'views' => 100]);
        $this->db->insert('posts', ['user_id' => $userId, 'title' => 'Post 2', 'content' => 'C', 'views' => 250]);
        $this->db->insert('posts', ['user_id' => $userId, 'title' => 'Post 3', 'content' => 'C', 'views' => 50]);

        // Total posts
        $this->assertSame(3, $this->db->table('posts')->where('user_id', $userId)->count());

        // Total views
        $this->assertEquals(400, $this->db->table('posts')->where('user_id', $userId)->sum('views'));

        // Average views
        $avg = $this->db->table('posts')->where('user_id', $userId)->avg('views');
        $this->assertEqualsWithDelta(133.33, $avg, 0.01);

        // Min/Max views
        $this->assertEquals(50, $this->db->table('posts')->where('user_id', $userId)->min('views'));
        $this->assertEquals(250, $this->db->table('posts')->where('user_id', $userId)->max('views'));
    }

    public function testGroupByWithHavingAndWhere(): void
    {
        // Setup: Create users with multiple posts
        $user1 = $this->db->insert('users', ['email' => 'user1@test.com', 'name' => 'User 1']);
        $user2 = $this->db->insert('users', ['email' => 'user2@test.com', 'name' => 'User 2']);
        $user3 = $this->db->insert('users', ['email' => 'user3@test.com', 'name' => 'User 3']);

        // User 1: 3 published posts
        $this->db->insert('posts', ['user_id' => $user1, 'title' => 'P1', 'content' => 'C', 'status' => 'published']);
        $this->db->insert('posts', ['user_id' => $user1, 'title' => 'P2', 'content' => 'C', 'status' => 'published']);
        $this->db->insert('posts', ['user_id' => $user1, 'title' => 'P3', 'content' => 'C', 'status' => 'published']);

        // User 2: 1 published, 1 draft
        $this->db->insert('posts', ['user_id' => $user2, 'title' => 'P4', 'content' => 'C', 'status' => 'published']);
        $this->db->insert('posts', ['user_id' => $user2, 'title' => 'P5', 'content' => 'C', 'status' => 'draft']);

        // User 3: 2 published posts
        $this->db->insert('posts', ['user_id' => $user3, 'title' => 'P6', 'content' => 'C', 'status' => 'published']);
        $this->db->insert('posts', ['user_id' => $user3, 'title' => 'P7', 'content' => 'C', 'status' => 'published']);

        // Test: Find users with 2+ published posts using raw query to avoid SQLite type issues
        // Note: HAVING with aggregate comparison via PDO execute() has type coercion issues in SQLite
        $result = $this->db->query(
            'SELECT user_id, COUNT(*) as post_count FROM posts WHERE status = ? GROUP BY user_id HAVING COUNT(*) >= 2',
            ['published']
        )->fetchAll(\PDO::FETCH_ASSOC);

        // Should return user1 (3 posts) and user3 (2 posts), NOT user2 (1 published)
        $this->assertCount(2, $result);

        $userIds = array_column($result, 'user_id');
        $this->assertContains((string) $user1, array_map('strval', $userIds));
        $this->assertContains((string) $user3, array_map('strval', $userIds));
    }

    /**
     * Test that parameter order is correct when having() is called before where().
     * This is a regression test for a bug where parameters were bound in call order
     * instead of SQL order (WHERE comes before HAVING in SQL).
     */
    public function testHavingBeforeWhereParameterOrder(): void
    {
        // Setup
        $user1 = $this->db->insert('users', ['email' => 'order1@test.com', 'name' => 'Order User 1']);
        $user2 = $this->db->insert('users', ['email' => 'order2@test.com', 'name' => 'Order User 2']);

        $this->db->insert('posts', ['user_id' => $user1, 'title' => 'Post', 'content' => 'C', 'status' => 'published']);
        $this->db->insert('posts', ['user_id' => $user1, 'title' => 'Post', 'content' => 'C', 'status' => 'published']);
        $this->db->insert('posts', ['user_id' => $user2, 'title' => 'Post', 'content' => 'C', 'status' => 'draft']);

        // Test parameter ordering - having() called BEFORE where()
        // The QueryBuilder must build params in SQL order (WHERE first, then HAVING)
        // regardless of the order methods are called
        [$sql, $params] = $this->db->table('posts')
            ->select(['user_id', \Sodaho\PdoWrapper\Database::raw('COUNT(*) as cnt')])
            ->groupBy('user_id')
            ->having(\Sodaho\PdoWrapper\Database::raw('COUNT(*)'), '>=', 2)       // Called first, but param should be second
            ->where('status', 'published')      // Called second, but param should be first
            ->toSql();

        // Verify params are in SQL order (WHERE value first, HAVING value second)
        $this->assertSame('published', $params[0], 'First param should be WHERE value');
        $this->assertSame(2, $params[1], 'Second param should be HAVING value');

        // Verify SQL structure
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('HAVING', $sql);
        $this->assertLessThan(strpos($sql, 'HAVING'), strpos($sql, 'WHERE'), 'WHERE should come before HAVING in SQL');
    }

    // =========================================================================
    // HOOK WORKFLOW
    // =========================================================================

    public function testHookLoggingWorkflow(): void
    {
        $queries = [];
        $errors = [];

        $this->db->on('query', function (array $data) use (&$queries) {
            $queries[] = $data;
        });

        $this->db->on('error', function (array $data) use (&$errors) {
            $errors[] = $data;
        });

        // Execute some queries
        $this->db->insert('users', ['email' => 'hook@test.com', 'name' => 'Hook User']);
        $this->db->table('users')->where('email', 'hook@test.com')->first();

        // Verify hooks were called
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('INSERT', $queries[0]['sql']);
        $this->assertStringContainsString('SELECT', $queries[1]['sql']);

        // Trigger an error
        try {
            $this->db->query('SELECT * FROM nonexistent_table');
        } catch (\Throwable $e) {
            // Expected
        }

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('nonexistent_table', $errors[0]['sql']);
    }
}
