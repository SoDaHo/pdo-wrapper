<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Closure;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Untyped;
use Throwable;

/**
 * updateMultiple() sends what its check read: a copy of each row, taken before the first UPDATE.
 * A value the caller holds a reference to - in a row, a whole row, among the bindings of a raw
 * expression -, changed by a 'query' listener after the first UPDATE into one the check would
 * have refused (an array; a number for a row), reaches no UPDATE: inside the caller's transaction
 * both rows are written and committed. Read again from the caller's rows instead, the second row
 * was refused (a TypeError for the number) after the first was written, and the caller's commit
 * kept part of the batch.
 */
class UpdateMultiplePlanTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create('batch', ['id' => 'key', 'name' => 'text']);
        $this->db->insert('batch', ['id' => 1, 'name' => 'one']);
        $this->db->insert('batch', ['id' => 2, 'name' => 'two']);
    }

    public function testAValueChangedThroughAReferenceReachesNoUpdate(): void
    {
        $name = 'changed-two';
        $rows = [['id' => 1, 'name' => 'changed-one'], ['id' => 2, 'name' => &$name]];

        $this->assertTheWholeBatch($rows, static function () use (&$name): void {
            $name = ['refused by the check'];
        });
    }

    public function testARowChangedThroughAReferenceReachesNoUpdate(): void
    {
        $second = ['id' => 2, 'name' => 'changed-two'];
        $rows = [['id' => 1, 'name' => 'changed-one'], &$second];

        $this->assertTheWholeBatch($rows, static function () use (&$second): void {
            $second = 123;
        });
    }

    public function testABindingChangedThroughAReferenceReachesNoUpdate(): void
    {
        $binding = 'changed-two';
        $rows = [['id' => 1, 'name' => 'changed-one'], ['id' => 2, 'name' => Database::raw('?', [&$binding])]];

        $this->assertTheWholeBatch($rows, static function () use (&$binding): void {
            $binding = ['refused by the check'];
        });
    }

    /**
     * Inside the caller's transaction, a 'query' listener runs $change once the first UPDATE ran;
     * whatever the batch throws is caught, as by a caller that goes on, and the transaction is
     * committed. Both rows are written, the second UPDATE bound what the check read.
     *
     * @param list<mixed> $rows
     * @param Closure(): void $change
     */
    private function assertTheWholeBatch(array $rows, Closure $change): void
    {
        $bound = [];
        $this->db->on('query', static function (array $data) use (&$bound, $change): void {
            if (is_string($data['sql']) && str_starts_with($data['sql'], 'UPDATE')) {
                $bound[] = $data['params'];
                if (count($bound) === 1) {
                    $change();
                }
            }
        });

        $this->db->beginTransaction();
        $failure = null;
        try {
            Untyped::call($this->db->updateMultiple(...), 'batch', $rows); // a row by reference is no array<string, mixed> for the analysis
        } catch (Throwable $e) {
            $failure = $e::class;
        }
        $this->db->commit();

        $this->assertSame(
            [null, ['changed-one', 'changed-two']],
            [$failure, array_column($this->db->table('batch')->orderBy('id')->get(), 'name')],
            'no failure, both rows written - not part of the batch'
        );
        $this->assertSame([['changed-one', 1], ['changed-two', 2]], $bound, 'the second UPDATE bound what the check read');
    }
}
