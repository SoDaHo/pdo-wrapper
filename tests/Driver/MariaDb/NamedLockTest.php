<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;
use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\NamedLockReentryException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

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
     * Not bound to a transaction: a rollback keeps it; the end of the connection gives it up.
     */
    public function testHeldByTheConnectionNotByATransaction(): void
    {
        $this->first->beginTransaction();
        $this->first->namedLock('job');
        $this->first->rollback();
        $this->assertTrue($this->first->isNamedLockHeld('job'), 'the rollback kept it');

        $this->first->reconnect();
        $this->assertFalse($this->first->isNamedLockHeld('job'), 'given up with the old connection');
        $this->assertTrue($this->second->namedLock('job', 2));
    }

    /**
     * A listener that calls reconnect() while a named-lock statement runs: the statement ran on
     * the old session, which went with its locks - the method throws instead of answering for it.
     */
    public function testAReconnectWhileTheStatementRunsThrows(): void
    {
        foreach (['namedLock' => fn (): bool => $this->first->namedLock('job'), 'isNamedLockHeld' => fn (): bool => $this->first->isNamedLockHeld('job'), 'releaseNamedLock' => fn (): bool => $this->first->releaseNamedLock('job')] as $method => $call) {
            $listener = function () use (&$listener): void {
                $this->first->off('query', $listener);
                $this->first->reconnect();
            };
            $this->first->on('query', $listener);
            try {
                $call();
                $this->fail('Expected QueryException: ' . $method);
            } catch (QueryException $e) {
                $this->assertSame(sprintf('%s(): the connection was replaced while the statement ran (reconnect() in a listener); a named lock belongs to its session, and that session is gone', $method), $e->getDebugMessage());
            }
        }
        $this->assertTrue($this->second->namedLock('job', 2), 'the lock went with the old session');
    }

    public function testWhatIsRefused(): void
    {
        foreach ([
            'namedLock() needs a name' => fn (): bool => $this->first->namedLock(''),
            'releaseNamedLock() needs a name' => fn (): bool => $this->first->releaseNamedLock(''),
            'isNamedLockHeld() needs a name' => fn (): bool => $this->first->isNamedLockHeld(''),
            'namedLock() takes a timeout of 0 or more seconds, not -1 (MariaDB answers a negative one with NULL)' => fn (): bool => $this->first->namedLock('job', -1),
            'namedLock(): a name with a NUL byte - MariaDB cuts the name there, and different names would be one lock. Encode a binary name (bin2hex()).' => fn (): bool => $this->first->namedLock("k\0a"),
            'releaseNamedLock(): a name with a NUL byte - MariaDB cuts the name there, and different names would be one lock. Encode a binary name (bin2hex()).' => fn (): bool => $this->first->releaseNamedLock("k\0b"),
            'isNamedLockHeld(): a name with a NUL byte - MariaDB cuts the name there, and different names would be one lock. Encode a binary name (bin2hex()).' => fn (): bool => $this->first->isNamedLockHeld("\0"),
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
    }
}
