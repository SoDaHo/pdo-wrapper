<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\RawExpression;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Untyped;
use stdClass;

/**
 * updateMultiple() checks every row before it sends anything: a refused row - one without the key
 * column, a qualified key, a null key value, a value that cannot be bound, also the key value of a
 * row with nothing to set - leaves no earlier row written, inside a transaction of the caller
 * (nothing would undo it there) and without one (no transaction is begun at all).
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
     * @return array<string, array{list<mixed>, string, string}>
     */
    public static function refusedBatches(): array
    {
        return [
            'a qualified key in the second row' => [[['id' => 1, 'name' => 'changed'], ['id' => 2, 'batch.name' => 'changed']], 'id', 'need the plain names of columns'],
            'the key column missing in the second row' => [[['id' => 1, 'name' => 'changed'], ['name' => 'changed']], 'id', 'Missing key column "id" in row'],
            'a null key value in the second row' => [[['id' => 1, 'name' => 'changed'], ['id' => null, 'name' => 'changed']], 'id', 'NULL value for column "id"'],
            'a numeric key in the second row' => [[['id' => 1, 'name' => 'changed'], ['id' => 2, 0 => 'changed']], 'id', 'need column names as keys'],
            'a qualified key column, no data to set' => [[['batch.id' => 1]], 'batch.id', 'The key column of updateMultiple() need the plain names'],
            'an array to set in the second row' => [[['id' => 1, 'name' => 'changed'], ['id' => 2, 'name' => ['changed']]], 'id', 'Row 2 of updateMultiple(): Cannot bind a value of type array (parameter #1)'],
            'INF to set in the second row' => [[['id' => 1, 'name' => 'changed'], ['id' => 2, 'name' => INF]], 'id', 'Row 2 of updateMultiple(): Cannot bind INF (parameter #1)'],
            'an object as the key value of the second row' => [[['id' => 1, 'name' => 'changed'], ['id' => new stdClass(), 'name' => 'changed']], 'id', 'Row 2 of updateMultiple(): Cannot bind a value of type stdClass (parameter #2)'],
            'an array among the bindings of a raw expression to set' => [[['id' => 1, 'name' => 'changed'], ['id' => 2, 'name' => Database::raw('CONCAT(?, ?)', ['changed', ['x']])]], 'id', 'Row 2 of updateMultiple(): Cannot bind a value of type array (parameter #2)'],
            'an unbindable key value in a row with nothing to set' => [[['id' => 1, 'name' => 'changed'], ['id' => []]], 'id', 'Row 2 of updateMultiple(): Cannot bind a value of type array (parameter #1)'],
            'a null key value in a row with nothing to set' => [[['id' => 1, 'name' => 'changed'], ['id' => null]], 'id', 'NULL value for column "id"'],
            'a null key value alone' => [[['id' => null]], 'id', 'NULL value for column "id"'],
            'no rows, a qualified key column' => [[], 'batch.id', 'The key column of updateMultiple() need the plain names'],
            'a second row that is no array' => [[['id' => 1, 'name' => 'changed'], 123], 'id', 'Row 2 of updateMultiple() is no array but int: pass column => value pairs, the key column among them. Nothing was sent.'],
        ];
    }

    /**
     * @param list<mixed> $rows
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
     * A row with nothing to set but a good key value is still skipped: nothing to update, no
     * statement for it.
     */
    public function testARowWithNothingToSetButAGoodKeyIsSkipped(): void
    {
        $this->assertSame(1, $this->db->updateMultiple('batch', [['id' => 1], ['id' => 2, 'name' => 'changed']]));
        $this->assertSame(['one', 'changed'], array_column($this->db->table('batch')->orderBy('id')->get(), 'name'));
        $this->assertCount(1, array_filter($this->sent, static fn (string $sql): bool => str_starts_with($sql, 'UPDATE')), 'one UPDATE');
    }

    /**
     * The check writes no SQL: a raw expression - of a class of its own whose __toString() counts
     * how often it is rendered - is rendered once, by the UPDATE that sends it, as a key value and
     * as a value to set. Rendered by the check as well, the key would have read 2 and hit the other row.
     */
    public function testTheCheckRendersNoRawExpression(): void
    {
        $key = new class ('') extends RawExpression {
            public int $renders = 0;

            public function __toString(): string
            {
                return (string) ++$this->renders;
            }
        };
        $name = new class ('') extends RawExpression {
            public int $renders = 0;

            public function __toString(): string
            {
                return sprintf("'rendered %d'", ++$this->renders);
            }
        };

        $this->assertSame(1, $this->db->updateMultiple('batch', [['id' => $key, 'name' => $name]]));

        $this->assertSame([1, 1], [$key->renders, $name->renders], 'each rendered once');
        $this->assertContains("UPDATE `batch` SET `name` = 'rendered 1' WHERE `id` = 1", $this->sent);
        $this->assertSame(['rendered 1', 'two'], array_column($this->db->table('batch')->orderBy('id')->get(), 'name'));
    }

    /**
     * @param list<mixed> $rows
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
