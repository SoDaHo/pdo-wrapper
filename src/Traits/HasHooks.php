<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Traits;

/**
 * Provides event hook functionality for database operations.
 *
 * "Fail Hard" implementation: Exceptions in hooks bubble up to the caller.
 * Events: 'query', 'error', 'transaction.begin', 'transaction.commit', 'transaction.rollback'
 */
trait HasHooks
{
    /** @var array<string, array<callable>> */
    private array $hooks = [];

    /**
     * Register a callback for an event.
     *
     * @param string $event Event name
     * @param callable $callback Callback receiving event data array
     */
    public function on(string $event, callable $callback): static
    {
        $this->hooks[$event][] = $callback;

        return $this;
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
