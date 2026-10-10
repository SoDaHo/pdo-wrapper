<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PDOException;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Exception\DatabaseException;
use Sodaho\PdoWrapper\Exception\LockOutsideTransactionException;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * The refusal of a locking read outside a transaction keeps what PDO threw while its transaction
 * state was read as previous, and carries no codes: nothing was sent, no failure of the read stands
 * behind it. Kept out by DatabaseException's codesOfPrevious - the flag for a listener's failure
 * means something else.
 */
class LockOutsideTransactionExceptionTest extends TestCase
{
    public function testThePreviousIsKeptAndItsCodesAreNot(): void
    {
        $unreadable = new PDOException('state unreadable');
        $unreadable->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];

        $e = new LockOutsideTransactionException(previous: $unreadable, debugMessage: 'debug');

        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertSame($unreadable, $e->getPrevious());
        $this->assertSame([null, null], [$e->sqlState, $e->driverCode]);
        $this->assertSame('Query refused: a row lock outside of a transaction', $e->getMessage());
    }

    public function testCodesOfPreviousKeepsTheCodesOutAsAListenerFailureDoes(): void
    {
        $failure = new PDOException('duplicate');
        $failure->errorInfo = ['23000', 1062, 'Duplicate entry'];

        $this->assertSame(['23000', 1062], self::codes(new DatabaseException('failed', $failure)));
        $this->assertSame([null, null], self::codes(new DatabaseException('refused', $failure, codesOfPrevious: false)));
        $this->assertSame([null, null], self::codes(new DatabaseException('a listener failed', $failure, listenerFailure: true)));
    }

    /** @return array{?string, ?int} */
    private static function codes(DatabaseException $e): array
    {
        return [$e->sqlState, $e->driverCode];
    }
}
