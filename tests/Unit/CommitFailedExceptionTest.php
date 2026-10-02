<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use Error;
use LogicException;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;

/**
 * CommitFailedException::$outcome is told once, by the driver: everyone may read it, nobody
 * assigns it, and what was told is not told differently afterwards.
 */
class CommitFailedExceptionTest extends TestCase
{
    public function testTheOutcomeIsNullUntilItIsToldAndStaysWhatWasTold(): void
    {
        $e = new CommitFailedException('Failed to commit transaction');
        $this->assertNull($e->outcome);

        $e->settle(DatabaseInterface::TRANSACTION_ROLLED_BACK);
        $this->assertSame('rolled_back', $e->outcome);

        try {
            $e->settle(DatabaseInterface::TRANSACTION_LOST);
            $this->fail('Expected LogicException');
        } catch (LogicException $second) {
            $this->assertSame('The outcome of this failed commit has already been told', $second->getMessage());
        }
        $this->assertSame('rolled_back', $e->outcome, 'what was told stays told');
    }

    public function testTheOutcomeCannotBeAssignedFromOutside(): void
    {
        $e = new CommitFailedException('Failed to commit transaction');
        // The property is named at run time: written out, the assignment would not pass static analysis
        $assign = static function (object $target, string $property, string $value): void {
            $target->{$property} = $value;
        };

        try {
            $assign($e, 'outcome', DatabaseInterface::TRANSACTION_ROLLED_BACK);
            $this->fail('Expected Error');
        } catch (Error $error) {
            $this->assertStringContainsString('Cannot modify private(set) property', $error->getMessage());
        }
        $this->assertNull($e->outcome);
    }
}
