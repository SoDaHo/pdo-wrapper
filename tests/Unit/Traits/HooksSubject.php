<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Traits;

use Sodaho\PdoWrapper\Traits\HasHooks;

/**
 * The HasHooks trait on its own, with a way to trigger an event from outside.
 */
final class HooksSubject
{
    use HasHooks;

    /**
     * @param array<string, mixed> $data
     */
    public function fireEvent(string $event, array $data): void
    {
        $this->trigger($event, $data);
    }

    /**
     * @return list<string>
     */
    protected function knownEvents(): array
    {
        return ['test', 'query', 'eventA', 'eventB'];
    }
}
