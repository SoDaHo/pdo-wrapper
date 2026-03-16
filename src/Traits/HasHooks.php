<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Traits;

use Closure;

/**
 * Provides event hook functionality for database operations.
 *
 * Events: 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback'
 */
trait HasHooks
{
    /** @var array<string, Closure[]> */
    private array $hooks = [];

    /**
     * Register a callback for an event.
     *
     * @param string $event Event name
     * @param Closure $callback Callback receiving event data array
     */
    public function on(string $event, Closure $callback): void
    {
        $this->hooks[$event][] = $callback;
    }

    /**
     * Trigger all callbacks for an event.
     *
     * @param string $event Event name
     * @param array<string, mixed> $data Event data to pass to callbacks
     */
    protected function trigger(string $event, array $data): void
    {
        foreach ($this->hooks[$event] ?? [] as $callback) {
            $callback($data);
        }
    }
}
