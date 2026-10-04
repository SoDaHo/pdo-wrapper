<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract;

use Sodaho\PdoWrapper\Exception\DatabaseException;

/**
 * off(): a listener goes as it came - every registration of it, told apart by identity - and
 * mistakes are loud.
 */
class HookRemovalTest extends ContractTestCase
{
    /** @var list<string> */
    private array $heard = [];

    public function heardByMethod(): void
    {
        $this->heard[] = 'method';
    }

    public function testARemovedListenerHearsNothingMore(): void
    {
        $closure = function (): void {
            $this->heard[] = 'closure';
        };
        $other = function (): void {
            $this->heard[] = 'other';
        };
        $this->assertSame($this->db, $this->db->on('query', $closure)->on('query', $other)->on('query', $closure)->on('query', [$this, 'heardByMethod']));

        $this->db->query('SELECT 1');
        $this->assertSame(['closure', 'other', 'closure', 'method'], $this->heard);

        $this->heard = [];
        $this->assertSame($this->db, $this->db->off('query', $closure), 'chainable');
        $this->db->off('query', [$this, 'heardByMethod']);
        $this->db->query('SELECT 1');
        $this->assertSame(['other'], $this->heard, 'every registration of the closure is gone, the method too');
    }

    /**
     * A listener removed while its event is told still runs for that telling; the change counts
     * from the next one.
     */
    public function testRemovingDuringTheEventCountsFromTheNextOne(): void
    {
        $second = function (): void {
            $this->heard[] = 'second';
        };
        $first = function () use ($second): void {
            $this->heard[] = 'first';
            if (count($this->heard) === 1) {
                $this->db->off('query', $second);
            }
        };
        $this->db->on('query', $first)->on('query', $second);

        $this->db->query('SELECT 1');
        $this->db->query('SELECT 1');

        $this->assertSame(['first', 'second', 'first'], $this->heard);

        // the same for the transaction events, which the driver tells itself
        $this->heard = [];
        $end = function (): void {
            $this->heard[] = 'end';
        };
        $remover = function () use ($end): void {
            $this->heard[] = 'remover';
            $this->db->off('transaction.end', $end);
        };
        $this->db->on('transaction.end', $remover)->on('transaction.end', $end);
        $this->db->transaction(static fn (): null => null);
        $this->assertSame(['remover', 'end'], $this->heard);
    }

    public function testAnUnknownEventOrAListenerThatIsNotRegisteredIsLoud(): void
    {
        $listener = static function (): void {
        };

        try {
            $this->db->off('qeury', $listener);
            $this->fail('Expected DatabaseException: unknown event');
        } catch (DatabaseException $e) {
            $this->assertSame('Unknown hook event', $e->getMessage());
            $this->assertStringStartsWith('Unknown event "qeury": no listener can be registered for it.', (string) $e->getDebugMessage());
        }

        $this->db->on('query', $listener);
        foreach (['error' => 'registered for another event', 'query' => 'the second off()'] as $event => $case) {
            if ($event === 'query') {
                $this->db->off('query', $listener);
            }
            try {
                $this->db->off($event, $listener);
                $this->fail('Expected DatabaseException: ' . $case);
            } catch (DatabaseException $e) {
                $this->assertSame('Unknown hook listener', $e->getMessage(), $case);
                $this->assertSame(sprintf('off(): the callback is not registered for "%s"', $event), $e->getDebugMessage(), $case);
            }
        }

        // [object, 'method'] pairs: the same method of another object that looks the same is another callback
        $make = static fn (): object => new class () {
            public function hear(): void
            {
            }
        };
        $registered = $make();
        $this->db->on('query', [$registered, 'hear']);
        try {
            $this->db->off('query', [$make(), 'hear']);
            $this->fail('Expected DatabaseException: another object');
        } catch (DatabaseException $e) {
            $this->assertSame('Unknown hook listener', $e->getMessage());
        }
        $this->db->off('query', [$registered, 'hear']);

        $equal = static function (): void {
        };
        $this->db->on('query', $equal);
        $this->expectException(DatabaseException::class);
        $this->db->off('query', static function (): void {
        }); // a closure that looks the same is another object
    }
}
