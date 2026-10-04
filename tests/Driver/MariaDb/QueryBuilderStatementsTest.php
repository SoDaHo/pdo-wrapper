<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Recorder;

/**
 * Builder statements on MariaDB, executed, with the SQL the 'query' hook was told: output names
 * that differ in case, UPDATE/DELETE ... ORDER BY ... LIMIT, locks with exists() and aggregates,
 * and how booleans are bound.
 */
class QueryBuilderStatementsTest extends ContractTestCase
{
    /**
     * MariaDB compares column names without case: two select() entries whose names differ only
     * in case are one output name in a counted derived table.
     */
    public function testAliasesThatDifferInCaseAreOneName(): void
    {
        $this->create('users', ['a' => 'int', 'b' => 'int']);
        $this->db->insert('users', ['a' => 1, 'b' => 2]);

        try {
            $this->db->table('users')->select(['a as X', 'b as x'])->distinct()->count();
            $this->fail('Expected QueryException: X and x are one name');
        } catch (QueryException $e) {
            $this->assertStringContainsString('names: "x" appears twice as an output name', (string) $e->getDebugMessage());
        }
    }

    /**
     * A grouped count keeps one select() entry per alias: a string entry's alias and a raw
     * expression's bare alias are the same name.
     */
    public function testAGroupedCountKeepsOneEntryPerAlias(): void
    {
        $this->create('users', ['a' => 'int', 'b' => 'int']);
        $rendered = new Recorder(static fn (array $context): string => (string) $context['sql']);
        $this->db->on('query', $rendered);

        $this->db->table('users')->select(['a as N', Database::raw('COUNT(*) AS n')])->groupBy('a')->count();

        $this->assertSame(['SELECT COUNT(*) as aggregate FROM (SELECT `a` as `N` FROM `users` GROUP BY `a`) as grouped'], $rendered->all());
    }

    /**
     * delete() and update() with limit() and orderBy(): `... ORDER BY ... LIMIT n`. orderBy()
     * without limit(), limit() without orderBy() and offset() throw before anything is sent;
     * select(), distinct() and a row lock cannot change which rows are hit and are ignored.
     */
    public function testDeleteAndUpdateWithLimit(): void
    {
        $this->create('users', ['id' => 'key', 'name' => 'text']);
        foreach (range(1, 9) as $id) {
            $this->db->insert('users', ['id' => $id, 'name' => 'n' . $id]);
        }
        $rendered = new Recorder(static fn (array $context): string => (string) $context['sql']);
        $this->db->on('query', $rendered);
        $this->db->on('error', $rendered);

        $this->assertSame(2, $this->db->table('users')->where('id', '>', 5)->orderBy('id')->limit(2)->delete());
        $this->assertSame([1, 2, 3, 4, 5, 8, 9], array_column($this->db->table('users')->orderBy('id')->get(), 'id'), 'the two lowest above 5');
        $this->assertSame(2, $this->db->table('users')->where('id', '>', 0)->orderBy('id', 'desc')->limit(2)->update(['name' => 'x']));
        $this->assertSame(['n1', 'n2', 'n3', 'n4', 'n5', 'x', 'x'], array_column($this->db->table('users')->orderBy('id')->get(), 'name'), 'the two highest');
        $this->assertSame([
            'DELETE FROM `users` WHERE `id` > ? ORDER BY `id` ASC LIMIT 2',
            'UPDATE `users` SET `name` = ? WHERE `id` > ? ORDER BY `id` DESC LIMIT 2',
        ], array_values(array_filter($rendered->all(), static fn (string $sql): bool => !str_starts_with($sql, 'SELECT'))));

        $rendered->clear();
        foreach ([
            'orderBy() alone' => fn (): int => $this->db->table('users')->where('id', 1)->orderBy('id')->delete(),
            'offset()' => fn (): int => $this->db->table('users')->where('id', 1)->orderBy('id')->limit(2)->offset(1)->delete(),
            'update() orderBy() alone' => fn (): int => $this->db->table('users')->where('id', 1)->orderBy('id')->update(['name' => 'x']),
            'update() offset()' => fn (): int => $this->db->table('users')->where('id', 1)->orderBy('id')->limit(2)->offset(1)->update(['name' => 'x']),
        ] as $name => $case) {
            try {
                $case();
                $this->fail("{$name}: expected QueryException");
            } catch (QueryException $e) {
                $this->assertStringContainsString('does not support', $e->getDebugMessage() ?? '', $name);
            }
        }
        // limit() without orderBy(): which rows would be up to the server
        foreach ([
            'delete' => fn (): int => $this->db->table('users')->where('id', '>', 5)->limit(1)->delete(),
            'update' => fn (): int => $this->db->table('users')->where('id', '>', 5)->limit(1)->update(['name' => 'x']),
        ] as $operation => $case) {
            try {
                $case();
                $this->fail("{$operation}: expected QueryException");
            } catch (QueryException $e) {
                $this->assertSame(ucfirst($operation) . ' failed', $e->getMessage());
                $this->assertSame(
                    "{$operation}() with limit() needs an orderBy(): without one, which rows it hits would be up to the server. Order by a unique key, or add one as tie-breaker.",
                    $e->getDebugMessage()
                );
            }
        }
        $this->assertSame([], $rendered->all(), 'none of them reached the database');

        $this->assertSame(0, $this->db->table('users')->select(['name'])->where('id', 999)->delete(), 'select() is ignored on delete()');
        $rendered->clear();
        $this->assertSame(0, $this->db->table('users')->where('id', 999)->lockForUpdate()->update(['name' => 'x']), 'a row lock is ignored on update()');
        $this->assertSame(0, $this->db->table('users')->where('id', 999)->distinct()->sharedLock()->delete(), 'distinct() and a row lock are ignored on delete()');
        $this->assertSame(['UPDATE `users` SET `name` = ? WHERE `id` = ?', 'DELETE FROM `users` WHERE `id` = ?'], $rendered->all());

        $rendered->clear();
        $this->db->table('users')->where('id', '>', 0)->orderBy('id', ' desc')->limit(1)->delete();
        $this->assertSame(['DELETE FROM `users` WHERE `id` > ? ORDER BY `id` DESC LIMIT 1'], $rendered->all(), 'surrounding whitespace and case are tolerated');
    }

    /**
     * exists() renders SELECT 1 ... LIMIT 1 and keeps the lock.
     */
    public function testExistsKeepsTheLock(): void
    {
        $this->create('users', ['id' => 'key']);
        $rendered = new Recorder(static fn (array $context): string => (string) $context['sql']);
        $this->db->on('query', $rendered);

        $this->db->beginTransaction();
        $this->assertFalse($this->db->table('users')->where('id', 7)->lockForUpdate()->exists());
        $this->db->rollback();

        $this->assertSame(['SELECT 1 FROM `users` WHERE `id` = ? LIMIT 1 FOR UPDATE'], $rendered->all());
    }

    /**
     * exists() must not slip past the lock prohibition through its count() fallback (having
     * without groupBy).
     */
    public function testExistsRejectsALockedHavingInsteadOfDroppingTheLock(): void
    {
        $this->create('users', ['id' => 'key']);
        $rendered = new Recorder(static fn (array $context): string => (string) $context['sql']);
        $this->db->on('query', $rendered);

        foreach (['lockForUpdate', 'sharedLock'] as $lock) {
            try {
                $this->db->table('users')->having(Database::raw('COUNT(*)'), '>', Database::raw('0'))->{$lock}()->exists();
                $this->fail("Expected QueryException for {$lock}()");
            } catch (QueryException $e) {
                $this->assertStringContainsString('cannot be combined', $e->getDebugMessage() ?? '');
            }
        }

        $this->assertSame([], $rendered->all(), 'nothing may run unlocked');
    }

    /**
     * Aggregates drop the lock (a locking read of an aggregate locks nothing a caller could name);
     * the builder keeps it for the rows it returns.
     */
    public function testAggregatesDropTheLock(): void
    {
        $this->create('users', ['id' => 'key']);
        $rendered = new Recorder(static fn (array $context): string => (string) $context['sql']);
        $this->db->on('query', $rendered);
        $builder = $this->db->table('users');

        $this->assertSame(0, $builder->lockForUpdate()->count());

        $this->assertSame(['SELECT COUNT(*) as aggregate FROM `users`'], $rendered->all());
        $this->assertSame('SELECT * FROM `users` FOR UPDATE', $builder->toSql()[0]);
    }

    /**
     * A boolean is bound as '1'/'0': PDO alone sends false as '', which strict mode rejects for a
     * numeric column. A string column keeps the '0'.
     */
    public function testBooleansAreBoundAsOneAndZero(): void
    {
        $this->create('flags', ['id' => 'id', 'active' => 'int', 'note' => 'text']);
        $this->db->insert('flags', ['active' => false, 'note' => false]);
        $this->db->insert('flags', ['active' => true, 'note' => true]);

        $this->assertSame(
            [['active' => 0, 'note' => '0'], ['active' => 1, 'note' => '1']],
            $this->db->query('SELECT active, note FROM flags ORDER BY id')->fetchAll()
        );
        $this->assertSame(1, $this->db->table('flags')->where('active', false)->where('note', false)->count());
        $this->assertSame(1, $this->db->table('flags')->where('active', true)->update(['active' => false, 'note' => false]));
        $this->assertSame(2, $this->db->table('flags')->where('active', false)->count());
        $this->assertSame(2, $this->db->query('SELECT COUNT(*) FROM flags WHERE note = :note', ['note' => false])->fetchColumn(), 'named parameters are converted too');

        $caller = new class () {
            public mixed $flag = false;
        };
        $this->assertSame(2, $this->db->query('SELECT COUNT(*) FROM flags WHERE active = ?', [&$caller->flag])->fetchColumn());
        $this->assertFalse($caller->flag, 'a referenced parameter is not rewritten in the caller');
    }
}
