<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

/**
 * A configured database whose name holds a ":": the prefix of a named lock ("<database>:") would be
 * ambiguous - the lock "c" of "a:b" and the lock "b:c" of "a" are one name on the server. The
 * named-lock methods refuse; the connection itself works.
 */
class NamedLockPrefixTest extends ContractTestCase
{
    private const DATABASE = 'pdo_wrapper_lock:prefix';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute('CREATE DATABASE IF NOT EXISTS `' . self::DATABASE . '`');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        parent::tearDown();
    }

    public function testTheNamedLockMethodsRefuseWhileTheConnectionWorks(): void
    {
        $db = new MariaDbDriver(['database' => self::DATABASE] + TestEnvironment::mariadb());
        $this->assertSame(self::DATABASE, $db->query('SELECT DATABASE()')->fetchColumn(), 'the connection works');

        foreach (['namedLock', 'releaseNamedLock', 'isNamedLockHeld', 'namedLockHolder'] as $method) {
            try {
                $db->{$method}('c');
                $this->fail('Expected QueryException: ' . $method);
            } catch (QueryException $e) {
                $this->assertStringStartsWith($method . '(): this driver names no prefix for named locks', (string) $e->getDebugMessage());
                $this->assertStringContainsString('names none when that database name holds a ":" itself', (string) $e->getDebugMessage());
            }
        }
        $this->assertSame([], $db->heldNamedLocks());
        $this->assertNull($this->db->query('SELECT IS_USED_LOCK(?)', [self::DATABASE . ':c'])->fetchColumn(), 'nothing was taken on the server');
    }
}
