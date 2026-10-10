<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\ListenerTransactionException;

/**
 * A listener of an event a driver of its own adds (knownEvents()) may not steer a transaction: such
 * an event may run in the middle of anything. beginTransaction(), commit(), rollback() and
 * transaction() refuse there before anything is done - so no connection is needed to see it.
 */
class CustomEventListenerTest extends TestCase
{
    public function testAListenerOfACustomEventMaySteerNoTransaction(): void
    {
        $db = new class () extends AbstractDriver {
            protected function knownEvents(): array
            {
                return [...parent::knownEvents(), 'cache.hit'];
            }

            public function hit(): void
            {
                $this->trigger('cache.hit', []);
            }
        };
        $calls = [
            'beginTransaction()' => static function () use ($db): void {
                $db->beginTransaction();
            },
            'commit()' => static function () use ($db): void {
                $db->commit();
            },
            'rollback()' => static function () use ($db): void {
                $db->rollback();
            },
            'transaction()' => static function () use ($db): void {
                $db->transaction(static fn (): null => null);
            },
        ];
        $refused = [];
        $db->on('cache.hit', static function () use ($calls, &$refused): void {
            foreach ($calls as $method => $call) {
                try {
                    $call();
                } catch (ListenerTransactionException $e) {
                    $refused[$method] = (string) $e->getDebugMessage();
                }
            }
        });

        $db->hit();

        $this->assertSame(array_keys($calls), array_keys($refused), 'every one refused');
        foreach ($refused as $method => $message) {
            $this->assertStringStartsWith("{$method} was called from inside a cache.hit listener of this driver:", $message);
        }
    }
}
