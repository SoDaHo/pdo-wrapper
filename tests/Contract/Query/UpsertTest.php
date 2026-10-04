<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Fetched;

/**
 * upsert(), upsertReturning(), insertWhen() with an update and insertWhenReturning(): the row
 * is inserted, or the row it collides with on any unique key is changed - with the update's
 * assignments in their order, raw() values with bindings and Database::value() -, and the
 * returning forms hand back the row.
 */
class UpsertTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create('counters', ['id' => 'id', 'name' => 'text UNIQUE NOT NULL', 'code' => 'text UNIQUE', 'n' => 'int NOT NULL', 'm' => 'int']);
        $this->db->insert('counters', ['name' => 'a', 'code' => 'A', 'n' => 1, 'm' => 0]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->db->table('counters')->orderBy('id')->get() as $row) {
            $rows[(string) $row['name']] = ['code' => $row['code'], 'n' => Fetched::int($row['n']), 'm' => $row['m'] === null ? null : Fetched::int($row['m'])];
        }

        return $rows;
    }

    public function testUpsertInsertsOrChangesTheRow(): void
    {
        $this->db->table('counters')->upsert(['name' => 'b', 'code' => 'B', 'n' => 5], ['n' => Database::raw('n + ?', [100])]);
        $this->db->table('counters')->upsert(['name' => 'a', 'code' => 'A', 'n' => 5], ['n' => Database::raw('n + ?', [100])]);

        $this->assertSame(['a' => ['code' => 'A', 'n' => 101, 'm' => 0], 'b' => ['code' => 'B', 'n' => 5, 'm' => null]], $this->rows());
    }

    /**
     * Any unique key counts as the duplicate: a row that matches the existing one only on "code"
     * changes that row.
     */
    public function testACollisionOnAnyUniqueKeyChangesTheRow(): void
    {
        $this->db->table('counters')->upsert(['name' => 'other', 'code' => 'A', 'n' => 7], ['n' => 7]);

        $this->assertSame(['a' => ['code' => 'A', 'n' => 7, 'm' => 0]], $this->rows());
    }

    /**
     * The update's assignments in the order given, applied from left to right; Database::value()
     * is the value the row would have been inserted with.
     */
    public function testTheUpdateRunsInItsOrder(): void
    {
        $this->db->table('counters')->upsert(['name' => 'a', 'n' => 10, 'm' => 0], ['n' => Database::raw('n + VALUE(n)'), 'm' => Database::raw('n')]);
        $this->assertSame(['code' => 'A', 'n' => 11, 'm' => 11], $this->rows()['a'], 'm sees the new n');

        $this->db->table('counters')->upsert(['name' => 'a', 'n' => 3, 'm' => 0], ['m' => Database::raw('n'), 'n' => Database::value('n')]);
        $this->assertSame(['code' => 'A', 'n' => 3, 'm' => 11], $this->rows()['a'], 'm sees the old n, n takes the inserted value');
    }

    /**
     * The values are bound in the order of the SQL: the row's, then the update's (raw bindings in
     * their place).
     */
    public function testTheValuesAreBoundInTheOrderOfTheSql(): void
    {
        $this->db->table('counters')->upsert(['name' => 'a', 'code' => 'A', 'n' => 1], ['m' => Database::raw('? * ?', [6, 7]), 'code' => 'Z', 'n' => Database::raw('n - ?', [1])]);

        $this->assertSame(['a' => ['code' => 'Z', 'n' => 0, 'm' => 42]], $this->rows());
    }

    public function testUpsertReturningHandsBackTheRow(): void
    {
        $inserted = $this->db->table('counters')->upsertReturning(['name' => 'b', 'n' => 5], ['n' => Database::raw('n + 1')], ['id', 'name', 'n']);
        $changed = $this->db->table('counters')->upsertReturning(['name' => 'a', 'n' => 5], ['n' => Database::raw('n + 1')], ['id', 'name', 'n']);
        $unchanged = $this->db->table('counters')->upsertReturning(['name' => 'a', 'n' => 5], ['n' => 2], ['name', 'n', Database::raw('n * 2 AS twice')]);
        $everything = $this->db->table('counters')->upsertReturning(['name' => 'b', 'n' => 5], ['m' => 9]);

        $this->assertSame(['name' => 'b', 'n' => 5], ['name' => $inserted['name'], 'n' => Fetched::int($inserted['n'])]);
        $this->assertSame(2, Fetched::int($inserted['id']), 'the new row');
        $this->assertSame(['id' => 1, 'name' => 'a', 'n' => 2], ['id' => Fetched::int($changed['id']), 'name' => $changed['name'], 'n' => Fetched::int($changed['n'])], 'the existing row after the update');
        $this->assertSame(['name', 'n', 'twice'], array_keys($unchanged));
        $this->assertSame([2, 4], [Fetched::int($unchanged['n']), Fetched::int($unchanged['twice'])], 'the row also when it already held those values');
        $this->assertSame(['id', 'name', 'code', 'n', 'm'], array_keys($everything), "'*' by default");
        $this->assertSame(9, Fetched::int($everything['m']));
    }

    /**
     * insertWhen() with an update: the condition decides whether anything happens at all; a
     * duplicate is changed instead of inserted.
     */
    public function testInsertWhenWithAnUpdate(): void
    {
        $this->db->table('counters')->insertWhen(['name' => 'a', 'n' => 1], '? = 1', [0], ['n' => 50]);
        $this->assertSame(1, $this->rows()['a']['n'], 'condition false: nothing changed');

        $this->db->table('counters')->insertWhen(['name' => 'a', 'n' => 1], '? = 1', [1], ['n' => Database::raw('n + ?', [50])]);
        $this->db->table('counters')->insertWhen(['name' => 'b', 'n' => 1], '? = 1', [1], ['n' => 50]);

        $this->assertSame(['a' => ['code' => 'A', 'n' => 51, 'm' => 0], 'b' => ['code' => null, 'n' => 1, 'm' => null]], $this->rows());

        // Database::value() in the update of the INSERT ... SELECT form: the value of the row
        $this->db->table('counters')->insertWhen(['name' => 'a', 'n' => 7], '? = 1', [1], ['n' => Database::raw('n + VALUE(n)'), 'm' => Database::value('n')]);
        $this->assertSame(['code' => 'A', 'n' => 58, 'm' => 7], $this->rows()['a']);
    }

    public function testInsertWhenReturning(): void
    {
        $this->assertNull($this->db->table('counters')->insertWhenReturning(['name' => 'a', 'n' => 1], '? = 1', [0], ['n' => 50]), 'condition false');
        $changed = $this->db->table('counters')->insertWhenReturning(['name' => 'a', 'n' => 1], '? = 1', [1], ['n' => 50], ['name', 'n']);
        $inserted = $this->db->table('counters')->insertWhenReturning(['name' => 'c', 'n' => 3], 'NOT EXISTS (SELECT 1 FROM counters WHERE n > ?)', [100], [], ['name', 'n']);
        $refused = $this->db->table('counters')->insertWhenReturning(['name' => 'd', 'n' => 3], 'NOT EXISTS (SELECT 1 FROM counters WHERE n > ?)', [10]);

        $this->assertSame(['name' => 'a', 'n' => 50], ['name' => $changed['name'] ?? null, 'n' => Fetched::int($changed['n'] ?? null)]);
        $this->assertSame(['name' => 'c', 'n' => 3], ['name' => $inserted['name'] ?? null, 'n' => Fetched::int($inserted['n'] ?? null)]);
        $this->assertNull($refused);
        $this->assertSame(['a', 'c'], array_keys($this->rows()));
    }

    /**
     * What is refused before anything is sent: an empty row or update, no columns to return, an
     * expression with bindings among them, and clauses set on the builder.
     */
    public function testWhatIsRefused(): void
    {
        $cases = [
            'Cannot insert empty data' => [
                fn (): int => $this->db->table('counters')->upsert([], ['n' => 1]),
                fn (): array => $this->db->table('counters')->upsertReturning([], ['n' => 1]),
                fn (): ?array => $this->db->table('counters')->insertWhenReturning([], '1 = 1'),
            ],
            'upsert() needs the columns to change on a duplicate ($update); to keep the existing row, use insertIgnore()' => [
                fn (): int => $this->db->table('counters')->upsert(['name' => 'a', 'n' => 1], []),
            ],
            'upsertReturning() needs the columns to change on a duplicate ($update); to keep the existing row, use insertIgnore()' => [
                fn (): array => $this->db->table('counters')->upsertReturning(['name' => 'a', 'n' => 1], []),
            ],
            "upsertReturning() needs the columns to return: names, '*', or expressions" => [
                fn (): array => $this->db->table('counters')->upsertReturning(['name' => 'a', 'n' => 1], ['n' => 2], []),
            ],
            "insertWhenReturning() needs the columns to return: names, '*', or expressions" => [
                fn (): ?array => $this->db->table('counters')->insertWhenReturning(['name' => 'a', 'n' => 1], '1 = 1', [], [], []),
            ],
            'upsertReturning() returns expressions without bindings only: their values would stand after the statement\'s own' => [
                fn (): array => $this->db->table('counters')->upsertReturning(['name' => 'a', 'n' => 1], ['n' => 2], [Database::raw('n + ?', [1])]),
            ],
            'insertWhenReturning() needs a condition' => [
                fn (): ?array => $this->db->table('counters')->insertWhenReturning(['name' => 'a', 'n' => 1], ' '),
            ],
            'insertWhenReturning() binds the condition values; write a raw expression into the condition instead' => [
                fn (): ?array => $this->db->table('counters')->insertWhenReturning(['name' => 'a', 'n' => 1], '? = 1', [Database::raw('1')]),
            ],
            'upsert() inserts or changes one row; where()/whereRaw(), joins, groupBy()/having(), orderBy(), limit()/offset(), distinct() and locks set on the builder are not part of the statement.' => [
                fn (): int => $this->db->table('counters')->where('name', 'a')->upsert(['name' => 'a', 'n' => 1], ['n' => 2]),
            ],
            'upsertReturning() inserts or changes one row; where()/whereRaw(), joins, groupBy()/having(), orderBy(), limit()/offset(), distinct() and locks set on the builder are not part of the statement.' => [
                fn (): array => $this->db->table('counters')->orderBy('id')->upsertReturning(['name' => 'a', 'n' => 1], ['n' => 2]),
            ],
            'insertWhenReturning() takes its condition as an argument; where()/whereRaw(), joins, groupBy()/having(), orderBy(), limit()/offset(), distinct() and locks set on the builder are not part of the statement.' => [
                fn (): ?array => $this->db->table('counters')->lockForUpdate()->insertWhenReturning(['name' => 'a', 'n' => 1], '1 = 1'),
            ],
        ];
        foreach ($cases as $message => $calls) {
            foreach ($calls as $call) {
                try {
                    $call();
                    $this->fail('Expected QueryException: ' . $message);
                } catch (QueryException $e) {
                    $this->assertSame('Insert failed', $e->getMessage());
                    $this->assertSame($message, $e->getDebugMessage());
                }
            }
        }
        $this->assertSame(['a' => ['code' => 'A', 'n' => 1, 'm' => 0]], $this->rows(), 'nothing was changed');
    }
}
