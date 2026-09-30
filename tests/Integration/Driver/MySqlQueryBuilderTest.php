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
            } catch (QueryException) {
                // locked by the first connection
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
}
