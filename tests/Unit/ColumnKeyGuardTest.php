<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Tests\Support\Untyped;

/**
 * Column => value arrays whose keys are integers - a list, a column named by digits alone - and a
 * having() column that is an expression: a QueryException before anything is sent, never a
 * TypeError from the quoting and never the server's "unknown column". No database needed: every
 * case throws before the statement exists.
 */
class ColumnKeyGuardTest extends TestCase
{
    private function db(): AbstractDriver
    {
        return new class () extends AbstractDriver {
        };
    }

    private function table(): QueryBuilder
    {
        return $this->db()->table('t');
    }

    /**
     * Lists and digit keys are no array<string, mixed>: called untyped, as code without static analysis does.
     *
     * @return iterable<string, array{\Closure(AbstractDriver): mixed, non-empty-string}>
     */
    public static function numericKeys(): iterable
    {
        yield 'builder update()' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->table('t')->where('id', 1)->update(...), [0 => 'x']), 'update() needs column names as keys, got the numeric key 0.'];
        yield 'builder increment() $extra' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->table('t')->where('id', 1)->increment(...), 'n', 1, ['7' => 'x']), 'increment() with $extra needs column names as keys, got the numeric key 7.'];
        yield 'builder decrement() $extra' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->table('t')->where('id', 1)->decrement(...), 'n', 1, ['x']), 'decrement() with $extra needs column names as keys, got the numeric key 0.'];
        yield 'insert()' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->insert(...), 't', ['x']), 'The columns to insert need column names as keys, got the numeric key 0'];
        yield 'update() data' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->update(...), 't', ['42' => 'x'], ['id' => 1]), 'The columns to set need column names as keys, got the numeric key 42'];
        yield 'update() where' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->update(...), 't', ['a' => 'x'], [1]), 'The WHERE conditions need column names as keys, got the numeric key 0'];
        yield 'delete()' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->delete(...), 't', [5 => 1]), 'The WHERE conditions need column names as keys, got the numeric key 5'];
        yield 'findOne()' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->findOne(...), 't', [0 => 1]), 'The WHERE conditions need column names as keys, got the numeric key 0'];
        yield 'upsert() update' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->upsert(...), 't', ['id' => 1], ['x']), 'The columns to set need column names as keys, got the numeric key 0'];
    }

    /**
     * @param \Closure(AbstractDriver): mixed $call
     * @param non-empty-string $message
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('numericKeys')]
    public function testANumericKeyIsAQueryException(\Closure $call, string $message): void
    {
        try {
            $call($this->db());
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringStartsWith($message, (string) $e->getDebugMessage());
        }
    }

    public function testAnAggregateAsAHavingStringIsAQueryException(): void
    {
        try {
            $this->table()->groupBy('status')->having('COUNT(*)', '>', 1);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringStartsWith('having() takes a column or an alias as a string, and "COUNT(*)" is an expression', (string) $e->getDebugMessage());
        }
    }

    public function testHavingStillTakesANameAndARawExpression(): void
    {
        [$sql, $params] = $this->table()
            ->select(['status', Database::raw('COUNT(*) AS n')])
            ->groupBy('status')
            ->having('n', '>', 1)
            ->having(Database::raw('SUM(score)'), '>=', 10)
            ->toSql();

        $this->assertSame('SELECT `status`, COUNT(*) AS n FROM `t` GROUP BY `status` HAVING `n` > ? AND SUM(score) >= ?', $sql);
        $this->assertSame([1, 10], $params);
    }
}
