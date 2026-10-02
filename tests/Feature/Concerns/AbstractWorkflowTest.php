<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Feature\Concerns;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Exception\UniqueViolationException;
use Sodaho\PdoWrapper\Tests\Support\Fetched;
use Sodaho\PdoWrapper\Tests\Support\ReadsPdoErrorInfo;

/**
 * Abstract base class for workflow tests.
 * Runs identical tests against all database drivers.
 */
abstract class AbstractWorkflowTest extends TestCase
{
    use ReadsPdoErrorInfo;

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

    /**
     * What UniqueViolationException::$constraint names for a duplicate users.email and a duplicate
     * users.id: the index name (MySQL/MariaDB), the constraint name (PostgreSQL), null (SQLite).
     *
     * @return array{email: string|null, primary: string|null}
     */
    abstract protected function uniqueConstraintNames(): array;

    /**
     * SQLSTATE and driver code this database reports for a table that does not exist and for a
     * duplicate key.
     *
     * @return array{unknownTable: array{string, int}, duplicate: array{string, int}}
     */
    abstract protected function failureCodes(): array;

    /**
     * What sum() and avg() deliver on this database for 1 + 2 in an INT column, twice 2^53 + 1 in
     * a BIGINT column, 0.1 + 0.2 in a DECIMAL(20,4) column and 0.5 + 0.25 in a DOUBLE column.
     *
     * @return array<string, array{int|float|string, int|float|string}> [sum, avg] by column
     */
    abstract protected function deliveredAggregates(): array;

    /**
     * Whether a later assignment of an UPDATE sees the value an earlier one of the same statement
     * set (MySQL/MariaDB: SET is evaluated left to right) or the row as it was (standard SQL:
     * PostgreSQL, SQLite).
     */
    abstract protected function laterAssignmentsSeeEarlierOnes(): bool;

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
        $this->assertNotNull($user);
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
        $this->assertNotNull($post);
        $this->assertSame('draft', $post['status']);

        // 3. Publish the post
        $this->db->table('posts')
            ->where('id', $postId)
            ->update(['status' => 'published']);

        // 4. Verify published
        $post = $this->db->findOne('posts', ['id' => $postId]);
        $this->assertNotNull($post);
        $this->assertSame('published', $post['status']);

        // 5. Increment views
        $this->db->execute(
            'UPDATE posts SET views = views + 1 WHERE id = ?',
            [$postId]
        );

        $post = $this->db->findOne('posts', ['id' => $postId]);
        $this->assertNotNull($post);
        $this->assertSame(1, Fetched::int($post['views']));
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

    /**
     * An alias is quoted like every other name. The result key is the alias as written on every
     * database (PostgreSQL folds a bare alias to lower case), orderBy() and groupBy() find it
     * under that name, a table alias with an upper-case letter works, and a reserved word is a
     * valid alias.
     */
    public function testAnAliasIsTheSameNameEverywhere(): void
    {
        $ada = (int) $this->db->insert('users', ['email' => 'ada@example.com', 'name' => 'Ada']);
        $bob = (int) $this->db->insert('users', ['email' => 'bob@example.com', 'name' => 'Bob']);
        $this->db->insert('posts', ['user_id' => $ada, 'title' => 'First']);
        $this->db->insert('posts', ['user_id' => $bob, 'title' => 'Second']);
        $this->db->insert('posts', ['user_id' => $bob, 'title' => 'Third']);

        $rows = $this->db->table('users')->select(['name as UserName'])->orderBy('UserName', 'DESC')->get();
        $this->assertSame([['UserName' => 'Bob'], ['UserName' => 'Ada']], $rows);

        $rows = $this->db->table('posts')
            ->select(['user_id as AuthorId', Database::raw('COUNT(*) AS n')])
            ->groupBy('AuthorId')
            ->orderBy('AuthorId')
            ->get();
        $this->assertSame(['AuthorId', 'n'], array_keys($rows[0]));
        $this->assertSame([[$ada, 1], [$bob, 2]], array_map(static fn (array $row): array => [Fetched::int($row['AuthorId']), Fetched::int($row['n'])], $rows));

        $rows = $this->db->table('posts as P')
            ->join('users as U', 'U.id', '=', 'P.user_id')
            ->select(['P.title as order', 'U.name as Author'])
            ->where('U.name', 'Bob')
            ->orderBy('P.id')
            ->get();
        $this->assertSame([['order' => 'Second', 'Author' => 'Bob'], ['order' => 'Third', 'Author' => 'Bob']], $rows);

        $this->assertSame(2, $this->db->table('posts as P')->where('P.user_id', $bob)->count());
        $this->assertSame(2, $this->db->table('posts')->select(['user_id as AuthorId'])->groupBy('AuthorId')->count(), 'a grouped count keeps the alias');
        $this->assertSame(2, $this->db->table('posts')->select(['user_id as AuthorId'])->distinct()->count());
    }

    /**
     * A failure carries what the database said, unmangled: $sqlState as a string, $driverCode as
     * the driver's number. getCode() is 0 on every database.
     */
    public function testAFailureCarriesTheSqlStateAndTheDriversCode(): void
    {
        $expected = $this->failureCodes();

        try {
            $this->db->query('SELECT * FROM no_such_table_codes');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame($expected['unknownTable'], [$e->sqlState, $e->driverCode]);
            $this->assertSame(0, $e->getCode());
        }

        $this->db->insert('users', ['email' => 'codes@example.com', 'name' => 'First']);
        try {
            $this->db->insert('users', ['email' => 'codes@example.com', 'name' => 'Second']);
            $this->fail('Expected UniqueViolationException');
        } catch (UniqueViolationException $e) {
            $this->assertSame($expected['duplicate'], [$e->sqlState, $e->driverCode]);
            $this->assertSame(0, $e->getCode());
        }

        // a failure that never reached the database has no codes
        try {
            $this->db->table('users')->where('email', null)->get();
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame([null, null, 0], [$e->sqlState, $e->driverCode, $e->getCode()]);
        }

        // not so where the exception reports what a listener threw: the rollback went through, and
        // the listener's codes are in getPrevious()
        $thrown = null;
        $this->db->on('transaction.rollback', function () use (&$thrown): void {
            try {
                $this->db->getPdo()->query('SELECT * FROM no_such_table_codes');
            } catch (\PDOException $e) {
                $thrown = $e;
                throw $e;
            }
        });
        $this->db->beginTransaction();
        try {
            $this->db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame($thrown, $e->getPrevious());
            $this->assertSame([null, null, 0], [$e->sqlState, $e->driverCode, $e->getCode()]);
            $this->assertSame($expected['unknownTable'], [$this->errorInfoBehind($e, 0), $this->errorInfoBehind($e, 1)]);
        }
    }

    /**
     * An exception that reports a listener's failure carries no SQLSTATE and no driver code,
     * whatever the listener ran into: the statement ran, the transaction was committed or rolled
     * back, the BEGIN went through. A retry that looks at $sqlState must not take a listener's
     * deadlock for the failure of the operation.
     */
    public function testAnExceptionAboutAListenersFailureCarriesNoCodes(): void
    {
        $deadlock = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
        $deadlock->errorInfo = ['40001', 1213, 'Deadlock found'];
        $listener = static function () use ($deadlock): void {
            throw $deadlock;
        };
        $caught = [];

        $db = $this->createDatabase();
        $db->on('query', $listener);
        try {
            $db->query('SELECT 1');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Query hook failed', $e->getMessage());
            $caught['query'] = $e;
        }

        $db = $this->createDatabase();
        $db->on('transaction.begin', $listener);
        try {
            $db->beginTransaction();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to begin transaction', $e->getMessage());
            $caught['transaction.begin'] = $e;
        }

        $db = $this->createDatabase();
        $db->on('transaction.commit', $listener);
        try {
            $db->transaction(static fn (): null => null);
            $this->fail('Expected CommitHookException');
        } catch (CommitHookException $e) {
            $caught['transaction.commit'] = $e;
        }

        $db = $this->createDatabase();
        $db->on('transaction.end', static function () use ($deadlock): void {
            throw new QueryException(message: 'Query failed', previous: $deadlock); // as a failed statement of the listener arrives
        });
        $db->beginTransaction();
        try {
            $db->rollback();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Transaction rolled back, but a transaction.end listener failed', $e->getMessage());
            $this->assertSame('40001', $e->getPrevious() instanceof QueryException ? $e->getPrevious()->sqlState : null, 'the listener\'s codes are one step away');
            $caught['transaction.end'] = $e;
        }

        $this->assertSame(['query', 'transaction.begin', 'transaction.commit', 'transaction.end'], array_keys($caught));
        foreach ($caught as $event => $e) {
            $this->assertSame([null, null], [$e->sqlState, $e->driverCode], $event);
        }
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
        $this->assertNotNull($user);
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

    /**
     * insert() returns the generated ID as an integer on every database, through the driver and
     * through the query builder.
     */
    public function testInsertReturnsTheIdAsAnIntegerOnEveryDatabase(): void
    {
        $first = $this->db->insert('users', ['email' => 'id-1@example.com', 'name' => 'One']);
        $second = $this->db->table('users')->insert(['email' => 'id-2@example.com', 'name' => 'Two']);

        $this->assertSame([1, 2], [$first, $second]);
    }

    /**
     * sum() and avg() hand on what the database computed, in the type its driver delivers - not
     * a float that has lost what the database had exactly: a BIGINT sum above 2^53 on every
     * database, a DECIMAL sum where the database has decimals.
     */
    public function testSumAndAvgArriveAsTheDatabaseDeliversThem(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS agg_types');
        $this->db->execute('CREATE TABLE agg_types (id INT PRIMARY KEY, small INT, big BIGINT, price DECIMAL(20,4), ratio DOUBLE PRECISION)');

        try {
            $this->assertNull($this->db->table('agg_types')->sum('small'), 'no rows: SQL NULL');
            $this->assertNull($this->db->table('agg_types')->avg('small'), 'no rows: SQL NULL');

            // 9007199254740993 is 2^53 + 1: no float holds it, nor twice it
            $this->db->insert('agg_types', ['id' => 1, 'small' => 1, 'big' => 9007199254740993, 'price' => '0.1000', 'ratio' => 0.5]);
            $this->db->insert('agg_types', ['id' => 2, 'small' => 2, 'big' => 9007199254740993, 'price' => '0.2000', 'ratio' => 0.25]);

            $delivered = [];
            foreach (['small', 'big', 'price', 'ratio'] as $column) {
                $delivered[$column] = [
                    $this->db->table('agg_types')->sum($column),
                    $this->db->table('agg_types')->avg($column),
                ];
            }

            $this->assertSame($this->deliveredAggregates(), $delivered);
            $this->assertSame('18014398509481986', (string) $delivered['big'][0], 'the exact sum on every database');
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS agg_types');
        }
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

    /**
     * insert() reads the new id before the 'query' hook runs: a listener that inserts on the
     * same connection (an audit row, here a second user) must not replace the id it returns.
     */
    public function testInsertReturnsItsOwnIdWhenAQueryListenerInserts(): void
    {
        $listenerId = null;
        $this->db->on('query', function (array $data) use (&$listenerId): void {
            if ($listenerId === null && str_contains($data['sql'], 'INSERT')) {
                $listenerId = 0; // set before the nested inserts fire this hook again
                // a plain statement first: the outer insert's id must not be read a second time after it
                $this->db->execute('INSERT INTO users (email, name) VALUES (?, ?)', ['audit2@test.com', 'Audit 2']);
                $listenerId = $this->db->insert('users', ['email' => 'audit@test.com', 'name' => 'Audit']);
            }
        });

        $id = $this->db->insert('users', ['email' => 'own@test.com', 'name' => 'Own']);

        $own = $this->db->findOne('users', ['email' => 'own@test.com']);
        $audit = $this->db->findOne('users', ['email' => 'audit@test.com']);
        $this->assertNotNull($own);
        $this->assertNotNull($audit);
        $this->assertEquals($own['id'], $id, 'insert() returns the id of its own row');
        $this->assertEquals($audit['id'], $listenerId);
        $this->assertNotEquals($id, $listenerId);
        $this->assertSame(3, $this->db->table('users')->count());
    }

    /**
     * groupBy() takes Database::raw() for an expression, alone or next to column names; count()
     * then counts the groups of that expression.
     */
    public function testGroupByARawExpression(): void
    {
        foreach ([['Anna', 'anna@a.test'], ['ANNA', 'anna@b.test'], ['Bert', 'bert@a.test']] as [$name, $email]) {
            $this->db->insert('users', ['name' => $name, 'email' => $email]);
        }
        $byName = $this->db->table('users')
            ->select([Database::raw('LOWER(name) AS lower_name'), Database::raw('COUNT(*) AS total')])
            ->groupBy(Database::raw('LOWER(name)'));

        [$sql] = $byName->toSql();
        $this->assertStringEndsWith(' GROUP BY LOWER(name)', $sql);
        $rows = (clone $byName)->orderBy('lower_name')->get();
        $this->assertSame(['anna', 'bert'], array_column($rows, 'lower_name'));
        $this->assertEquals([2, 1], array_column($rows, 'total'));
        $this->assertSame(2, $byName->count());

        $mixed = $this->db->table('users')
            ->select([Database::raw('LOWER(name) AS lower_name'), 'email'])
            ->groupBy([Database::raw('LOWER(name)'), 'email']);
        $this->assertSame(3, $mixed->count());
        $this->assertCount(3, $mixed->get());
    }

    /**
     * exists() with distinct() and groupBy(), and whereBetween()/whereNotBetween() with a raw
     * bound, executed on the real engine (the SQL shape differs per dialect).
     */
    public function testExistsWithDistinctAndGroupByAndBetweenWithARawBound(): void
    {
        foreach ([['a@test.com', 'Anna', 'admin'], ['b@test.com', 'Bert', 'user'], ['c@test.com', 'Cleo', 'user']] as [$email, $name, $role]) {
            $this->db->insert('users', ['email' => $email, 'name' => $name, 'role' => $role]);
        }
        $ids = array_column($this->db->table('users')->orderBy('id')->get(), 'id');

        $this->assertTrue($this->db->table('users')->select('role')->distinct()->groupBy('role')->exists());
        $this->assertTrue($this->db->table('users')->select('role')->distinct()->groupBy('role')->having(Database::raw('COUNT(*)'), '>', 1)->exists());
        $this->assertFalse($this->db->table('users')->select('role')->distinct()->groupBy('role')->having(Database::raw('COUNT(*)'), '>', 2)->exists());
        $this->assertFalse($this->db->table('users')->where('role', 'guest')->select('role')->distinct()->groupBy('role')->exists());

        $between = $this->db->table('users')->whereBetween('id', [Database::raw((string) Fetched::int($ids[0]) . ' + 1'), $ids[2]])->orderBy('id')->get();
        $this->assertSame(['Bert', 'Cleo'], array_column($between, 'name'));
        $notBetween = $this->db->table('users')->whereNotBetween('id', [$ids[1], Database::raw((string) Fetched::int($ids[2]) . ' + 0')])->get();
        $this->assertSame(['Anna'], array_column($notBetween, 'name'));
    }

    /**
     * LIKE in a join condition takes its pattern from a column; the bound escape character makes
     * an escaped pattern mean the same on every database, in every kind of select.
     */
    public function testLikeInAJoinConditionUsesTheBoundEscapeCharacter(): void
    {
        $this->assertJoinLikeMatchesTheEscapedPatternOnly();
    }

    protected function assertJoinLikeMatchesTheEscapedPatternOnly(): void
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

    /**
     * where() with IS / IS NOT compares null-safely on every database, also with a null value.
     */
    public function testWhereIsWithAValueThatMayBeNull(): void
    {
        $this->db->insert('users', ['email' => 'a@test.com', 'name' => 'Anna', 'role' => 'admin']);
        $this->db->insert('users', ['email' => 'b@test.com', 'name' => 'Bert', 'role' => 'user']);
        $this->db->update('users', ['role' => null], ['email' => 'b@test.com']);

        $names = fn (string $operator, ?string $role): array => array_column(
            $this->db->table('users')->where('role', $operator, $role)->orderBy('id')->get(),
            'name'
        );

        $this->assertSame(['Bert'], $names('IS', null));
        $this->assertSame(['Anna'], $names('IS NOT', null));
        $this->assertSame(['Anna'], $names('IS', 'admin'));
        $this->assertSame(['Bert'], $names('IS NOT', 'admin'), 'null-safe: the row without a role is "not admin"');
        $this->assertSame(1, $this->db->table('users')->where('role', 'IS', null)->update(['role' => 'guest']));
    }

    /**
     * A duplicate key fails with UniqueViolationException - a QueryException, so existing catch
     * blocks keep working - and names the violated key where the database does. Other constraint
     * failures stay plain QueryExceptions.
     */
    public function testADuplicateKeyIsAUniqueViolation(): void
    {
        $id = $this->db->insert('users', ['email' => 'taken@test.com', 'name' => 'First']);
        $expected = $this->uniqueConstraintNames();

        try {
            $this->db->insert('users', ['email' => 'taken@test.com', 'name' => 'Second']);
            $this->fail('Expected UniqueViolationException');
        } catch (UniqueViolationException $e) {
            $this->assertInstanceOf(QueryException::class, $e);
            $this->assertSame('Query failed', $e->getMessage());
            $this->assertSame($expected['email'], $e->constraint);
            $this->assertStringContainsString('INSERT INTO', (string) $e->getDebugMessage());
            $this->assertNotNull($e->getPrevious());
        }

        // the duplicate value comes from outside and may look like the end of the message
        $tricky = "x' for key 'evil";
        $this->db->insert('users', ['email' => $tricky, 'name' => 'Tricky']);
        try {
            $this->db->insert('users', ['email' => $tricky, 'name' => 'Tricky again']);
            $this->fail('Expected UniqueViolationException');
        } catch (UniqueViolationException $e) {
            $this->assertSame($expected['email'], $e->constraint);
        }

        try {
            $this->db->insert('users', ['id' => $id, 'email' => 'other@test.com', 'name' => 'Same id']);
            $this->fail('Expected UniqueViolationException for the primary key');
        } catch (UniqueViolationException $e) {
            $this->assertSame($expected['primary'], $e->constraint);
        }

        try {
            $this->db->table('users')->where('email', $tricky)->update(['email' => 'taken@test.com']);
            $this->fail('Expected UniqueViolationException for an update');
        } catch (UniqueViolationException $e) {
            $this->assertSame($expected['email'], $e->constraint);
        }

        try {
            $this->db->execute('INSERT INTO users (email, name) VALUES (?, NULL)', ['null-name@test.com']);
            $this->fail('Expected QueryException: name is NOT NULL');
        } catch (QueryException $e) {
            $this->assertNotInstanceOf(UniqueViolationException::class, $e, 'a NOT NULL violation is not a duplicate');
        }
    }

    /**
     * Database::raw() with bindings as a value: bound where the expression stands. The SET list is
     * written in the order of the array - which decides the result on MySQL/MariaDB, where a later
     * assignment sees what an earlier one set.
     */
    public function testARawValueWithBindingsAndTheOrderOfTheSetList(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS set_order');
        $this->db->execute('CREATE TABLE set_order (id INT PRIMARY KEY, attempts INT NOT NULL, pause_s INT NOT NULL, note VARCHAR(20) NOT NULL)');
        $seen = [];
        $this->db->on('query', static function (array $data) use (&$seen): void {
            $seen[] = $data['params'];
        });

        try {
            $this->db->insert('set_order', ['id' => 1, 'attempts' => 1, 'pause_s' => 0, 'note' => 'new']);
            $this->db->insert('set_order', ['id' => 2, 'attempts' => 1, 'pause_s' => 0, 'note' => 'new']);
            $seen = [];

            // attempts first: on MySQL/MariaDB the pause is computed from the raised counter
            $affected = $this->db->table('set_order')->where('id', 1)->whereIn('note', ['new', 'old'])->update([
                'attempts' => Database::raw('attempts + ?', [1]),
                'note' => 'first',
                'pause_s' => Database::raw('? * attempts', [10]),
            ]);
            $this->assertSame(1, $affected);
            $this->assertSame([[1, 'first', 10, 1, 'new', 'old']], $seen, 'SET values in array order, raw bindings in place, then WHERE');
            $row = $this->db->findOne('set_order', ['id' => 1]);
            $this->assertSame(2, Fetched::int($row['attempts'] ?? 0));
            $this->assertSame($this->laterAssignmentsSeeEarlierOnes() ? 20 : 10, Fetched::int($row['pause_s'] ?? 0));

            // the other way round the pause is computed first, from the old counter, on every database
            $this->db->update('set_order', [
                'pause_s' => Database::raw('? * attempts', [10]),
                'attempts' => Database::raw('attempts + ?', [1]),
            ], ['id' => 2, 'note' => Database::raw('LOWER(?)', ['NEW'])]);
            $row = $this->db->findOne('set_order', ['id' => 2]);
            $this->assertSame(2, Fetched::int($row['attempts'] ?? 0));
            $this->assertSame(10, Fetched::int($row['pause_s'] ?? 0));
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS set_order');
        }
    }

    /**
     * insertIgnore(): 1 when the row went in, 0 when a unique key or the primary key collided -
     * without an exception and without touching the existing row. Everything else still throws.
     */
    public function testInsertIgnoreSkipsADuplicateAndNothingElse(): void
    {
        $this->assertSame(1, $this->db->insertIgnore('users', ['email' => 'once@test.com', 'name' => 'First']));
        $this->assertSame(0, $this->db->insertIgnore('users', ['email' => 'once@test.com', 'name' => 'Second']));
        $this->assertSame(0, $this->db->table('users')->insertIgnore(['name' => 'Third', 'email' => 'once@test.com']), 'whichever column comes first');
        $this->assertSame(1, $this->db->table('users')->insertIgnore(['email' => 'twice@test.com', 'name' => Database::raw("'raw name'")]));

        $rows = $this->db->table('users')->orderBy('id')->get();
        $this->assertSame(['First', 'raw name'], array_column($rows, 'name'), 'the existing row is untouched');

        $id = $rows[0]['id'];
        $this->assertSame(0, $this->db->insertIgnore('users', ['id' => $id, 'email' => 'third@test.com', 'name' => 'Same id']), 'the primary key counts too');

        try {
            $this->db->insertIgnore('posts', ['user_id' => 999999, 'title' => 'orphan']);
            $this->fail('Expected QueryException: a foreign key violation is not a duplicate');
        } catch (QueryException $e) {
            $this->assertNotInstanceOf(UniqueViolationException::class, $e);
        }
        try {
            $this->db->insertIgnore('users', []);
            $this->fail('Expected QueryException for empty data');
        } catch (QueryException $e) {
            $this->assertSame('Cannot insert empty data', $e->getDebugMessage());
        }
        try {
            $this->db->table('users')->where('id', 1)->insertIgnore(['email' => 'x@test.com', 'name' => 'x']);
            $this->fail('Expected QueryException: builder clauses are not part of the statement');
        } catch (QueryException $e) {
            $this->assertStringContainsString('insertIgnore() inserts one row', (string) $e->getDebugMessage());
        }
        $this->assertSame(2, $this->db->table('users')->count());
    }
}
