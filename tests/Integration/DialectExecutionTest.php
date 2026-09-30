<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;

/**
 * Dialect-dependent builder features executed on SQLite: IS / IS NOT, OFFSET without LIMIT, row locks (omitted).
 */
class DialectExecutionTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = Database::sqlite();
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, nick TEXT)');
        $this->db->insert('users', ['name' => 'Max', 'nick' => null]);
        $this->db->insert('users', ['name' => 'Anna', 'nick' => 'anna']);
    }

    public function testIsAndIsNotCompareNullSafely(): void
    {
        $this->assertSame(['Anna'], array_column($this->db->table('users')->where('nick', 'IS', 'anna')->get(), 'name'));
        // NULL IS NOT 'anna' is true, unlike NULL <> 'anna'
        $this->assertSame(['Max'], array_column($this->db->table('users')->where('nick', 'IS NOT', 'anna')->get(), 'name'));
        $this->assertSame([], array_column($this->db->table('users')->where('nick', '<>', 'anna')->get(), 'name'));
    }

    public function testOffsetWithoutLimitReturnsTheRemainingRows(): void
    {
        $rows = $this->db->table('users')->orderBy('id')->offset(1)->get();

        $this->assertSame(['Anna'], array_column($rows, 'name'));
        // exists() keeps the offset: "is there a next page?"
        $this->assertTrue($this->db->table('users')->offset(1)->exists());
        $this->assertFalse($this->db->table('users')->offset(2)->exists());
    }

    public function testExistsWithGroupingDistinctAndHaving(): void
    {
        $this->db->insert('users', ['name' => 'Max', 'nick' => 'max2']);
        $grouped = $this->db->table('users')->select(['name'])->groupBy('name');

        $this->assertTrue($grouped->exists());
        $this->assertSame('SELECT "name" FROM "users" GROUP BY "name"', $grouped->toSql()[0], 'exists() must not change the builder');
        $this->assertTrue($this->db->table('users')->select(['name'])->distinct()->exists());
        // DISTINCT keeps its cardinality (two distinct names): offset(1) has a next page, offset(2) has not
        $this->assertTrue($this->db->table('users')->select(['name'])->distinct()->offset(1)->exists());
        $this->assertFalse($this->db->table('users')->select(['name'])->distinct()->offset(2)->exists());
        $this->assertTrue($this->db->table('users')->groupBy('name')->offset(1)->exists());
        $this->assertFalse($this->db->table('users')->groupBy('name')->offset(2)->exists());
        // DISTINCT with the other projections: wildcard, table wildcard, raw column (three rows, all distinct)
        $this->assertTrue($this->db->table('users')->distinct()->offset(2)->exists());
        $this->assertFalse($this->db->table('users')->distinct()->offset(3)->exists());
        $this->assertTrue($this->db->table('users')->select(['users.*'])->distinct()->offset(2)->exists());
        $this->assertTrue($this->db->table('users')->select([Database::raw('LOWER(name) AS n')])->distinct()->offset(1)->exists());
        $this->assertFalse($this->db->table('users')->select([Database::raw('LOWER(name) AS n')])->distinct()->offset(2)->exists());
        // raw bounds: SQLite compares a bound number in HAVING as text (known, to be fixed in the SQLite hardening)
        $this->assertTrue($this->db->table('users')->groupBy('name')->having(Database::raw('COUNT(*)'), '>', Database::raw('1'))->exists());
        $this->assertFalse($this->db->table('users')->groupBy('name')->having(Database::raw('COUNT(*)'), '>', Database::raw('5'))->exists());
        // HAVING without GROUP BY: evaluated via COUNT(*), as before
        $this->assertTrue($this->db->table('users')->having(Database::raw('COUNT(*)'), '>=', Database::raw('3'))->exists());
        $this->assertFalse($this->db->table('users')->having(Database::raw('COUNT(*)'), '>', Database::raw('5'))->exists());
        $this->assertFalse($this->db->table('users')->where('id', 999)->having(Database::raw('COUNT(*)'), '=', Database::raw('0'))->exists());
    }

    public function testRowLocksAreOmittedOnSqliteAndStillExecute(): void
    {
        $this->db->beginTransaction();
        $row = $this->db->table('users')->where('id', 1)->lockForUpdate()->first();
        $shared = $this->db->table('users')->where('id', 2)->sharedLock()->first();
        $this->db->commit();

        $this->assertSame('Max', $row['name'] ?? null);
        $this->assertSame('Anna', $shared['name'] ?? null);
        $this->assertSame('SELECT * FROM "users" WHERE "id" = ?', $this->db->table('users')->where('id', 1)->lockForUpdate()->toSql()[0]);
    }
}
