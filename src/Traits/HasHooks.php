<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Traits;

/**
 * Provides event hook functionality for database operations.
 *
 * "Fail Hard" implementation: Exceptions in hooks bubble up to the caller; the first failing
 * hook stops the remaining ones. Exception: 'transaction.commit' listeners run after the commit,
 * so all of them run and their failures arrive together in a CommitHookException - unless a
 * transaction left open by a listener cannot be rolled back (or the connection state cannot be
 * read); the remaining listeners are then skipped and listed as failures (see AbstractDriver).
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
