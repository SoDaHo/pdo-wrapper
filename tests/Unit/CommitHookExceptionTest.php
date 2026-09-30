<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\DatabaseException;

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
        $this->assertSame(7, $e->getCode());
        $this->assertInstanceOf(DatabaseException::class, $e);
        $this->assertSame('2 failure(s), first: RuntimeException: hook failed', $e->getDebugMessage());
    }

    public function testConnectionInTransactionFlagIsExposedReadonly(): void
    {
        $first = new RuntimeException('hook failed');

        $e = new CommitHookException($first, [$first], true);

        $this->assertTrue($e->connectionInTransaction);
    }
}
