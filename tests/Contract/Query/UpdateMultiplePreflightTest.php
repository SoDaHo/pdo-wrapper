<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Untyped;

/**
 * updateMultiple() checks every row before it sends anything: a refused row - one without the key
 * column, a qualified key, a null key value - leaves no earlier row written, inside a transaction
 * of the caller (nothing would undo it there) and without one (no transaction is begun at all).
 */
class UpdateMultiplePreflightTest extends ContractTestCase
{
    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->create('batch', ['id' => 'key', 'name' => 'text']);
        $this->db->insert('batch', ['id' => 1, 'name' => 'one']);
        $this->db->insert('batch', ['id' => 2, 'name' => 'two']);
        $this->sent = [];
        $this->db->on('query.before', function (array $data): void {
            $this->sent[] = is_string($data['sql']) ? $data['sql'] : '';
        });
        $this->db->on('transaction.begin', function (): void {
            $this->sent[] = 'BEGIN';
        });
    }

    /**
     * @return array<string, array{list<array<array-key, mixed>>, string, string}>
     */
    public static function refusedBatches(): array
    {
        return [
            'a qualified key in the second row' => [[['id' => 1, 'name' => 'changed'], ['id' => 2, 'batch.name' => 'changed']], 'id', 'need the plain names of columns'],
            'the key column missing in the second row' => [[['id' => 1, 'name' => 'changed'], ['name' => 'changed']], 'id', 'Missing key column "id" in row'],
            'a null key value in the second row' => [[['id' => 1, 'name' => 'changed'], ['id' => null, 'name' => 'changed']], 'id', 'NULL value for column "id"'],
            'a numeric key in the second row' => [[['id' => 1, 'name' => 'changed'], ['id' => 2, 0 => 'changed']], 'id', 'need column names as keys'],
            'a qualified key column, no data to set' => [[['batch.id' => 1]], 'batch.id', 'The key column of updateMultiple() need the plain names'],
        ];
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedBatches')]
    public function testARefusedRowLeavesNothingWrittenInsideTheCallersTransaction(array $rows, string $keyColumn, string $why): void
    {
        $this->db->beginTransaction();
        $this->sent = [];
        try {
            Untyped::call($this->db->updateMultiple(...), 'batch', $rows, $keyColumn); // a numeric key is no array<string, mixed>
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString($why, (string) $e->getDebugMessage());
        }
        $this->assertSame([], $this->sent, 'nothing was sent');
        $this->db->commit(); // a caller that catches the refusal and goes on

        $this->assertSame(['one', 'two'], array_column($this->db->table('batch')->orderBy('id')->get(), 'name'));
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedBatches')]
    public function testARefusedRowBeginsNoTransactionOfItsOwn(array $rows, string $keyColumn, string $why): void
    {
        try {
            Untyped::call($this->db->updateMultiple(...), 'batch', $rows, $keyColumn); // a numeric key is no array<string, mixed>
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString($why, (string) $e->getDebugMessage());
        }
        $this->assertSame([], $this->sent, 'no BEGIN, no UPDATE');
        $this->assertNull($this->db->currentTransaction());
        $this->assertSame(['one', 'two'], array_column($this->db->table('batch')->orderBy('id')->get(), 'name'));
    }
}
