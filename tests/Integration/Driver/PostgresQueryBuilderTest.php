<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Exception\QueryException;

#[Group('postgres')]
class PostgresQueryBuilderTest extends TestCase
{
    private PostgresDriver $db;

    protected function setUp(): void
    {
        $this->db = Database::postgres([
            'host' => $_ENV['POSTGRES_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
            'database' => $_ENV['POSTGRES_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['POSTGRES_USERNAME'] ?? 'postgres',
            'password' => $_ENV['POSTGRES_PASSWORD'] ?? 'postgres',
        ]);

        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
        $this->db->execute('DROP TABLE IF EXISTS qb_test');
        $this->db->execute('CREATE TABLE qb_test (id SERIAL PRIMARY KEY, name VARCHAR(255), age INT)');
        $this->db->execute('CREATE TABLE qb_profiles (id SERIAL PRIMARY KEY, user_id INT, bio VARCHAR(255))');

        $this->db->insert('qb_test', ['name' => 'Max', 'age' => 25]);
        $this->db->insert('qb_test', ['name' => 'Anna', 'age' => 30]);
        $this->db->insert('qb_profiles', ['user_id' => 1, 'bio' => 'Developer']);
        $this->db->insert('qb_profiles', ['user_id' => 999, 'bio' => 'Orphan']); // No matching user
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qb_profiles');
        $this->db->execute('DROP TABLE IF EXISTS qb_test');
    }

    public function testIsAndIsNotExecuteAsNullSafeEquality(): void
    {
        $this->db->execute('ALTER TABLE qb_test ADD COLUMN nick VARCHAR(50)');
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

    public function testDeleteWithLimitThrowsInsteadOfDeletingEverything(): void
    {
        try {
            $this->db->table('qb_test')->where('id', '>', 0)->orderBy('id')->limit(1)->delete();
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('only MySQL/MariaDB support (dialect "pgsql")', $e->getDebugMessage() ?? '');
        }

        $this->assertSame(2, $this->db->table('qb_test')->count(), 'nothing was deleted');
    }

    public function testInsertWhenExecutes(): void
    {
        $sql = [];
        $this->db->on('query', static function (array $context) use (&$sql): void {
            $sql[] = (string) $context['sql'];
        });
        $condition = 'NOT EXISTS (SELECT 1 FROM qb_test WHERE name = ?)';

        $this->assertSame(1, $this->db->insertWhen('qb_test', ['name' => 'Tom', 'age' => 50], $condition, ['Tom']));
        $this->assertSame(0, $this->db->insertWhen('qb_test', ['name' => 'Tom', 'age' => 51], $condition, ['Tom']));
        $this->assertSame(1, $this->db->table('qb_test')->insertWhen(['name' => 'Eva', 'age' => Database::raw('40 + 2')], '? < ?', [1, 2]));
        $this->assertSame('INSERT INTO "qb_test" ("name", "age") SELECT ?, ? WHERE (NOT EXISTS (SELECT 1 FROM qb_test WHERE name = ?))', $sql[0]);
        $this->assertSame([['Tom', 50], ['Eva', 42]], array_map(static fn (array $r): array => [$r['name'], (int) $r['age']], $this->db->table('qb_test')->where('age', '>=', 40)->orderBy('id')->get()));
    }

    /**
     * sharedLock() lets another connection read the row with a shared lock but not take an
     * exclusive one; without any lock (control) the exclusive probe succeeds at once.
     */
    public function testSharedLockIsVisibleToAnotherConnection(): void
    {
        $other = Database::postgres([
            'host' => $_ENV['POSTGRES_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
            'database' => $_ENV['POSTGRES_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['POSTGRES_USERNAME'] ?? 'postgres',
            'password' => $_ENV['POSTGRES_PASSWORD'] ?? 'postgres',
        ]);
        $exclusiveProbe = static fn (): mixed => $other->query('SELECT id FROM qb_test WHERE id = 1 FOR UPDATE NOWAIT')->fetch();
        $sharedProbe = static fn (): mixed => $other->query('SELECT id FROM qb_test WHERE id = 1 FOR SHARE NOWAIT')->fetch();

        $this->db->beginTransaction();
        $this->db->table('qb_test')->where('id', 1)->sharedLock()->first();
        $other->beginTransaction();
        try {
            $this->assertSame(['id' => 1], $sharedProbe(), 'a shared lock does not block another shared lock');
            try {
                $exclusiveProbe();
                $this->fail('Expected the exclusive probe to fail while the row is share-locked');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->getPrevious()?->errorInfo[0] ?? null, 'lock_not_available, not some other error');
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
        $other = Database::postgres([
            'host' => $_ENV['POSTGRES_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
            'database' => $_ENV['POSTGRES_DATABASE'] ?? 'pdo_wrapper_test',
            'username' => $_ENV['POSTGRES_USERNAME'] ?? 'postgres',
            'password' => $_ENV['POSTGRES_PASSWORD'] ?? 'postgres',
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
                $this->assertSame('55P03', $e->getPrevious()?->errorInfo[0] ?? null, 'lock_not_available: the row is locked');
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

        $this->assertSame(2, $this->db->table('qb_test')->select('name')->distinct()->count());
        $this->assertSame(2, $this->db->table('qb_test')->distinct()->count('name'));
        $this->assertSame(2, $this->db->table('qb_test')->groupBy('name')->count());
        $this->assertSame(1, $this->db->table('qb_test')->groupBy('name')->having(Database::raw('COUNT(*)'), '>', 1)->count());
        $this->assertSame(120.0, $this->db->table('qb_test')->sum('age'));
        $this->assertSame(95.0, $this->db->table('qb_test')->distinct()->sum('age'));
        // group keys from two tables: four users, one of them with a profile (the orphan profile is not in a LEFT JOIN)
        $this->assertSame(4, $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->groupBy(['qb_test.id', 'qb_profiles.id'])->count());
        $this->assertSame(5, $this->db->table('qb_test')->rightJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->groupBy(['qb_test.id', 'qb_profiles.id'])->count() + 3, 'right join: one matched and one orphan profile');
        $this->assertSame(2, $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->select(['qb_test.name'])->distinct()->count());
        $this->assertSame(4, $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->select(['qb_test.*'])->distinct()->count(), 'one wildcard over a join: four users, distinct by id');
        $this->assertSame(4, $this->db->table('qb_test')->leftJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')->select(['qb_test.*', 'qb_profiles.*'])->groupBy(['qb_test.id', 'qb_profiles.id'])->count(), 'wildcards dropped from the grouped select');
        $this->assertSame(2, $this->db->table('qb_test')->select([Database::raw('LOWER(name) AS ln')])->groupBy('ln')->count(), 'groupBy() on a select alias');
        // quoted aliases are case-sensitive on PostgreSQL: "l" and "L" are two aliases, both must stay for GROUP BY
        $this->assertSame(2, $this->db->table('qb_test')->select([Database::raw('LOWER(name) AS "l"'), Database::raw('UPPER(name) AS "L"')])->groupBy(['l', 'L'])->count());
        $this->assertTrue($this->db->table('qb_test')->select([Database::raw('LOWER(name) AS "l"'), Database::raw('UPPER(name) AS "L"')])->groupBy(['l', 'L'])->exists());
        // PostgreSQL rejects a select alias in HAVING; the aggregate itself works
        $this->assertSame(1, $this->db->table('qb_test')->select(['name', Database::raw('COUNT(*) AS n')])->groupBy('name')->having(Database::raw('COUNT(*)'), '>', 1)->count());
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
        $results = $this->db->table('qb_test')
            ->rightJoin('qb_profiles', 'qb_test.id', '=', 'qb_profiles.user_id')
            ->get();

        $this->assertCount(2, $results); // Both profiles, one with NULL user
    }

    public function testQueryBuilderWithPostgresQuoting(): void
    {
        $users = $this->db->table('qb_test')->where('name', 'Max')->get();

        $this->assertCount(1, $users);
    }

    public function testGroupByWithArray(): void
    {
        $results = $this->db->table('qb_test')
            ->select('age')
            ->groupBy(['age'])
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * A boolean is bound as '1'/'0'. PDO alone sends false as '', which PostgreSQL rejects for a
     * boolean or integer column ("invalid input syntax"), in WHERE as well as on insert. Text rather
     * than PARAM_BOOL: that reaches PostgreSQL as 't'/'f', which an integer column rejects.
     */
    public function testBooleansAreBoundAsOneAndZero(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qb_flags');
        $this->db->execute('CREATE TABLE qb_flags (id SERIAL PRIMARY KEY, active BOOLEAN, n INTEGER, note VARCHAR(10))');

        $this->db->insert('qb_flags', ['active' => false, 'n' => false, 'note' => false]);
        $this->db->insert('qb_flags', ['active' => true, 'n' => true, 'note' => true]);

        $this->assertSame(
            [['active' => false, 'n' => 0, 'note' => '0'], ['active' => true, 'n' => 1, 'note' => '1']],
            $this->db->table('qb_flags')->select(['active', 'n', 'note'])->orderBy('id')->get()
        );
        $this->assertSame(1, $this->db->table('qb_flags')->where('active', false)->where('n', false)->count());
        // a text column is compared as text: only '0' matches, neither '' nor 'abc'
        $this->db->execute("INSERT INTO qb_flags (active, n, note) VALUES (false, 0, 'abc'), (false, 0, '')");
        $this->assertSame(1, $this->db->table('qb_flags')->where('note', false)->count());
        $this->assertSame(1, $this->db->table('qb_flags')->where('active', true)->update(['active' => false, 'n' => false]));
        $this->assertSame(4, $this->db->table('qb_flags')->where('active', false)->count());
        $this->assertSame(1, $this->db->insertWhen('qb_flags', ['active' => false, 'n' => false, 'note' => false], 'NOT EXISTS (SELECT 1 FROM qb_flags WHERE active = ?)', [true]));

        $this->db->execute('DROP TABLE IF EXISTS qb_flags');
    }
}
