<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Recorder;

/**
 * Builder statements on MariaDB, executed, with the SQL the 'query' hook was told: output names
 * that differ in case, UPDATE/DELETE ... ORDER BY ... LIMIT, increment()'s cast, orderBy() with a
 * string, locks with exists() and aggregates, and how booleans are bound.
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

        foreach ([[['a as X', 'b as x'], 'x'], [['a', 'b as A'], 'a'], [['A', 'users.a'], 'a']] as [$columns, $name]) {
            try {
                $this->db->table('users')->select($columns)->distinct()->count();
                $this->fail('Expected QueryException: one name - ' . implode(', ', $columns));
            } catch (QueryException $e) {
                $this->assertStringContainsString(sprintf('names: "%s" appears twice as an output name', $name), (string) $e->getDebugMessage());
            }
        }
    }

    /**
     * A grouped count keeps one select() entry per alias: a string entry's alias and a raw
     * expression's alias are the same name - bare or quoted, MariaDB compares them without case
     * (two of them would end in error 1060, a duplicate column name in the derived table).
     */
    public function testAGroupedCountKeepsOneEntryPerAlias(): void
    {
        $this->create('users', ['a' => 'int', 'b' => 'int']);
        $this->db->insert('users', ['a' => 1, 'b' => 2]);
        $this->db->insert('users', ['a' => 2, 'b' => 2]);
        $rendered = new Recorder(static fn (array $context): string => (string) $context['sql']);
        $this->db->on('query', $rendered);

        foreach (['COUNT(*) AS n', 'COUNT(*) AS `n`', 'COUNT(*) as "N"', 'COUNT(*) AS `N`'] as $raw) {
            $this->assertSame(2, $this->db->table('users')->select(['a as N', Database::raw($raw)])->groupBy('a')->count(), $raw);
        }

        $this->assertSame(array_fill(0, 4, 'SELECT COUNT(*) as aggregate FROM (SELECT `a` as `N` FROM `users` GROUP BY `a`) as grouped'), $rendered->all());

        // A quoted alias is an alias: the entry stays for having() to refer to
        foreach (['COUNT(*) AS `n`', 'COUNT(*) AS "n"'] as $raw) {
            $this->assertSame(2, $this->db->table('users')->select([Database::raw($raw)])->groupBy('a')->having('n', '>', 0)->count(), $raw);
        }
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
     * increment() and decrement(): the column first, then $extra, in the order given; the step
     * is bound where it stands, the WHERE values after the SET values. The step is cast - an int
     * to SIGNED, a float to DECIMAL(65,30) -: bound as text it would make MariaDB add in DOUBLE.
     */
    public function testIncrementSetsTheColumnFirstThenTheExtraValues(): void
    {
        $this->create('counters', ['id' => 'key', 'attempts' => 'int NOT NULL', 'note' => 'text', 'seen' => 'int']);
        $this->db->insert('counters', ['id' => 1, 'attempts' => 0, 'note' => 'a', 'seen' => 0]);
        $rendered = new Recorder(static fn (array $context): array => [(string) $context['sql'], $context['params']]);
        $this->db->on('query', $rendered);

        $this->db->table('counters')->where('id', 1)->increment('attempts', 2, ['note' => 'b', 'seen' => 1]);
        $this->db->table('counters')->where('id', 1)->decrement('attempts', 1.5);

        $this->assertSame([
            ['UPDATE `counters` SET `attempts` = `attempts` + CAST(? AS SIGNED), `note` = ?, `seen` = ? WHERE `id` = ?', [2, 'b', 1, 1]],
            ['UPDATE `counters` SET `attempts` = `attempts` - CAST(? AS DECIMAL(65,30)) WHERE `id` = ?', [1.5, 1]],
        ], $rendered->all());
    }

    /**
     * A float the DECIMAL(65,30) cast would change is refused before anything is sent: INF, NAN,
     * magnitudes from 1e35 (beyond the integer digits) and below 1e-13 (the digits PHP writes
     * could reach past the 30th place; a tiny step on a DOUBLE column would silently be lost).
     * 0, negative steps and the bounds themselves are added.
     */
    public function testAFloatStepTheCastCannotHoldIsRefused(): void
    {
        $this->create('counters', ['id' => 'key', 'score' => 'double NOT NULL']);
        $this->db->insert('counters', ['id' => 1, 'score' => 0.5]);
        $rendered = new Recorder(static fn (array $context): string => (string) $context['sql']);
        $this->db->on('query', $rendered);

        foreach ([INF, -INF, NAN, 9.99e-14, -1e-14, 1e35, -1e35] as $step) {
            foreach (['increment', 'decrement'] as $method) {
                try {
                    $this->db->table('counters')->where('id', 1)->{$method}('score', $step);
                    $this->fail(sprintf('Expected QueryException: %s(%s)', $method, var_export($step, true)));
                } catch (QueryException $e) {
                    $this->assertSame('Update failed', $e->getMessage());
                    $this->assertSame(sprintf('%s() adds a float as DECIMAL(65,30), which would change %s: only 0 and magnitudes from 1e-13 below 1e35 keep the digits PHP writes for a float. Use update() with Database::raw() for it.', $method, var_export($step, true)), $e->getDebugMessage());
                }
            }
        }
        $this->assertSame([], $rendered->all(), 'nothing was sent');

        $score = fn (): mixed => $this->db->findOne('counters', ['id' => 1])['score'] ?? null;
        foreach ([0.0, -0.0] as $step) {
            $this->assertSame(0, $this->db->table('counters')->where('id', 1)->increment('score', $step), 'nothing changed');
        }
        $this->db->table('counters')->where('id', 1)->increment('score', 1e-13);
        $this->assertSame(0.5000000000001, $score());
        $this->db->table('counters')->where('id', 1)->increment('score', -1e-13);
        $this->assertSame(0.5, $score(), 'a negative step');
        $this->db->table('counters')->where('id', 1)->increment('score', 9.9e34);
        $this->assertSame(9.9e34, $score());
    }

    /**
     * orderBy() takes a string as one column name: "title as x" is not split into an alias.
     */
    public function testOrderByTakesAStringAsOneColumnName(): void
    {
        $this->create('docs', ['id' => 'key', 'title as x' => 'int']);
        $this->db->insert('docs', ['id' => 1, 'title as x' => 2]);
        $this->db->insert('docs', ['id' => 2, 'title as x' => 1]);
        $builder = $this->db->table('docs')->orderBy('title as x');

        $this->assertSame('SELECT * FROM `docs` ORDER BY `title as x` ASC', $builder->toSql()[0]);
        $this->assertSame([2, 1], array_column($builder->get(), 'id'));
        $this->assertSame('SELECT `title` as `x` FROM `docs`', $this->db->table('docs')->select(['title as x'])->toSql()[0], 'select() still reads an alias');
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
     * Aggregates keep the lock: `SELECT COUNT(*) ... FOR UPDATE` locks what it reads. The
     * combinations a select refuses (distinct(), groupBy(), having()) are refused here too,
     * before anything is sent.
     */
    public function testAggregatesKeepTheLock(): void
    {
        $this->create('users', ['id' => 'key', 'n' => 'int']);
        $rendered = new Recorder(static fn (array $context): string => (string) $context['sql']);
        $this->db->on('query', $rendered);

        $this->db->beginTransaction();
        $this->assertSame(0, $this->db->table('users')->where('n', 7)->lockForUpdate()->count());
        $this->assertNull($this->db->table('users')->sharedLock()->max('n'));
        $this->assertNull($this->db->table('users')->lockForUpdate()->sum('n'));
        $this->db->rollback();

        $this->assertSame([
            'SELECT COUNT(*) as aggregate FROM `users` WHERE `n` = ? FOR UPDATE',
            'SELECT MAX(`n`) as aggregate FROM `users` LOCK IN SHARE MODE',
            'SELECT SUM(`n`) as aggregate FROM `users` FOR UPDATE',
        ], $rendered->all());

        $rendered->clear();
        foreach ([
            'distinct() with a column' => fn (): int => $this->db->table('users')->distinct()->lockForUpdate()->count('n'),
            'distinct()' => fn (): int => $this->db->table('users')->distinct()->lockForUpdate()->count(),
            'groupBy()' => fn (): int => $this->db->table('users')->groupBy('n')->lockForUpdate()->count(),
            'having()' => fn (): mixed => $this->db->table('users')->having(Database::raw('COUNT(*)'), '>', Database::raw('0'))->sharedLock()->max('n'),
        ] as $name => $case) {
            try {
                $case();
                $this->fail("Expected QueryException: {$name}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('cannot be combined with distinct(), groupBy() or having()', (string) $e->getDebugMessage(), $name);
            }
        }
        $this->assertSame([], $rendered->all(), 'nothing may run unlocked');
    }

    /**
     * What the kept lock is for: a count under lockForUpdate() holds off another transaction's
     * insert into what it counted until the transaction ends (REPEATABLE READ locks the gaps too),
     * so "count, then insert" is not overtaken.
     */
    public function testALockedCountHoldsOffAnotherInsert(): void
    {
        $this->create('sessions', ['id' => 'key', 'user_id' => 'int']);
        $this->db->insert('sessions', ['id' => 1, 'user_id' => 7]);
        $other = $this->connect();
        $other->execute('SET SESSION innodb_lock_wait_timeout = 1');

        $this->db->beginTransaction();
        $this->assertSame(1, $this->db->table('sessions')->where('user_id', 7)->lockForUpdate()->count());
        try {
            $other->insert('sessions', ['id' => 2, 'user_id' => 7]);
            $this->fail('Expected the insert to wait for the lock');
        } catch (QueryException $e) {
            $previous = $e->getPrevious();
            $this->assertInstanceOf(\PDOException::class, $previous);
            $this->assertSame(1205, $previous->errorInfo[1] ?? null, 'lock wait timeout');
        }
        $this->db->rollback();

        $other->insert('sessions', ['id' => 2, 'user_id' => 7]);
        $this->assertSame(2, $other->table('sessions')->count(), 'free after the end');
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
