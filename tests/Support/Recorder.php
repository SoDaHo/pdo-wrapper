<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use Closure;

/**
 * A listener that keeps what it is handed, in order: pass it to on().
 *
 * A local array that a closure fills by reference does the same at run time, but the analyser
 * does not see a listener write to it: once a test has asserted that nothing was recorded, or has
 * emptied the array, it counts as empty for the rest of the test. The entries live in an object
 * instead, and reading them is declared impure: what all() returns changes while the driver
 * under test runs. PHPStan takes that declaration on a getter only from a class that may be
 * extended, hence no "final".
 *
 * @template T
 */
class Recorder
{
    /** @var list<T> */
    private array $entries = [];

    /**
     * @param Closure(array<string, mixed>): T $pick What to keep of the event data
     */
    public function __construct(private readonly Closure $pick)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __invoke(array $data): void
    {
        $this->entries[] = ($this->pick)($data);
    }

    /**
     * @return list<T>
     *
     * @phpstan-impure
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * Takes the latest entry out.
     *
     * @return T|null Null when there is none
     *
     * @phpstan-impure
     */
    public function pop(): mixed
    {
        return array_pop($this->entries);
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
