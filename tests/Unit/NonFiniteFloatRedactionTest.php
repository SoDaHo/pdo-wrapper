<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * A float INF, -INF or NAN cannot be bound: refused before anything is sent, with the parameter's
 * position in the debug message and in the 'error' payload. Without redactParameters both name the
 * value; with it neither does - "a non-finite float" (Daybreak review of the eighth candidate: the
 * option promised no bound value in a debug message or a hook payload, and both showed INF). No
 * database needed: the refusal comes first.
 */
class NonFiniteFloatRedactionTest extends TestCase
{
    /**
     * The refusal of each value, with or without the option: the debug message and the 'error'
     * payload carry the same text, the 'params' of the payloads the value or '[redacted]'.
     */
    public function testANonFiniteFloatIsNamedOnlyWithoutTheOption(): void
    {
        foreach ([[INF, 'INF'], [-INF, '-INF'], [NAN, 'NAN']] as [$value, $name]) {
            foreach ([false => $name, true => 'a non-finite float'] as $redact => $shown) {
                $db = new class (null, (bool) $redact) extends AbstractDriver {
                };
                $payloads = [];
                foreach (['query.before', 'error'] as $event) {
                    $db->on($event, static function (array $data) use (&$payloads, $event): void {
                        $payloads[$event] = $data;
                    });
                }
                $expected = sprintf('Cannot bind %s (parameter #1): MariaDB has no such number and would compare it as 0', $shown);
                $case = $name . ($redact ? ', redacted' : '');

                try {
                    $db->query('SELECT ?', [$value]);
                    $this->fail('Expected QueryException: ' . $case);
                } catch (QueryException $e) {
                    $this->assertSame('Query failed', $e->getMessage(), $case);
                    $this->assertSame($expected . ' | SQL: SELECT ?', $e->getDebugMessage(), $case);
                }
                $this->assertSame($expected, $payloads['error']['error'] ?? null, $case);
                if ($redact) {
                    $this->assertSame(['[redacted]'], $payloads['error']['params'] ?? null, $case);
                    $this->assertSame(['[redacted]'], $payloads['query.before']['params'] ?? null, $case);
                }
            }
        }
    }
}
