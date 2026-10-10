<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use LogicException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;

/**
 * The aliases of select() entries as the builder compares them - for the output names of
 * distinct()->count() and for the entries a grouped count() keeps: letters of any script count,
 * as in the quoting of the entries themselves, and the alias ends the entry. No database needed.
 */
class AliasKeyTest extends TestCase
{
    /** The SQL a count() hands to query(), caught there before anything is sent */
    private function countSql(QueryBuilder $query): string
    {
        try {
            $query->count();
        } catch (\Throwable $e) {
            $this->assertInstanceOf(LogicException::class, $e, 'the statement reached query()');

            return $e->getMessage();
        }
        $this->fail('count() reached no query()');
    }

    private function table(): QueryBuilder
    {
        $db = new class () extends AbstractDriver {
            public function query(string $sql, #[\SensitiveParameter] array $params = []): PDOStatement
            {
                throw new LogicException($sql);
            }
        };

        return $db->table('t');
    }

    public function testANonAsciiAliasOfARawEntryIsKeptByAGroupedCount(): void
    {
        $sql = $this->countSql($this->table()->select(['x', Database::raw('COUNT(*) AS zähler')])->groupBy('x')->having('zähler', '>', 1));

        $this->assertSame('SELECT COUNT(*) as aggregate FROM (SELECT COUNT(*) AS zähler FROM `t` GROUP BY `x` HAVING `zähler` > ?) as grouped', $sql);
    }

    public function testTwoEntriesWithTheSameNonAsciiAliasAreAConflictForDistinctCount(): void
    {
        try {
            $this->table()->select(['a as ä', 'b as ä'])->distinct()->count();
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('"ä" appears twice as an output name', (string) $e->getDebugMessage());
        }
    }

    public function testAnAliasEndsTheEntry(): void
    {
        $sql = $this->countSql($this->table()->select(['x', Database::raw("COUNT(*) AS n\n")])->groupBy('x'));

        $this->assertStringStartsWith('SELECT COUNT(*) as aggregate FROM (SELECT 1 as g FROM', $sql, 'a trailing newline: no alias, the entry is not kept');
    }
}
