<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Traits;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Traits\HasHooks;

class HasHooksTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        $this->subject = new class () {
            use HasHooks;

            public function fireEvent(string $event, array $data): void
            {
                $this->trigger($event, $data);
            }
        };
    }

    public function testCanRegisterHook(): void
    {
        $called = false;

        $this->subject->on('test', function () use (&$called) {
            $called = true;
        });

        $this->subject->fireEvent('test', []);

        $this->assertTrue($called);
    }

    public function testHookReceivesData(): void
    {
        $receivedData = null;

        $this->subject->on('query', function (array $data) use (&$receivedData) {
            $receivedData = $data;
        });

        $this->subject->fireEvent('query', ['sql' => 'SELECT 1', 'duration' => 0.5]);

        $this->assertSame(['sql' => 'SELECT 1', 'duration' => 0.5], $receivedData);
    }

    public function testMultipleHooksForSameEvent(): void
    {
        $counter = 0;

        $this->subject->on('test', function () use (&$counter) {
            $counter++;
        });

        $this->subject->on('test', function () use (&$counter) {
            $counter++;
        });

        $this->subject->fireEvent('test', []);

        $this->assertSame(2, $counter);
    }

    public function testUnregisteredEventDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();
        $this->subject->fireEvent('nonexistent', []);
    }

    public function testDifferentEventsAreSeparate(): void
    {
        $eventACalled = false;
        $eventBCalled = false;

        $this->subject->on('eventA', function () use (&$eventACalled) {
            $eventACalled = true;
        });

        $this->subject->on('eventB', function () use (&$eventBCalled) {
            $eventBCalled = true;
        });

        $this->subject->fireEvent('eventA', []);

        $this->assertTrue($eventACalled);
        $this->assertFalse($eventBCalled);
    }
}
