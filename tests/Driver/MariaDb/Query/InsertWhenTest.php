<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb\Query;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * The whole insertWhen() statement on MariaDB: the SELECT of the row reads FROM DUAL before its
 * WHERE (the contract test, tests/Contract/Query/InsertWhenTest, checks the statement up to the
 * SELECT and from the WHERE on).
 */
class InsertWhenTest extends ContractTestCase
{
    /** @var list<array{sql: string, params: array<int|string, mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->create('codes', ['id' => 'id', 'user_id' => 'int NOT NULL', 'code' => 'text NOT NULL', 'used_at' => 'text', 'created_at' => 'text']);
        $this->db->on('query', function (array $context): void {
            $this->queries[] = ['sql' => (string) $context['sql'], 'params' => (array) $context['params']];
        });
    }

    public function testTheRowIsSelectedFromDual(): void
    {
        $condition = 'NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL)';

        $this->assertSame(1, $this->db->insertWhen('codes', ['user_id' => 7, 'code' => 'first'], $condition, [7]));
        $this->assertSame(
            'INSERT INTO `codes` (`user_id`, `code`) SELECT ?, ? FROM DUAL WHERE (NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL))',
            $this->queries[0]['sql']
        );
        $this->assertSame([7, 'first', 7], $this->queries[0]['params'], 'row values first, then the condition bindings');
    }

    public function testRawValuesAndNowAreInlinedBeforeFromDual(): void
    {
        $inserted = $this->db->table('codes')->insertWhen(
            ['user_id' => 9, 'code' => Database::raw("CONCAT('raw', '-code')"), 'created_at' => $this->db->now()],
            '? > ?',
            [2, 1]
        );

        $this->assertSame(1, $inserted);
        $this->assertSame("INSERT INTO `codes` (`user_id`, `code`, `created_at`) SELECT ?, CONCAT('raw', '-code'), NOW() FROM DUAL WHERE (? > ?)", $this->queries[0]['sql']);
        $this->assertSame([9, 2, 1], $this->queries[0]['params']);
    }
}
