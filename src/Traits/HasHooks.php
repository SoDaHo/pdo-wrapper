<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Traits;

/**
 * Provides event hook functionality for database operations.
 *
 * "Fail Hard" implementation: Exceptions in hooks bubble up to the caller (a PDOException from a
 * 'transaction.begin' or 'transaction.rollback' hook arrives as TransactionException); the first failing
 * hook stops the remaining ones (after a failing 'transaction.begin' hook a rollback of the new
 * transaction is attempted, best effort, see AbstractDriver). Exception: 'transaction.commit' listeners run after the commit,
 * so all of them run and their failures arrive together in a CommitHookException - unless a
 * transaction left open by a listener cannot be rolled back (or the connection state cannot be
 * read); the remaining listeners are then skipped and listed as failures (see AbstractDriver).
 * 'transaction.rollback' listeners run only after a rollback this library performed and that
 * succeeded (rollback(), or the automatic rollback in transaction()/updateMultiple()). Measured on
 * MySQL 8.0 and MariaDB 11.4 with mysqlnd: after a deadlock (transaction rolled back by the server)
 * and after a lock wait timeout (only the statement rolled back) PDO still reports the transaction,
 * the library's ROLLBACK succeeds and the listeners run; after a lost connection the rollback fails
 * and no listener runs.
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
