<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Traits;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\DatabaseException;

/**
 * The hook registry of HasHooks without a driver: which events on() accepts, that a driver adds its
 * own, that a refused listener is not kept, and how listeners of one and of several events are called.
 */
class HasHooksTest extends TestCase
{
    private HooksSubject $subject;

    protected function setUp(): void
    {
        $this->subject = new HooksSubject();
    }

    /**
     * A listener for a name nothing triggers would never run: on() refuses it, and says which
     * names there are - in the debug message, like every value.
     */
    public function testOnRefusesAnUnknownEvent(): void
    {
        $db = new class () extends AbstractDriver {
        }; // a driver without a connection: on() needs none
        $noop = static function (): void {
        };

        foreach (['query.before', 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback', 'transaction.end'] as $event) {
            $this->assertSame($db, $db->on($event, $noop));
        }

        foreach (['qeury', 'Query', 'query.after', 'transaction', 'transaction.ended', ''] as $event) {
            try {
                $db->on($event, $noop);
                $this->fail('Expected DatabaseException for "' . $event . '"');
            } catch (DatabaseException $e) {
                $this->assertSame(DatabaseException::class, $e::class);
                $this->assertSame('Unknown hook event', $e->getMessage());
                $this->assertSame(
                    sprintf('Unknown event "%s": a listener for it would never run. Known events: query.before, query, error, transaction.begin, transaction.commit, transaction.rollback, transaction.end', $event),
                    $e->getDebugMessage()
                );
            }
        }
    }

    /**
     * A driver that triggers events of its own names them; its listeners register and run.
     */
    public function testADriverAddsItsOwnEvents(): void
    {
        $db = new class () extends AbstractDriver {
            protected function knownEvents(): array
            {
                return [...parent::knownEvents(), 'cache.hit'];
            }

            public function hit(): void
            {
                $this->trigger('cache.hit', ['key' => 'a']);
            }
        };
        $seen = [];
        $db->on('cache.hit', static function (array $data) use (&$seen): void {
            $seen[] = $data['key'];
        });
        $db->on('query', static function (): void {
        });
        $db->hit();

        $this->assertSame(['a'], $seen);
        $this->expectException(DatabaseException::class);
        $db->on('cache.miss', static function (): void {
        });
    }

    /**
     * Nothing was registered for an event that was refused.
     */
    public function testARefusedListenerIsNotRegistered(): void
    {
        $called = false;
        try {
            $this->subject->on('nonexistent', function () use (&$called) {
                $called = true;
            });
            $this->fail('Expected DatabaseException');
        } catch (DatabaseException) {
            $this->subject->fireEvent('nonexistent', []);
        }

        $this->assertFalse($called);
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
