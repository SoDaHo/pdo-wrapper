<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * insertWhen(): insert a row only when a condition holds, in one statement.
 *
 * The statement is `INSERT INTO ... SELECT <row> [FROM <dummy table>] WHERE (<condition>)`:
 * how the names are quoted and whether the SELECT names a dummy table is the driver's matter, so
 * the SQL is checked from its SELECT up to that point and from the WHERE on (the whole statement:
 * tests/Driver).
 */
class InsertWhenTest extends ContractTestCase
{
    /** @var list<array{sql: string, params: array<int|string, mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        // active and amount are there for testNullBoolAndFloatValuesAreBoundAsInInsert(), which
        // added them with ALTER TABLE: a test writes no DDL
        $this->create('codes', ['id' => 'id', 'user_id' => 'int NOT NULL', 'code' => 'text NOT NULL', 'used_at' => 'text', 'created_at' => 'text', 'active' => 'int', 'amount' => 'double']);
        $this->db->on('query', function (array $context): void {
            $this->queries[] = ['sql' => (string) $context['sql'], 'params' => (array) $context['params']];
        });
        $this->db->on('error', function (array $context): void {
            $this->queries[] = ['sql' => 'ERROR ' . (string) $context['sql'], 'params' => (array) $context['params']];
        });
    }

    /**
     * What is stored is read back in the type of its column: NULL as null (not '' or 'null'), a
     * boolean as the integer 0 or 1 (false is not sent as '', which a strict numeric column
     * rejects), a float as that float.
     */
    public function testNullBoolAndFloatValuesAreBoundAsInInsert(): void
    {
        $this->assertSame(1, $this->db->insertWhen('codes', ['user_id' => 1, 'code' => 'a', 'used_at' => null, 'active' => false, 'amount' => 1.5], '1 = 1'));
        $this->assertSame(1, $this->db->insertWhen('codes', ['user_id' => 2, 'code' => 'b', 'used_at' => null, 'active' => true, 'amount' => 2.25], '1 = 1'));

        $rows = $this->db->query('SELECT used_at, active, amount FROM codes ORDER BY id')->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([[null, 0, 1.5], [null, 1, 2.25]], $rows, 'bools as 0/1, null as NULL, floats as numbers');
    }

    public function testBuilderRefusesClausesThatWouldNotBePartOfTheStatement(): void
    {
        $row = ['user_id' => 1, 'code' => 'x'];
        foreach ([
            'where()' => fn () => $this->db->table('codes')->where('user_id', 1)->insertWhen($row, '1 = 1'),
            'whereRaw()' => fn () => $this->db->table('codes')->whereRaw('1 = 1')->insertWhen($row, '1 = 1'),
            'join()' => fn () => $this->db->table('codes')->join('users', 'users.id', '=', 'codes.user_id')->insertWhen($row, '1 = 1'),
            'groupBy()' => fn () => $this->db->table('codes')->groupBy('user_id')->insertWhen($row, '1 = 1'),
            'having()' => fn () => $this->db->table('codes')->having(Database::raw('COUNT(*)'), '>', 1)->insertWhen($row, '1 = 1'),
            'orderBy()' => fn () => $this->db->table('codes')->orderBy('id')->insertWhen($row, '1 = 1'),
            'limit()' => fn () => $this->db->table('codes')->limit(1)->insertWhen($row, '1 = 1'),
            'offset()' => fn () => $this->db->table('codes')->offset(1)->insertWhen($row, '1 = 1'),
            'distinct()' => fn () => $this->db->table('codes')->distinct()->insertWhen($row, '1 = 1'),
            'lock' => fn () => $this->db->table('codes')->lockForUpdate()->insertWhen($row, '1 = 1'),
        ] as $name => $case) {
            try {
                $case();
                $this->fail("{$name}: expected QueryException");
            } catch (QueryException $e) {
                $this->assertStringContainsString('takes its condition as an argument', $e->getDebugMessage() ?? '', $name);
            }
        }
        $this->assertSame([], $this->queries, 'nothing reached the database');
        $this->assertSame(1, $this->db->table('codes')->select(['id'])->insertWhen($row, '1 = 1'), 'select() is harmless and ignored');
    }

    public function testInsertsOnlyWhileTheConditionHolds(): void
    {
        $condition = 'NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL)';

        $this->assertSame(1, $this->db->insertWhen('codes', ['user_id' => 7, 'code' => 'first'], $condition, [7]));
        $this->assertSame(0, $this->db->insertWhen('codes', ['user_id' => 7, 'code' => 'second'], $condition, [7]), 'an open code exists');
        $this->assertSame(1, $this->db->insertWhen('codes', ['user_id' => 8, 'code' => 'other'], $condition, [8]));
        $this->db->update('codes', ['used_at' => '2026-10-01 04:00:00'], ['user_id' => 7]);
        $this->assertSame(1, $this->db->insertWhen('codes', ['user_id' => 7, 'code' => 'third'], $condition, [7]), 'the open code was used');

        $this->assertSame(['first', 'other', 'third'], array_column($this->db->table('codes')->orderBy('id')->get(), 'code'));
        $this->assertStringStartsWith('INSERT INTO ', $this->queries[0]['sql']);
        $this->assertStringContainsString(') SELECT ?, ? ', $this->queries[0]['sql']);
        $this->assertStringEndsWith(' WHERE (NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL))', $this->queries[0]['sql']);
        $this->assertSame([7, 'first', 7], $this->queries[0]['params'], 'row values first, then the condition bindings');
    }

    public function testBindingOrderIsRowValuesThenCondition(): void
    {
        // The condition's placeholder must receive the condition binding (3), not a row value (1):
        // with the order swapped "? = 3" would see the user_id 1 and fail, "? = 1" would see 3 and pass
        $this->assertSame(1, $this->db->insertWhen('codes', ['user_id' => 1, 'code' => 'x'], '? = 3', [3]));
        $this->assertSame(0, $this->db->insertWhen('codes', ['user_id' => 1, 'code' => 'x'], '? = 1', [3]));
        $this->assertSame([1, 'x', 3], $this->queries[0]['params']);
        $this->assertSame(1, $this->db->table('codes')->where('user_id', 1)->count());
    }

    public function testRawValuesInTheRowAreInlinedAndTheBuilderPassesThrough(): void
    {
        // CONCAT(), not the standard "||": an engine may read "||" as a logical OR (see RawValueTest)
        $inserted = $this->db->table('codes')->insertWhen(
            ['user_id' => 9, 'code' => Database::raw("CONCAT('raw', '-code')"), 'created_at' => $this->db->now()],
            '? > ?',
            [2, 1]
        );

        $this->assertSame(1, $inserted);
        $this->assertStringStartsWith('INSERT INTO ', $this->queries[0]['sql']);
        $this->assertStringContainsString(") SELECT ?, CONCAT('raw', '-code'), " . $this->db->now() . ' ', $this->queries[0]['sql'], "the driver's now() inlined as it is");
        $this->assertStringEndsWith(' WHERE (? > ?)', $this->queries[0]['sql']);
        $this->assertSame([9, 2, 1], $this->queries[0]['params']);
        $row = $this->db->table('codes')->where('user_id', 9)->first();
        $this->assertSame('raw-code', $row['code'] ?? null);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($row['created_at'] ?? ''));
    }

    public function testInvalidInputIsRejectedBeforeAnyQuery(): void
    {
        $cases = [
            'empty row' => fn () => $this->db->insertWhen('codes', [], '1 = 1'),
            'empty condition' => fn () => $this->db->insertWhen('codes', ['user_id' => 1, 'code' => 'x'], '   '),
            'raw binding' => fn () => $this->db->insertWhen('codes', ['user_id' => 1, 'code' => 'x'], '? = 1', [Database::raw('1')]),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                $this->fail("{$name}: expected QueryException");
            } catch (QueryException $e) {
                $this->assertSame('Insert failed', $e->getMessage(), $name);
            }
        }
        $this->assertSame([], $this->queries, 'nothing reached the database');
        $this->assertSame(0, $this->db->table('codes')->count());
    }
}
