<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Exception\QueryException;

#[Group('mysql')]
class MySqlQueryBuilderTest extends TestCase
{
    private MySqlDriver $db;

    protected function setUp(): void
    {
        $this->db = Database::mysql([
            'host' => $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
            'database' => $_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['MYSQL_USERNAME'] ?? 'root',
            'password' => $_ENV['MYSQL_PASSWORD'] ?? 'root',
        ]);

        $this->db->execute('DROP TABLE IF EXISTS qb_test');
        $this->db->execute('CREATE TABLE qb_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), age INT)');

        $this->db->insert('qb_test', ['name' => 'Max', 'age' => 25]);
        $this->db->insert('qb_test', ['name' => 'Anna', 'age' => 30]);
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qb_test');
    }

    public function testQueryBuilderWithMySqlQuoting(): void
    {
        $users = $this->db->table('qb_test')->where('name', 'Max')->get();

        $this->assertCount(1, $users);
        $this->assertSame('Max', $users[0]['name']);
    }

    public function testToSqlUsesMySqlBackticks(): void
    {
        [$sql] = $this->db->table('qb_test')->where('id', 1)->toSql();

        $this->assertStringContainsString('`qb_test`', $sql);
        $this->assertStringContainsString('`id`', $sql);
    }

    public function testQueryBuilderJoinWithMySql(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
        $this->db->execute('CREATE TABLE qb_profiles (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, bio VARCHAR(255))');
        $this->db->insert('qb_profiles', ['user_id' => 1, 'bio' => 'Developer']);

        $results = $this->db->table('qb_test')
            ->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')
            ->get();

        $this->assertCount(2, $results);

        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
    }

    public function testIsAndIsNotExecuteAsNullSafeEquality(): void
    {
        $this->db->execute('ALTER TABLE qb_test ADD COLUMN nick VARCHAR(50) NULL');
        $this->db->update('qb_test', ['nick' => 'maxi'], ['name' => 'Max']);

        $this->assertSame(['Max'], array_column($this->db->table('qb_test')->where('nick', 'IS', 'maxi')->get(), 'name'));
        $this->assertSame(['Anna'], array_column($this->db->table('qb_test')->where('nick', 'IS NOT', 'maxi')->get(), 'name'));
    }

    public function testOffsetWithoutLimitExecutes(): void
    {
        $rows = $this->db->table('qb_test')->orderBy('id')->offset(1)->get();

        $this->assertSame(['Anna'], array_column($rows, 'name'));
    }

    public function testWhereRawExecutes(): void
    {
        $this->assertSame(['Max'], array_column($this->db->table('qb_test')->where('age', '<', 40)->whereRaw('LOWER(name) = ? OR age > ?', ['max', 100])->get(), 'name'));
        $this->assertSame(1, $this->db->table('qb_test')->whereRaw('age BETWEEN ? AND ?', [26, 35])->count());
    }

    public function testDeleteWithOrderByAndLimitDeletesTheOldestRows(): void
    {
        foreach (['Tom', 'Eva', 'Kim'] as $name) {
            $this->db->insert('qb_test', ['name' => $name, 'age' => 20]);
        }
        $sql = [];
        $this->db->on('query', static function (array $context) use (&$sql): void {
            $sql[] = (string) $context['sql'];
        });

        $this->assertSame(2, $this->db->table('qb_test')->where('id', '>', 0)->orderBy('id')->limit(2)->delete());
        $this->assertSame('DELETE FROM `qb_test` WHERE `id` > ? ORDER BY `id` ASC LIMIT 2', $sql[0]);
        $this->assertSame([3, 4, 5], array_map('intval', array_column($this->db->table('qb_test')->orderBy('id')->get(), 'id')), 'the two oldest rows are gone');
        $this->assertSame(1, $this->db->table('qb_test')->where('age', 20)->orderBy('id', 'DESC')->limit(1)->delete(), 'the newest of the matching rows');
        $this->assertSame([3, 4], array_map('intval', array_column($this->db->table('qb_test')->orderBy('id')->get(), 'id')));
        $this->assertSame(2, $this->db->table('qb_test')->where('id', '>', 0)->limit(10)->delete(), 'limit() without orderBy(): any order');
    }

    public function testInsertWhenExecutesWithFromDual(): void
    {
        $sql = [];
        $this->db->on('query', static function (array $context) use (&$sql): void {
            $sql[] = (string) $context['sql'];
        });
        $condition = 'NOT EXISTS (SELECT 1 FROM qb_test WHERE name = ?)';

        $this->assertSame(1, $this->db->insertWhen('qb_test', ['name' => 'Tom', 'age' => 50], $condition, ['Tom']));
        $this->assertSame(0, $this->db->insertWhen('qb_test', ['name' => 'Tom', 'age' => 51], $condition, ['Tom']));
        $this->assertSame(1, $this->db->table('qb_test')->insertWhen(['name' => 'Eva', 'age' => Database::raw('40 + 2')], '? < ?', [1, 2]));
        $this->assertSame('INSERT INTO `qb_test` (`name`, `age`) SELECT ?, ? FROM DUAL WHERE (NOT EXISTS (SELECT 1 FROM qb_test WHERE name = ?))', $sql[0]);
        $this->assertSame([['Tom', 50], ['Eva', 42]], array_map(static fn (array $r): array => [$r['name'], (int) $r['age']], $this->db->table('qb_test')->where('age', '>=', 40)->orderBy('id')->get()));
    }

    /**
     * sharedLock() lets another connection read the row with a shared lock but not take an
     * exclusive one; without any lock (control) the exclusive probe succeeds at once.
     */
    public function testSharedLockIsVisibleToAnotherConnection(): void
    {
        $other = Database::mysql([
            'host' => $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
            'database' => $_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['MYSQL_USERNAME'] ?? 'root',
            'password' => $_ENV['MYSQL_PASSWORD'] ?? 'root',
        ]);
        $other->execute('SET SESSION innodb_lock_wait_timeout = 1');
        // NOWAIT exists since MySQL 8.0 / MariaDB 10.3 (the supported matrix starts at 8.0 / 10.11)
        $exclusiveProbe = static fn (): mixed => $other->query('SELECT id FROM qb_test WHERE id = 1 FOR UPDATE NOWAIT')->fetch();
        $sharedProbe = static fn (): mixed => $other->query('SELECT id FROM qb_test WHERE id = 1 LOCK IN SHARE MODE')->fetch();

        $this->db->beginTransaction();
        $this->db->table('qb_test')->where('id', 1)->sharedLock()->first();
        $other->beginTransaction();
        try {
            $this->assertSame(['id' => 1], $sharedProbe(), 'a shared lock does not block another shared lock');
            try {
                $exclusiveProbe();
                $this->fail('Expected the exclusive probe to fail while the row is share-locked');
            } catch (QueryException $e) {
                $this->assertContains($e->getPrevious()?->errorInfo[1] ?? null, [3572, 1205], 'lock conflict, not some other error');
            }
        } finally {
            $other->rollback();
            $this->db->commit();
        }

        // control: a plain read holds no lock, the exclusive probe succeeds at once
        $this->db->beginTransaction();
        $this->db->table('qb_test')->where('id', 1)->first();
        $other->beginTransaction();
        try {
            $this->assertSame(['id' => 1], $exclusiveProbe());
        } finally {
            $other->rollback();
            $this->db->commit();
        }
    }

    public function testGroupedExistsWithHavingOnASelectAlias(): void
    {
        $this->db->insert('qb_test', ['name' => 'Max', 'age' => 40]);

        $this->assertTrue($this->db->table('qb_test')->select(['name', Database::raw('COUNT(*) AS n')])->groupBy('name')->having('n', '>', 1)->exists());
        $this->assertFalse($this->db->table('qb_test')->select(['name', Database::raw('COUNT(*) AS n')])->groupBy('name')->having('n', '>', 5)->exists());
    }

    public function testRowLocksExecuteInsideATransaction(): void
    {
        $this->db->beginTransaction();
        $row = $this->db->table('qb_test')->where('id', 1)->lockForUpdate()->first();
        $shared = $this->db->table('qb_test')->where('id', 2)->sharedLock()->first();
        $count = $this->db->table('qb_test')->lockForUpdate()->count();
        $this->db->commit();

        $this->assertSame('Max', $row['name'] ?? null);
        $this->assertSame('Anna', $shared['name'] ?? null);
        $this->assertSame(2, $count);
    }

    /**
     * The lock must be real: a second connection probing the row with FOR UPDATE NOWAIT fails
     * while the first transaction holds it (via first() and via exists()), and succeeds after the commit.
     */
    public function testRowLockIsVisibleToAnotherConnection(): void
    {
        $other = Database::mysql([
            'host' => $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
            'database' => $_ENV['MYSQL_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['MYSQL_USERNAME'] ?? 'root',
            'password' => $_ENV['MYSQL_PASSWORD'] ?? 'root',
        ]);
        $probe = static fn (): mixed => $other->query('SELECT id FROM qb_test WHERE id = 1 FOR UPDATE NOWAIT')->fetch();

        foreach (['first', 'exists'] as $method) {
            $this->db->beginTransaction();
            $builder = $this->db->table('qb_test')->where('id', 1)->lockForUpdate();
            $method === 'first' ? $builder->first() : $builder->exists();
            try {
                $other->beginTransaction();
                $probe();
                $this->fail("Expected the probe to fail while the row is locked via {$method}()");
            } catch (QueryException $e) {
                $this->assertContains($e->getPrevious()?->errorInfo[1] ?? null, [3572, 1205], 'lock conflict: NOWAIT (MySQL 3572) or lock wait timeout (MariaDB 1205)');
            } finally {
                $other->rollback();
            }
            $this->db->commit();
        }

        $this->assertSame(['id' => 1], $probe());
    }

    public function testCountWithDistinctAndGroupByExecutes(): void
    {
        $this->db->insert('qb_test', ['name' => 'Max', 'age' => 40]);
        $this->db->insert('qb_test', ['name' => 'Max', 'age' => 25]); // ages 25, 30, 40, 25: SUM 120, SUM(DISTINCT) 95
        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
        $this->db->execute('CREATE TABLE qb_profiles (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, name VARCHAR(255))');
        $this->db->insert('qb_profiles', ['user_id' => 1, 'name' => 'Developer']);

        $this->assertSame(2, $this->db->table('qb_test')->select('name')->distinct()->count());
        $this->assertSame(2, $this->db->table('qb_test')->distinct()->count('name'));
        $this->assertSame(2, $this->db->table('qb_test')->groupBy('name')->count());
        $this->assertSame(1, $this->db->table('qb_test')->groupBy('name')->having(Database::raw('COUNT(*)'), '>', 1)->count());
        $this->assertSame(120.0, $this->db->table('qb_test')->sum('age'));
        $this->assertSame(95.0, $this->db->table('qb_test')->distinct()->sum('age'));
        // a join with clashing column names ("name" in both tables) must not break the derived table
        $this->assertSame(2, $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->groupBy('qb_test.name')->count());
        $this->assertSame(4, $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->groupBy(['qb_test.id', 'qb_profiles.id'])->count());
        $this->assertSame(2, $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->select(['qb_test.name'])->distinct()->count());
        $this->assertSame(4, $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->select(['qb_test.id', 'qb_profiles.id as profile_id'])->distinct()->count(), 'same column names aliased apart');
        try {
            $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->select(['qb_test.id', 'qb_profiles.id'])->distinct()->count();
            $this->fail('Expected QueryException for repeated output names');
        } catch (QueryException $e) {
            $this->assertStringContainsString('"id" appears twice', $e->getDebugMessage() ?? '');
        }
        $this->assertSame(4, $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->select(['qb_test.*'])->distinct()->count(), 'one wildcard over a join: four users, distinct by id');
        // clashing names in an explicit select() are dropped from the grouped select (MySQL rejects them in a derived table)
        $this->assertSame(4, $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->select(['qb_test.*', 'qb_profiles.*'])->groupBy(['qb_test.id', 'qb_profiles.id'])->count());
        $this->assertSame(2, $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->select(['qb_test.name', 'qb_profiles.name'])->groupBy('qb_test.name')->count());
        // having() on a select alias keeps working, because aliased select() entries stay in the inner select
        $this->assertSame(1, $this->db->table('qb_test')->select([Database::raw('COUNT(*) AS n')])->groupBy('name')->having('n', '>', 1)->count());
        $this->assertSame(1, $this->db->table('qb_test')->select(['name', 'age', Database::raw('COUNT(*) AS n')])->groupBy('name')->having('n', '>', 1)->count(), 'plain columns dropped, alias kept');
        $this->assertSame(1, $this->db->table('qb_test')->select([Database::raw('COUNT(*)'), Database::raw('COUNT(*) AS n'), Database::raw('COUNT(*) AS n')])->groupBy('name')->having('n', '>', 1)->count(), 'unaliased and repeated aliases would repeat a column name in the derived table');
        $this->assertSame(2, $this->db->table('qb_test')->select([Database::raw('LOWER(name) AS ln')])->groupBy('ln')->count());

        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
    }

    public function testExistsWithOffsetAndLocksExecutes(): void
    {
        $this->db->beginTransaction();
        $next = $this->db->table('qb_test')->orderBy('id')->offset(1)->lockForUpdate()->exists();
        $beyond = $this->db->table('qb_test')->orderBy('id')->offset(2)->sharedLock()->exists();
        $this->db->commit();

        $this->assertTrue($next);
        $this->assertFalse($beyond);
    }

    public function testRightJoin(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
        $this->db->execute('CREATE TABLE qb_profiles (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, bio VARCHAR(255))');
        $this->db->insert('qb_profiles', ['user_id' => 1, 'bio' => 'Developer']);
        $this->db->insert('qb_profiles', ['user_id' => 999, 'bio' => 'Orphan']);

        $results = $this->db->table('qb_test')
            ->rightJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')
            ->get();

        $this->assertCount(2, $results);

        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
    }

    /**
     * A boolean is bound as '1'/'0'. PDO alone sends false as '', which MySQL in strict mode
     * (the default) rejects for a numeric column: "Incorrect integer value: ''".
     */
    public function testBooleansAreBoundAsOneAndZero(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qb_flags');
        $this->db->execute('CREATE TABLE qb_flags (id INT AUTO_INCREMENT PRIMARY KEY, active TINYINT(1), n INT, note VARCHAR(10))');

        $this->db->insert('qb_flags', ['active' => false, 'n' => false, 'note' => false]);
        $this->db->insert('qb_flags', ['active' => true, 'n' => true, 'note' => true]);

        $this->assertSame(
            [['active' => 0, 'n' => 0, 'note' => '0'], ['active' => 1, 'n' => 1, 'note' => '1']],
            $this->db->table('qb_flags')->select(['active', 'n', 'note'])->orderBy('id')->get()
        );
        $this->assertSame(1, $this->db->table('qb_flags')->where('active', false)->where('n', false)->count());
        // a text column is compared as text: only '0' matches, neither '' nor 'abc' (which a numeric 0 would match on MySQL)
        $this->db->execute("INSERT INTO qb_flags (active, n, note) VALUES (0, 0, 'abc'), (0, 0, '')");
        $this->assertSame(1, $this->db->table('qb_flags')->where('note', false)->count());
        $this->assertSame(1, $this->db->table('qb_flags')->where('active', true)->update(['active' => false, 'n' => false]));
        $this->assertSame(4, $this->db->table('qb_flags')->where('active', false)->count());
        $this->assertSame(1, $this->db->insertWhen('qb_flags', ['active' => false, 'n' => false, 'note' => false], 'NOT EXISTS (SELECT 1 FROM qb_flags WHERE active = ?)', [true]));

        $this->db->execute('DROP TABLE IF EXISTS qb_flags');
    }
}
