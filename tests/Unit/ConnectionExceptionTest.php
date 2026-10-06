<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PDOException;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\ConnectionRefusal;
use Sodaho\PdoWrapper\Exception\NamedLocksHeldException;

/**
 * ConnectionException takes the parameters of DatabaseException as before, then $refusal.
 */
class ConnectionExceptionTest extends TestCase
{
    public function testTheParametersOfDatabaseExceptionThenTheRefusal(): void
    {
        $failure = new PDOException('access denied');
        $failure->errorInfo = ['28000', 1045, 'access denied'];

        $e = new ConnectionException('Database connection failed', $failure, 'debug');
        $this->assertSame(['28000', 1045], [$e->sqlState, $e->driverCode], 'the codes of the failure');
        $this->assertNull($e->refusal);

        $listener = new ConnectionException('Database connection failed', $failure, 'debug', true);
        $this->assertSame([null, null], [$listener->sqlState, $listener->driverCode], "a listener's failure carries no codes, as for DatabaseException");

        $refused = new ConnectionException(message: 'Database connection failed', debugMessage: 'debug', refusal: ConnectionRefusal::MariaDbTooOld);
        $this->assertSame(ConnectionRefusal::MariaDbTooOld, $refused->refusal);
        $this->assertSame('mariadb_too_old', $refused->refusal->value);
    }

    public function testHeldLocksAreAConnectionExceptionWithTheirNames(): void
    {
        $e = new NamedLocksHeldException(debugMessage: 'debug', lockNames: ['a', '7']);
        $this->assertInstanceOf(ConnectionException::class, $e);
        $this->assertSame('Database connection failed', $e->getMessage());
        $this->assertSame(['a', '7'], $e->lockNames);
        $this->assertNull($e->refusal);
    }
}
