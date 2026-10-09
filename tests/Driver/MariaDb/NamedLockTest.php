<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Closure;
use ErrorException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\NamedLockReentryException;
use Sodaho\PdoWrapper\Exception\NamedLocksHeldException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;
use Throwable;

/**
 * Named locks (GET_LOCK()): held by a connection, not by a transaction; prefixed with the
 * configured database; a second hold refused; a release that did not release reported as false.
 */
class NamedLockTest extends ContractTestCase
{
    private MariaDbDriver $first;

    private MariaDbDriver $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->first = $this->mariaDb();
        $this->second = $this->mariaDb();
    }

    protected function closeConnections(): void
    {
        unset($this->first, $this->second);
    }

    protected function tearDown(): void
    {
        ReportedVersionPdo::reset();
        NullColumnStatement::$null = true;
        UnreadableColumnStatement::$mode = 'pdo';
        parent::tearDown();
    }

    /**
     * @param array{options?: array<int, mixed>} $extra
     */
    private function mariaDb(array $extra = []): MariaDbDriver
    {
        $db = $this->connect($extra);
        $this->assertInstanceOf(MariaDbDriver::class, $db);

        return $db;
    }

    public function testOneConnectionAtATime(): void
    {
        $this->assertTrue($this->first->namedLock('login:7'));
        $this->assertFalse($this->second->namedLock('login:7'), 'held by the first connection');
        $this->assertTrue($this->second->namedLock('login:8'), 'another name');
        $this->assertTrue($this->first->isNamedLockHeld('login:7'));
        $this->assertFalse($this->second->isNamedLockHeld('login:7'));

        $this->assertTrue($this->first->releaseNamedLock('login:7'));
        $this->assertFalse($this->first->isNamedLockHeld('login:7'));
        $this->assertTrue($this->second->namedLock('login:7'), 'free after the release');
    }

    /**
     * On the server the name carries the configured database: one namespace for every database
     * of the server.
     */
    public function testTheNameIsPrefixedWithTheDatabase(): void
    {
        $this->first->namedLock('job');
        $database = TestEnvironment::mariadb()['database'];

        $this->assertSame(1, $this->db->query('SELECT IS_USED_LOCK(?) IS NOT NULL', [$database . ':job'])->fetchColumn());
        $this->assertSame(0, $this->db->query('SELECT IS_USED_LOCK(?) IS NOT NULL', ['job'])->fetchColumn());
        $this->assertTrue($this->second->namedLock('JOB'), 'compared as written');
    }

    /**
     * MariaDB would count a second hold, and one release would not free it: refused.
     */
    public function testASecondHoldIsRefused(): void
    {
        $this->first->namedLock('job');

        try {
            $this->first->namedLock('job');
            $this->fail('Expected NamedLockReentryException');
        } catch (NamedLockReentryException $e) {
            $this->assertInstanceOf(QueryException::class, $e, 'existing catch blocks keep working');
            $this->assertSame('Query failed', $e->getMessage());
            $this->assertSame('job', $e->lockName);
            $this->assertSame('namedLock(): this connection holds "job" already; MariaDB would count a second hold, and one release would not free it. Release it first, or ask isNamedLockHeld().', $e->getDebugMessage());
        }
        $this->assertTrue($this->first->releaseNamedLock('job'));
        $this->assertTrue($this->second->namedLock('job'), 'one release freed it');
    }

    /**
     * A lock this connection took in raw SQL is not counted - until namedLock() learns that the
     * connection holds it: then it is.
     */
    public function testALockTakenInRawSqlIsCountedOnceNamedLockSeesIt(): void
    {
        $database = TestEnvironment::mariadb()['database'];
        $this->first->query('SELECT GET_LOCK(?, 0)', [$database . ':job']);
        $this->assertSame([], $this->first->heldNamedLocks(), 'not seen');

        try {
            $this->first->namedLock('job');
            $this->fail('Expected NamedLockReentryException');
        } catch (NamedLockReentryException) {
        }
        $this->assertSame(['job'], $this->first->heldNamedLocks());
    }

    public function testAReleaseThatReleasesNothingIsFalse(): void
    {
        $this->assertFalse($this->first->releaseNamedLock('job'), 'nobody holds it');
        $this->second->namedLock('job');
        $this->assertFalse($this->first->releaseNamedLock('job'), 'another connection holds it');
        $this->assertTrue($this->second->isNamedLockHeld('job'), 'and still does');
    }

    public function testTheTimeoutWaits(): void
    {
        $this->first->namedLock('job');

        $started = microtime(true);
        $this->assertFalse($this->second->namedLock('job', 1));
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started);
    }

    /**
     * Not bound to a transaction: a rollback keeps it. reconnect() refuses while it is held and
     * nothing changes; given up knowingly, it goes with the old connection.
     */
    public function testHeldByTheConnectionNotByATransaction(): void
    {
        $this->first->beginTransaction();
        $this->first->namedLock('job');
        $this->first->rollback();
        $this->assertTrue($this->first->isNamedLockHeld('job'), 'the rollback kept it');

        $pdo = $this->first->getPdo();
        try {
            $this->first->reconnect();
            $this->fail('Expected NamedLocksHeldException');
        } catch (NamedLocksHeldException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertSame(['job'], $e->lockNames);
            $this->assertNull($e->refusal);
            $this->assertSame('reconnect() would give up the named locks this driver holds ("job") with the old session. Release them first, or call reconnect(dropNamedLocks: true) to give them up knowingly.', $e->getDebugMessage());
        }
        $this->assertSame($pdo, $this->first->getPdo(), 'nothing changed');
        $this->assertTrue($this->first->isNamedLockHeld('job'), 'still held');
        $this->assertSame(['job'], $this->first->heldNamedLocks());

        $this->first->reconnect(dropNamedLocks: true);
        $this->assertNotSame($pdo, $this->first->getPdo());
        unset($pdo); // the old connection closes with its last holder
        $this->assertSame([], $this->first->heldNamedLocks());
        $this->assertFalse($this->first->isNamedLockHeld('job'), 'given up with the old connection');
        $this->assertTrue($this->second->namedLock('job', 2));
    }

    /**
     * A 'query' listener's reconnect() - after the statement ran, before its method returns - is
     * refused, $dropNamedLocks or not: the answer and what was recorded belong to the session the
     * statement ran on. Nothing changes; what the statement did is recorded.
     */
    public function testAReconnectAfterANamedLockStatementRanIsRefused(): void
    {
        $pdo = $this->first->getPdo();
        $refused = function (Closure $call, bool $drop = true): ConnectionException {
            $listener = function () use (&$listener, $drop): void {
                $this->first->off('query', $listener);
                $this->first->reconnect(dropNamedLocks: $drop);
            };
            $this->first->on('query', $listener);
            try {
                $call();
                $this->fail('Expected ConnectionException');
            } catch (ConnectionException $e) {
                $this->assertSame('reconnect() after a named-lock statement ran, before its method returned (from a listener): the answer and what was recorded belong to the session it ran on. Reconnect after the call.', $e->getDebugMessage());

                return $e;
            }
        };

        $refused(fn (): bool => $this->first->namedLock('job'));
        $this->assertSame(['job'], $this->first->heldNamedLocks(), 'taken where the statement ran');
        $this->assertTrue($this->first->isNamedLockHeld('job'));
        $this->assertNotInstanceOf(NamedLocksHeldException::class, $refused(fn (): bool => $this->first->isNamedLockHeld('job'), false), 'the running statement first, then the held locks');
        $refused(fn (): ?int => $this->first->namedLockHolder('job'));
        $refused(fn (): bool => $this->first->releaseNamedLock('job'));
        $this->assertSame([], $this->first->heldNamedLocks(), 'released where the statement ran');

        $this->assertSame($pdo, $this->first->getPdo(), 'nothing was reconnected');
        $this->assertTrue($this->second->namedLock('job'), 'released on the server');
    }

    /**
     * A 'query.before' listener's reconnect() comes before the statement: it simply runs on the new
     * session, and what it answers is recorded there.
     */
    public function testAReconnectBeforeANamedLockStatementIsItsNewSession(): void
    {
        $pdo = $this->first->getPdo();
        $listener = function () use (&$listener): void {
            $this->first->off('query.before', $listener);
            $this->first->reconnect();
        };
        $this->first->on('query.before', $listener);

        $this->assertTrue($this->first->namedLock('job'));
        $this->assertNotSame($pdo, $this->first->getPdo());
        $this->assertSame(['job'], $this->first->heldNamedLocks());
        $this->assertTrue($this->first->isNamedLockHeld('job'), 'held by the new session');
        $this->assertFalse($this->second->namedLock('job'));
    }

    /**
     * An 'error' listener that reconnects after a lost connection - the statement failed, nothing
     * was recorded: the reconnect goes through, the caller gets the statement's failure.
     */
    public function testAnErrorListenerMayReconnectAfterAFailedLockStatement(): void
    {
        $id = $this->first->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->second->execute('KILL ' . $id);
        $gone = false;
        for ($i = 0; $i < 100 && !$gone; $i++) {
            $gone = $this->second->query('SELECT COUNT(*) FROM information_schema.processlist WHERE id = ?', [$id])->fetchColumn() === 0;
            if (!$gone) {
                usleep(50_000);
            }
        }
        $this->assertTrue($gone, 'the killed connection did not disappear within 5 s');
        $pdo = $this->first->getPdo();
        $listener = function () use (&$listener): void {
            $this->first->off('error', $listener);
            $this->first->reconnect();
        };
        $this->first->on('error', $listener);

        try {
            $this->first->namedLock('job');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertNotNull($e->driverCode, "the statement's failure");
        }
        $this->assertNotSame($pdo, $this->first->getPdo(), 'reconnected');
        $this->assertSame([], $this->first->heldNamedLocks());
        $this->assertTrue($this->first->namedLock('job'), 'on the new connection');
    }

    /**
     * What a 'query' listener of releaseNamedLock() does with the same name comes after the
     * release: taken again, it counts.
     */
    public function testAListenerOfTheReleaseTakesTheNameAgain(): void
    {
        $this->first->namedLock('job');
        $listener = function () use (&$listener): void {
            $this->first->off('query', $listener);
            $this->assertTrue($this->first->namedLock('job'));
        };
        $this->first->on('query', $listener);

        $this->assertTrue($this->first->releaseNamedLock('job'));
        $this->assertSame(['job'], $this->first->heldNamedLocks());
        $this->assertTrue($this->first->isNamedLockHeld('job'));
    }

    /**
     * A driver that puts another PDO object in place while a named-lock statement runs (from a
     * listener): the answer may speak of another session - the method throws instead.
     */
    public function testAConnectionReplacedWhileTheStatementRunsThrows(): void
    {
        $db = new class (TestEnvironment::mariadb()) extends MariaDbDriver {
            public function replaceConnection(PDO $pdo): void
            {
                $this->pdo = $pdo;
            }
        };
        $other = self::binding()->pdo();
        $listener = function () use ($db, $other, &$listener): void {
            $db->off('query', $listener);
            $db->replaceConnection($other);
        };
        $db->on('query', $listener);

        try {
            $db->isNamedLockHeld('job');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('isNamedLockHeld(): the connection was replaced while the statement ran; a named lock belongs to its session', $e->getDebugMessage());
        }
    }

    /**
     * A statement that did not run - a 'query.before' listener stopped it - changes nothing: a name
     * not taken is not counted, a name whose release was not sent still is.
     */
    public function testAStatementThatDidNotRunChangesNothing(): void
    {
        $stopNext = function (): void {
            $stop = function () use (&$stop): void {
                $this->first->off('query.before', $stop);

                throw new RuntimeException('stopped');
            };
            $this->first->on('query.before', $stop);
        };

        $stopNext();
        try {
            $this->first->namedLock('job');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
        }
        $this->assertSame([], $this->first->heldNamedLocks());
        $this->assertFalse($this->first->isNamedLockHeld('job'));

        $this->first->namedLock('job');
        $stopNext();
        try {
            $this->first->releaseNamedLock('job');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
        }
        $this->assertSame(['job'], $this->first->heldNamedLocks());
        $this->assertTrue($this->first->isNamedLockHeld('job'), 'still held');

        // statements that did not run leave nothing behind: the next one that runs is guarded as ever
        $listener = function () use (&$listener): void {
            $this->first->off('query', $listener);
            $this->first->reconnect(dropNamedLocks: true);
        };
        $this->first->on('query', $listener);
        try {
            $this->first->isNamedLockHeld('job');
            $this->fail('Expected ConnectionException');
        } catch (Throwable $e) { // the listener's exception, passed on unchanged
            $this->assertInstanceOf(ConnectionException::class, $e);
            $this->assertStringStartsWith('reconnect() after a named-lock statement ran', (string) $e->getDebugMessage());
        }
    }

    /**
     * Answers about a name that counts already: an error (NULL) leaves it counted; an answer that
     * another connection holds it - the lock was given up in raw SQL - clears it.
     */
    public function testAnAnswerAboutACountedName(): void
    {
        NullColumnStatement::$null = false;
        $db = $this->mariaDb(['options' => [PDO::ATTR_STATEMENT_CLASS => [NullColumnStatement::class]]]);
        $this->assertTrue($db->namedLock('job'));
        NullColumnStatement::$null = true;
        try {
            $db->namedLock('job');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('answered NULL', (string) $e->getDebugMessage());
        }
        $this->assertSame(['job'], $db->heldNamedLocks(), 'NULL says nothing about it');

        $database = TestEnvironment::mariadb()['database'];
        $this->first->namedLock('busy');
        $this->first->query('SELECT RELEASE_LOCK(?)', [$database . ':busy']);
        $this->second->namedLock('busy');
        $this->assertFalse($this->first->namedLock('busy'));
        $this->assertSame([], $this->first->heldNamedLocks(), 'another connection holds it');
    }

    /**
     * An answer that cannot be read after the statement ran - fetchColumn() throws, or returns
     * false: the lock may have been taken, so the name counts, and the method throws; a release
     * that ran clears the name all the same.
     */
    public function testAnAnswerThatCannotBeRead(): void
    {
        foreach (['throws' => 'pdo', 'returns false' => 'false'] as $case => $mode) {
            UnreadableColumnStatement::$mode = $mode;
            $db = $this->mariaDb(['options' => [PDO::ATTR_STATEMENT_CLASS => [UnreadableColumnStatement::class]]]);
            foreach (['namedLock' => fn (): mixed => $db->namedLock('job'), 'isNamedLockHeld' => fn (): mixed => $db->isNamedLockHeld('job'), 'namedLockHolder' => fn (): mixed => $db->namedLockHolder('job'), 'releaseNamedLock' => fn (): mixed => $db->releaseNamedLock('job')] as $method => $call) {
                try {
                    $call();
                    $this->fail("Expected QueryException: {$method}, {$case}");
                } catch (QueryException $e) {
                    $this->assertSame(sprintf('%s(): the statement ran, but its answer could not be read', $method), $e->getDebugMessage(), $case);
                    $this->assertInstanceOf(PDOException::class, $e->getPrevious());
                    $this->assertStringStartsWith($mode === 'pdo' ? 'the answer could not be read (scenario)' : 'PDOStatement::fetchColumn() returned false', $e->getPrevious()->getMessage(), $case);
                }
                if ($method === 'namedLock') {
                    $this->assertSame(['job'], $db->heldNamedLocks(), "{$case}: it may have been taken - and it was");
                    $this->assertFalse($this->second->namedLock('job'), $case);
                }
            }
            $this->assertSame([], $db->heldNamedLocks(), "{$case}: the release ran");
            $this->assertTrue($this->second->namedLock('job', 2), "{$case}: released on the server");
            $this->assertTrue($this->second->releaseNamedLock('job'));
            unset($db);
        }
    }

    /**
     * An error handler's own exception out of the read - not about a failure PDO recorded: passed
     * on unchanged, and the lock that may have been taken counts all the same.
     */
    public function testAnErrorHandlersExceptionOutOfTheReadPassesUnchanged(): void
    {
        UnreadableColumnStatement::$mode = 'other';
        $db = $this->mariaDb(['options' => [PDO::ATTR_STATEMENT_CLASS => [UnreadableColumnStatement::class]]]);

        try {
            $db->namedLock('job');
            $this->fail('Expected ErrorException');
        } catch (Throwable $e) {
            $this->assertSame(ErrorException::class, $e::class);
            $this->assertSame('an error handler of the application (scenario)', $e->getMessage());
        }
        $this->assertSame(['job'], $db->heldNamedLocks());
        $this->assertFalse($this->second->namedLock('job'), 'taken');
    }

    /**
     * A driver's own query() and queryThen() do not stand between a named-lock statement and its
     * step - not one that changes the SQL, sends a statement ahead, or calls the step twice: the
     * answers are recorded where the statements ran, and the refusal holds. The hooks see the
     * statements; insert() still goes through the driver's own query().
     */
    public function testADriversOwnQueryDoesNotStandBetweenALockStatementAndItsStep(): void
    {
        $db = new class (TestEnvironment::mariadb()) extends MariaDbDriver {
            /** @var list<string> */
            public array $seen = [];

            public function query(string $sql, array $params = []): PDOStatement
            {
                $this->seen[] = $sql;
                $database = TestEnvironment::mariadb()['database'];
                if (str_contains($sql, 'GET_LOCK')) {
                    parent::query($sql, [$database . ':other', $database . ':other', 0]); // sent ahead
                }

                return parent::query('/* app */ ' . $sql, $params);
            }

            protected function queryThen(string $sql, array $params, Closure $afterExecute): PDOStatement
            {
                return parent::queryThen($sql, $params, static function (?PDOStatement $stmt = null) use ($afterExecute): void {
                    if ($stmt !== null) {
                        $afterExecute($stmt);
                        $afterExecute($stmt);
                    }
                });
            }
        };
        $told = [];
        $db->on('query', static function (array $data) use (&$told): void {
            $told[] = (string) $data['sql'];
        });

        $this->assertTrue($db->namedLock('job'));
        $this->assertSame(['job'], $db->heldNamedLocks());
        $this->assertTrue($db->isNamedLockHeld('job'));
        $this->assertIsInt($db->namedLockHolder('job'));
        $this->assertTrue($this->second->namedLock('other'), 'nothing was sent ahead: the driver holds no other lock');
        $this->assertTrue($this->second->releaseNamedLock('other'));
        $this->assertSame([], $db->seen, "the driver's own query() saw no lock statement");
        $this->assertCount(3, $told, 'the hooks did');

        $listener = function () use ($db, &$listener): void {
            $db->off('query', $listener);
            $db->reconnect(dropNamedLocks: true);
        };
        $db->on('query', $listener);
        try {
            $db->releaseNamedLock('job');
            $this->fail('Expected ConnectionException');
        } catch (Throwable $e) { // the listener's exception, passed on unchanged
            $this->assertInstanceOf(ConnectionException::class, $e);
            $this->assertStringStartsWith('reconnect() after a named-lock statement ran', (string) $e->getDebugMessage());
        }
        $this->assertSame([], $db->heldNamedLocks(), 'released where the statement ran');

        $this->create('lock_ids', ['id' => 'id', 'n' => 'int']);
        $this->assertSame(1, $db->insert('lock_ids', ['n' => 1]));
        $this->assertCount(1, $db->seen, 'insert() goes through it');
    }

    /**
     * Foreign code inside query() that sends the very same lock statement at the same depth - here
     * a bindAndExecute() of the driver's own, sending it for another name first: the step is the
     * method's statement's alone, taken before any foreign code ran - the answer recorded is its
     * own, and the guard is counted once.
     */
    public function testTheSameLockStatementSentFromInsideQueryIsCountedOnce(): void
    {
        $db = new class (TestEnvironment::mariadb()) extends MariaDbDriver {
            private bool $sent = false;

            protected function bindAndExecute(PDOStatement $stmt, array $params): bool
            {
                if (!$this->sent && str_contains($stmt->queryString, 'GET_LOCK')) {
                    $this->sent = true;
                    $database = TestEnvironment::mariadb()['database'];
                    $this->query($stmt->queryString, [$database . ':busy', $database . ':busy', 0]); // answered 0: another connection holds it
                }

                return parent::bindAndExecute($stmt, $params);
            }
        };
        $this->second->namedLock('busy');

        $this->assertTrue($db->namedLock('job'));
        $this->assertSame(['job'], $db->heldNamedLocks(), 'the answer of the statement itself');
        $db->reconnect(dropNamedLocks: true);
        $this->assertSame([], $db->heldNamedLocks(), 'nothing held reconnect() back but the counted name');
    }

    /**
     * The same, and the method's own statement then fails: the answer of the foreign statement
     * (another lock, busy) is not recorded for the name, which stays counted as it was.
     */
    public function testAForeignStatementIsNotRecordedWhenTheOwnOneFails(): void
    {
        $db = new class (TestEnvironment::mariadb()) extends MariaDbDriver {
            public bool $failNext = false;

            protected function bindAndExecute(PDOStatement $stmt, array $params): bool
            {
                if ($this->failNext && str_contains($stmt->queryString, 'GET_LOCK')) {
                    $this->failNext = false;
                    $database = TestEnvironment::mariadb()['database'];
                    $this->query($stmt->queryString, [$database . ':busy', $database . ':busy', 0]); // answered 0: another connection holds it

                    throw new PDOException('the own statement failed (scenario)');
                }

                return parent::bindAndExecute($stmt, $params);
            }
        };
        $this->second->namedLock('busy');
        $this->assertTrue($db->namedLock('job'));
        $db->failNext = true;

        try {
            $db->namedLock('job');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('the own statement failed (scenario)', $e->getPrevious()?->getMessage());
        }
        $this->assertSame(['job'], $db->heldNamedLocks(), 'still counted: the foreign answer (0) was not taken for it');
        $this->assertTrue($db->isNamedLockHeld('job'));
    }

    /**
     * Foreign code inside query() swaps the connection after the method's statement was prepared
     * (a bindAndExecute() of the driver's own sends a statement whose 'query' listener reconnects):
     * the statement runs on the old session - nothing is recorded, and the method throws instead
     * of answering true for a lock the current session does not hold.
     */
    public function testAConnectionSwappedAfterThePrepareIsNotTakenForTheStatements(): void
    {
        $db = new class (TestEnvironment::mariadb()) extends MariaDbDriver {
            public bool $swap = true;

            public ?string $refused = null;

            protected function bindAndExecute(PDOStatement $stmt, array $params): bool
            {
                if ($this->swap && str_contains($stmt->queryString, 'GET_LOCK')) {
                    $this->swap = false;
                    $listener = function () use (&$listener): void {
                        $this->off('query', $listener);
                        $this->reconnect();
                    };
                    $this->on('query', $listener);
                    $this->query('SELECT 1');
                    // a 'query' listener of the method's own statement, after it ran: refused all the same
                    $probe = function () use (&$probe): void {
                        $this->off('query', $probe);
                        try {
                            $this->reconnect();
                        } catch (ConnectionException $e) {
                            $this->refused = $e->getDebugMessage();
                        }
                    };
                    $this->on('query', $probe);
                }

                return parent::bindAndExecute($stmt, $params);
            }
        };

        try {
            $db->namedLock('job');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('namedLock(): the connection was replaced while the statement ran; a named lock belongs to its session', $e->getDebugMessage());
        }
        $this->assertSame([], $db->heldNamedLocks(), 'nothing recorded for the old session');
        $this->assertFalse($db->isNamedLockHeld('job'), 'the current session does not hold it');
        $this->assertStringStartsWith('reconnect() after a named-lock statement ran', (string) $db->refused);
        $db->reconnect(); // nothing holds it back afterwards
    }

    /**
     * A statement whose prepare fails, and an 'error' listener that reconnects - giving up a lock:
     * nothing holds the old session open while the listeners run, so the lock is free at once.
     */
    public function testAnErrorListenerThatReconnectsAfterAFailedPrepareClosesTheOldSessionAtOnce(): void
    {
        $this->first->namedLock('job');
        $taken = null;
        $listener = function () use (&$listener, &$taken): void {
            $this->first->off('error', $listener);
            $this->first->reconnect(dropNamedLocks: true);
            // the server ends the old session's lock as it handles its close: a short wait
            $taken = $this->second->namedLock('job', 2);
        };
        $this->first->on('error', $listener);

        try {
            $this->first->query('SELEC 1');
            $this->fail('Expected QueryException');
        } catch (QueryException) {
        }
        $this->assertTrue($taken, 'the old session was closed while the listener ran');
    }

    /**
     * A 'query.before' listener that reconnects - giving up the lock this driver held - before
     * the same name is taken again: nothing holds the old session open, so the new one takes it.
     */
    public function testALockGivenUpBeforeTheStatementIsFreeForTheNewSession(): void
    {
        $this->assertTrue($this->first->namedLock('job'));
        $listener = function () use (&$listener): void {
            $this->first->off('query.before', $listener);
            $this->first->reconnect(dropNamedLocks: true);
        };
        $this->first->on('query.before', $listener);

        // the server ends the old session's lock as it handles its close, alongside the new statement: a short wait
        $this->assertTrue($this->first->namedLock('job', 2), 'the old session has closed and taken its lock along');
        $this->assertSame(['job'], $this->first->heldNamedLocks());
        $this->assertTrue($this->first->isNamedLockHeld('job'));
    }

    /**
     * What heldNamedLocks() lists: the names taken and not released, as passed, in the order taken.
     * A busy lock is not taken; a release clears the name whatever the server answers; a second
     * hold refused keeps it.
     */
    public function testHeldNamedLocks(): void
    {
        $this->assertSame([], $this->first->heldNamedLocks());
        $this->second->namedLock('busy');
        $this->assertTrue($this->first->namedLock('job'));
        $this->assertTrue($this->first->namedLock('7'));
        $this->assertFalse($this->first->namedLock('busy'));
        $this->assertSame(['job', '7'], $this->first->heldNamedLocks(), 'a numeric name stays a string; the busy one is not held');

        try {
            $this->first->namedLock('job');
            $this->fail('Expected NamedLockReentryException');
        } catch (NamedLockReentryException) {
        }
        $this->assertSame(['job', '7'], $this->first->heldNamedLocks(), 'still held');

        $this->assertTrue($this->first->releaseNamedLock('job'));
        $this->assertFalse($this->first->releaseNamedLock('busy'), 'another connection holds it');
        $this->assertSame(['7'], $this->first->heldNamedLocks());
        $this->first->namedLock('job');
        $this->assertSame(['7', 'job'], $this->first->heldNamedLocks(), 'in the order taken');

        try {
            $this->first->reconnect();
            $this->fail('Expected NamedLocksHeldException');
        } catch (NamedLocksHeldException $e) {
            $this->assertSame(['7', 'job'], $e->lockNames);
            $this->assertStringStartsWith('reconnect() would give up the named locks this driver holds ("7", "job") with the old session.', (string) $e->getDebugMessage());
        }
    }

    /**
     * A 'query' listener that throws after GET_LOCK() ran: the lock was taken, and the name stays
     * counted ('Query hook failed' carries no code of the server).
     */
    public function testALockTakenBeforeAListenerFailedStaysCounted(): void
    {
        $listener = function () use (&$listener): void {
            $this->first->off('query', $listener);

            throw new PDOException('audit table missing');
        };
        $this->first->on('query', $listener);

        try {
            $this->first->namedLock('job');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Query hook failed', $e->getMessage());
            $this->assertNull($e->driverCode);
        }
        $this->assertSame(['job'], $this->first->heldNamedLocks());
        $this->assertTrue($this->first->isNamedLockHeld('job'), 'taken');

        // the listener's own statement fails with a code of the server: still not the lock's failure
        $listener = function () use (&$listener): void {
            $this->first->off('query', $listener);
            $this->first->insert('no_such_audit_table', ['note' => 'x']);
        };
        $this->first->on('query', $listener);
        try {
            $this->first->namedLock('second');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertNotNull($e->driverCode, "the listener's statement failed on the server");
        }
        $this->assertSame(['job', 'second'], $this->first->heldNamedLocks());
    }

    /**
     * The connection dies while a lock is held (here: killed by another session). The next lock
     * statement fails with the server's code: a name held before stays counted - the driver does
     * not know what the server did with it -, so reconnect() refuses until the locks are given up
     * knowingly. A name that was not held is not counted after such a failure.
     */
    public function testALockOfAConnectionThatDiedIsGivenUpKnowingly(): void
    {
        $this->assertTrue($this->first->namedLock('job'));
        $id = $this->first->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->second->execute('KILL ' . $id);
        $gone = false;
        for ($i = 0; $i < 100 && !$gone; $i++) {
            $gone = $this->second->query('SELECT COUNT(*) FROM information_schema.processlist WHERE id = ?', [$id])->fetchColumn() === 0;
            if (!$gone) {
                usleep(50_000);
            }
        }
        $this->assertTrue($gone, 'the killed connection did not disappear within 5 s');

        foreach (['job' => ['job'], 'other' => ['job']] as $name => $held) {
            try {
                $this->first->namedLock($name);
                $this->fail('Expected QueryException: the connection is gone');
            } catch (QueryException $e) {
                $this->assertNotNull($e->driverCode, 'the failure of the statement');
            }
            $this->assertSame($held, $this->first->heldNamedLocks(), $name);
        }

        try {
            $this->first->reconnect();
            $this->fail('Expected NamedLocksHeldException');
        } catch (NamedLocksHeldException $e) {
            $this->assertSame(['job'], $e->lockNames);
        }
        $this->first->reconnect(dropNamedLocks: true);
        $this->assertSame([], $this->first->heldNamedLocks());
        $this->assertTrue($this->first->namedLock('job'), 'free: the server ended the lock with its connection');
    }

    /**
     * reconnect(dropNamedLocks: true) whose new connection fails: nothing has changed, the locks
     * are still held and counted.
     */
    public function testADropThatCannotReconnectKeepsTheLocks(): void
    {
        $db = $this->mariaDb(['pdoClass' => ReportedVersionPdo::class]);
        $db->namedLock('job');
        ReportedVersionPdo::$server = '10.6.18-MariaDB';

        try {
            $db->reconnect(dropNamedLocks: true);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertNotInstanceOf(NamedLocksHeldException::class, $e);
        }
        $this->assertSame(['job'], $db->heldNamedLocks());
        $this->assertTrue($db->isNamedLockHeld('job'));
    }

    /**
     * A driver of its own (extends AbstractDriver) names no prefix by default: the named-lock
     * methods throw before anything is sent. One that names a prefix takes locks with it; schema()
     * works on any driver.
     */
    public function testADriverOfItsOwnNamesItsPrefix(): void
    {
        $pdo = self::binding()->pdo();
        $plain = new class ($pdo) extends AbstractDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
        foreach (['namedLock' => fn (): mixed => $plain->namedLock('job'), 'releaseNamedLock' => fn (): mixed => $plain->releaseNamedLock('job'), 'isNamedLockHeld' => fn (): mixed => $plain->isNamedLockHeld('job'), 'namedLockHolder' => fn (): mixed => $plain->namedLockHolder('job')] as $method => $call) {
            try {
                $call();
                $this->fail('Expected QueryException: ' . $method);
            } catch (QueryException $e) {
                $this->assertSame(sprintf('%s(): this driver names no prefix for named locks; override namedLockPrefix() (MariaDbDriver uses its configured database and ":", and names none when that database name holds a ":" itself: the lock names of two databases could meet)', $method), $e->getDebugMessage());
            }
        }
        $this->assertSame([], $plain->heldNamedLocks());

        $named = new class ($pdo) extends AbstractDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            protected function namedLockPrefix(): string
            {
                return 'own:';
            }
        };
        $this->assertTrue($named->namedLock('job'));
        $this->assertSame(1, $this->db->query("SELECT IS_USED_LOCK('own:job') IS NOT NULL")->fetchColumn());
        try {
            $named->reconnect();
            $this->fail('Expected NamedLocksHeldException');
        } catch (NamedLocksHeldException $e) {
            $this->assertSame(['job'], $e->lockNames, 'the held locks first - before "this driver cannot reconnect"');
        }
        $this->assertTrue($named->releaseNamedLock('job'));
        $this->create('schema_seen', ['id' => 'key']);
        $this->assertContains('schema_seen', $named->schema()->tables(), 'schema() on a driver of its own');
        $this->assertSame($this->first->schema()->columns('schema_seen'), $named->schema()->columns('schema_seen'));
    }

    /**
     * Who holds a lock, asked on the server: the holder's connection id, this connection's own
     * included; null when nobody does. With the same prefix as namedLock().
     */
    public function testNamedLockHolder(): void
    {
        $this->assertNull($this->first->namedLockHolder('job'));
        $this->second->namedLock('job');
        $secondId = $this->second->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->assertIsInt($secondId);
        $this->assertSame($secondId, $this->first->namedLockHolder('job'));
        $this->assertSame($secondId, $this->second->namedLockHolder('job'), 'its own');

        $database = TestEnvironment::mariadb()['database'];
        $this->assertSame($secondId, $this->db->query('SELECT IS_USED_LOCK(?)', [$database . ':job'])->fetchColumn(), 'the documented form of the name on the server');
    }

    /**
     * An answer that is neither a connection id nor NULL is not taken for one. Replayed with a
     * statement class that delivers the number as text.
     */
    public function testANamedLockHolderThatIsNoNumberIsAnError(): void
    {
        $db = $this->mariaDb(['options' => [PDO::ATTR_STATEMENT_CLASS => [StringColumnStatement::class]]]);

        try {
            $db->namedLockHolder('job');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame("namedLockHolder(): IS_USED_LOCK() answered '7' for \"job\", neither a connection id nor NULL", $e->getDebugMessage());
        }
    }

    public function testWhatIsRefused(): void
    {
        foreach ([
            'namedLock() needs a name' => fn (): bool => $this->first->namedLock(''),
            'releaseNamedLock() needs a name' => fn (): bool => $this->first->releaseNamedLock(''),
            'isNamedLockHeld() needs a name' => fn (): bool => $this->first->isNamedLockHeld(''),
            'namedLockHolder() needs a name' => fn (): ?int => $this->first->namedLockHolder(''),
            'namedLock() takes a timeout of 0 or more seconds, not -1 (MariaDB answers a negative one with NULL)' => fn (): bool => $this->first->namedLock('job', -1),
            'namedLock(): a name with a NUL byte - MariaDB cuts the name there, and different names would be one lock. Encode a binary name (bin2hex()).' => fn (): bool => $this->first->namedLock("k\0a"),
            'releaseNamedLock(): a name with a NUL byte - MariaDB cuts the name there, and different names would be one lock. Encode a binary name (bin2hex()).' => fn (): bool => $this->first->releaseNamedLock("k\0b"),
            'isNamedLockHeld(): a name with a NUL byte - MariaDB cuts the name there, and different names would be one lock. Encode a binary name (bin2hex()).' => fn (): bool => $this->first->isNamedLockHeld("\0"),
            'namedLockHolder(): a name with a NUL byte - MariaDB cuts the name there, and different names would be one lock. Encode a binary name (bin2hex()).' => fn (): ?int => $this->first->namedLockHolder("a\0"),
        ] as $message => $call) {
            try {
                $call();
                $this->fail('Expected QueryException: ' . $message);
            } catch (QueryException $e) {
                $this->assertSame($message, $e->getDebugMessage());
            }
        }

        try {
            $this->first->namedLock(str_repeat('x', 192));
            $this->fail('Expected QueryException: a name beyond 192 bytes with the prefix');
        } catch (QueryException $e) {
            $this->assertSame(1059, $e->driverCode);
        }
        $this->assertSame([], $this->first->heldNamedLocks(), 'nothing refused counts as held');
    }

    /**
     * GET_LOCK() answers NULL after an error on the server: not a busy lock. Replayed with a
     * statement class that delivers NULL.
     */
    public function testANullAnswerIsAnError(): void
    {
        $db = $this->mariaDb(['options' => [PDO::ATTR_STATEMENT_CLASS => [NullColumnStatement::class]]]);

        try {
            $db->namedLock('job');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('namedLock(): GET_LOCK() answered NULL for "job" - an error on the server, not a busy lock', $e->getDebugMessage());
        }
        $this->assertSame([], $db->heldNamedLocks(), 'an error, not a lock');
    }
}
