<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\DatabaseException;
use Sodaho\PdoWrapper\Exception\QueryException;

class CommitHookExceptionTest extends TestCase
{
    /**
     * The third argument is optional: code built against 1.1 keeps constructing the exception
     * with two arguments (fixtures, tests) and reads the flag as false.
     */
    public function testTwoArgumentConstructionMeansConnectionNotInTransaction(): void
    {
        $first = new RuntimeException('hook failed', 7);
        $second = new LogicException('listener left a transaction open');

        $e = new CommitHookException($first, [$first, $second]);

        $this->assertFalse($e->connectionInTransaction);
        $this->assertSame([$first, $second], $e->failures);
        $this->assertSame($first, $e->getPrevious());
        $this->assertSame(0, $e->getCode(), 'always 0: the code of a listener\'s exception is not passed on');
        $this->assertNull($e->sqlState);
        $this->assertNull($e->driverCode);
        $this->assertInstanceOf(DatabaseException::class, $e);
        $this->assertSame('2 failure(s), first: RuntimeException: hook failed', $e->getDebugMessage());
    }

    /**
     * What the database said travels with the cause: a PDOException's errorInfo becomes $sqlState
     * and $driverCode, and an exception of this library hands its own on. getCode() stays 0.
     */
    public function testTheCodesOfTheCauseAreHandedOn(): void
    {
        $pdo = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $pdo->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $query = new QueryException(message: 'Query failed', previous: $pdo);

        $this->assertSame(['23000', 1062, 0], [$query->sqlState, $query->driverCode, $query->getCode()]);

        $hook = new CommitHookException($query, [$query]);
        $this->assertSame(['23000', 1062, 0], [$hook->sqlState, $hook->driverCode, $hook->getCode()]);

        // no errorInfo, or one that says nothing: no codes, whatever the exception's own code is
        $bare = new \PDOException('made by a listener', 5);
        $this->assertSame([null, null, 0], $this->codes(new QueryException(previous: $bare)));
        $empty = new \PDOException('reported without an exception');
        $empty->errorInfo = ['', null, null];
        $this->assertSame([null, null, 0], $this->codes(new QueryException(previous: $empty)));
        $odd = new \PDOException('odd');
        $odd->errorInfo = [42000, '1064', 'syntax'];
        $this->assertSame([null, null, 0], $this->codes(new QueryException(previous: $odd)), 'only a string SQLSTATE and an integer driver code are taken');
        $this->assertSame([null, null, 0], $this->codes(new DatabaseException()));
        $this->assertSame([null, null, 0], $this->codes(new QueryException(previous: new RuntimeException('other', 9))));

        // only PDO's own exception is read: another one that happens to have an errorInfo is not
        $lookalike = new class ('not PDO') extends RuntimeException {
            /** @var array{string, int} */
            public array $errorInfo = ['23000', 1062];
        };
        $this->assertSame([null, null, 0], $this->codes(new QueryException(previous: $lookalike)));
    }

    /**
     * @return array{?string, ?int, int|string}
     */
    private function codes(DatabaseException $e): array
    {
        return [$e->sqlState, $e->driverCode, $e->getCode()];
    }

    public function testConnectionInTransactionFlagIsExposedReadonly(): void
    {
        $first = new RuntimeException('hook failed');

        $e = new CommitHookException($first, [$first], true);

        $this->assertTrue($e->connectionInTransaction);
    }
}
