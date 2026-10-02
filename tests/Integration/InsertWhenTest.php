<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * insertWhen(): insert a row only when a condition holds, in one statement.
 */
class InsertWhenTest extends TestCase
{
    private DatabaseInterface $db;

    /** @var list<array{sql: string, params: array<int|string, mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        $this->db = Database::sqlite(':memory:');
        $this->db->execute('CREATE TABLE codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code TEXT NOT NULL, used_at TEXT NULL, created_at TEXT NULL)');
        $this->db->on('query', function (array $context): void {
            $this->queries[] = ['sql' => (string) $context['sql'], 'params' => (array) $context['params']];
        });
        $this->db->on('error', function (array $context): void {
            $this->queries[] = ['sql' => 'ERROR ' . (string) $context['sql'], 'params' => (array) $context['params']];
        });
    }

    public function testNullBoolAndFloatValuesAreBoundAsInInsert(): void
    {
        $this->db->execute('ALTER TABLE codes ADD COLUMN active INTEGER');
        $this->db->execute('ALTER TABLE codes ADD COLUMN amount REAL');

        $this->assertSame(1, $this->db->insertWhen('codes', ['user_id' => 1, 'code' => 'a', 'used_at' => null, 'active' => false, 'amount' => 1.5], '1 = 1'));
        $this->assertSame(1, $this->db->insertWhen('codes', ['user_id' => 2, 'code' => 'b', 'used_at' => null, 'active' => true, 'amount' => 2.25], '1 = 1'));

        $rows = $this->db->query('SELECT typeof(used_at), typeof(active), active, typeof(amount), amount FROM codes ORDER BY id')->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([['null', 'integer', 0, 'real', 1.5], ['null', 'integer', 1, 'real', 2.25]], $rows, 'SQLite binds bools as 0/1, null as NULL, floats as text with REAL affinity');
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
        $this->assertSame(
            'INSERT INTO `codes` (`user_id`, `code`) SELECT ?, ? WHERE (NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL))',
            $this->queries[0]['sql']
        );
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
        $inserted = $this->db->table('codes')->insertWhen(
            ['user_id' => 9, 'code' => Database::raw("'raw' || '-code'"), 'created_at' => $this->db->now()],
            '? > ?',
            [2, 1]
        );

        $this->assertSame(1, $inserted);
        $this->assertSame("INSERT INTO `codes` (`user_id`, `code`, `created_at`) SELECT ?, 'raw' || '-code', datetime('now', 'localtime') WHERE (? > ?)", $this->queries[0]['sql']);
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
