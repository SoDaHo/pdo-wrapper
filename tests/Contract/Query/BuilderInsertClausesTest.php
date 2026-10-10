<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Closure;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * The builder's insert() takes no clause: a where(), join, groupBy()/having(), orderBy(),
 * limit()/offset(), distinct() or row lock is not part of an INSERT, and was dropped without a
 * word - the row went in whatever the condition said. It throws now, before anything is sent, as
 * insertWhen(), upsert() and insertIgnore() do. select() is no clause of an insert and stays.
 */
class BuilderInsertClausesTest extends ContractTestCase
{
    public function testABuilderClauseIsRefusedBeforeAnythingIsSent(): void
    {
        $this->create('invites', ['id' => 'key', 'name' => 'text']);
        $sent = [];
        $this->db->on('query.before', static function (array $data) use (&$sent): void {
            $sent[] = $data['sql'];
        });

        /** @var array<string, Closure(QueryBuilder): QueryBuilder> $clauses */
        $clauses = [
            'where' => static fn (QueryBuilder $q): QueryBuilder => $q->where('id', 1),
            'whereRaw' => static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('1 = 0'),
            'join' => static fn (QueryBuilder $q): QueryBuilder => $q->join('other', 'other.id', '=', 'invites.id'),
            'groupBy' => static fn (QueryBuilder $q): QueryBuilder => $q->groupBy('name'),
            'having' => static fn (QueryBuilder $q): QueryBuilder => $q->having('name', '=', 'x'),
            'orderBy' => static fn (QueryBuilder $q): QueryBuilder => $q->orderBy('id'),
            'limit' => static fn (QueryBuilder $q): QueryBuilder => $q->limit(1),
            'offset' => static fn (QueryBuilder $q): QueryBuilder => $q->offset(1),
            'distinct' => static fn (QueryBuilder $q): QueryBuilder => $q->distinct(),
            'lockForUpdate' => static fn (QueryBuilder $q): QueryBuilder => $q->lockForUpdate(),
        ];
        foreach ($clauses as $name => $clause) {
            try {
                $clause($this->db->table('invites'))->insert(['id' => 1, 'name' => $name]);
                $this->fail("Expected QueryException: {$name}");
            } catch (QueryException $e) {
                $this->assertSame('Insert failed', $e->getMessage(), $name);
                $this->assertStringStartsWith('insert() inserts one row, unconditionally', (string) $e->getDebugMessage(), $name);
            }
        }

        $this->assertSame([], $sent, 'nothing was sent');
        $this->assertSame(0, $this->db->table('invites')->count());
        $this->db->table('invites')->select(['id'])->insert(['id' => 1, 'name' => 'select() is no clause of an insert']);
        $this->assertSame(1, $this->db->table('invites')->count());
    }
}
