<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract;

use Sodaho\PdoWrapper\Database;

/**
 * Builder features whose SQL differs between databases, executed: the null-safe IS / IS NOT,
 * OFFSET without LIMIT, exists() over grouped, distinct and having queries.
 */
class BuilderExecutionTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create('users', ['id' => 'id', 'name' => 'text', 'nick' => 'text']);
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
        $this->assertSame('SELECT `name` FROM `users` GROUP BY `name`', $grouped->toSql()[0], 'exists() must not change the builder');
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
        // raw bounds
        $this->assertTrue($this->db->table('users')->groupBy('name')->having(Database::raw('COUNT(*)'), '>', Database::raw('1'))->exists());
        $this->assertFalse($this->db->table('users')->groupBy('name')->having(Database::raw('COUNT(*)'), '>', Database::raw('5'))->exists());
        // HAVING without GROUP BY: evaluated via COUNT(*), as before
        $this->assertTrue($this->db->table('users')->having(Database::raw('COUNT(*)'), '>=', Database::raw('3'))->exists());
        $this->assertFalse($this->db->table('users')->having(Database::raw('COUNT(*)'), '>', Database::raw('5'))->exists());
        $this->assertFalse($this->db->table('users')->where('id', 999)->having(Database::raw('COUNT(*)'), '=', Database::raw('0'))->exists());
    }
}
