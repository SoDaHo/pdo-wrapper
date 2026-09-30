<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * whereRaw(): a trusted SQL condition with bound values, for what the other where*() methods
 * cannot express (an expression on the left, an OR group).
 */
class WhereRawTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = Database::sqlite();
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, status TEXT, score INTEGER)');
        foreach ([['Max@Example.com', 'active', 10], ['anna@example.com', 'active', 20], ['tom@example.com', 'inactive', 30]] as [$email, $status, $score]) {
            $this->db->insert('users', ['email' => $email, 'status' => $status, 'score' => $score]);
        }
    }

    public function testRawConditionIsJoinedWithAndAndItsValuesAreBoundInOrder(): void
    {
        $query = $this->db->table('users')
            ->where('status', 'active')
            ->whereRaw('LOWER(email) = ?', ['max@example.com'])
            ->whereRaw('score > ? OR score < ?', [15, 5]);
        [$sql, $params] = $query->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ? AND (LOWER(email) = ?) AND (score > ? OR score < ?)', $sql);
        $this->assertSame(['active', 'max@example.com', 15, 5], $params);
        $this->assertSame([], $query->get(), 'Max has score 10: neither > 15 nor < 5');
        $this->assertSame(['Max@Example.com'], array_column($this->db->table('users')->whereRaw('LOWER(email) = ?', ['max@example.com'])->get(), 'email'));
        $this->assertSame(2, $this->db->table('users')->whereRaw('score > ? OR score < ?', [15, 5])->count());
    }

    /**
     * The raw bindings keep their place among the other parameters: after earlier WHERE values,
     * before HAVING values, in every statement shape (grouped and distinct count(), exists(), update()).
     */
    public function testRawBindingsKeepTheirOrderInEveryStatementShape(): void
    {
        $grouped = $this->db->table('users')
            ->select(['status', Database::raw('COUNT(*) AS n')])
            ->whereIn('score', [10, Database::raw('20'), 30])
            ->whereRaw('LOWER(email) LIKE ? OR score >= ?', ['%example.com', 30])
            ->groupBy('status')
            ->having(Database::raw('COUNT(*)'), '>=', 1);
        [$sql, $params] = $grouped->toSql();

        $this->assertSame('SELECT `status`, COUNT(*) AS n FROM `users` WHERE `score` IN (?, 20, ?) AND (LOWER(email) LIKE ? OR score >= ?) GROUP BY `status` HAVING COUNT(*) >= ?', $sql);
        $this->assertSame([10, 30, '%example.com', 30, 1], $params);
        $this->assertSame([['status' => 'active', 'n' => 2], ['status' => 'inactive', 'n' => 1]], $grouped->orderBy('status')->get());
        $this->assertSame(2, $grouped->count(), 'two groups');
        $this->assertTrue($grouped->exists());
        $this->assertSame(2, $this->db->table('users')->select(['status'])->distinct()->whereRaw('score >= ?', [10])->count());
        $this->assertFalse($this->db->table('users')->whereRaw('score > ?', [100])->groupBy('status')->exists());
        // exists() with having() but no groupBy() takes the count() path: WHERE bindings still come before the HAVING binding
        $this->assertTrue($this->db->table('users')->whereRaw('score >= ?', [10])->having(Database::raw('COUNT(*)'), '>', 2)->exists());
        $this->assertFalse($this->db->table('users')->whereRaw('score >= ?', [20])->having(Database::raw('COUNT(*)'), '>', 2)->exists());

        $updated = $this->db->table('users')->where('status', 'active')->whereRaw('score < ?', [15])->update(['score' => 11, 'status' => 'bumped']);
        $this->assertSame(1, $updated);
        $this->assertSame(11, $this->db->table('users')->where('status', 'bumped')->first()['score'] ?? null, 'SET values are bound before the WHERE values');
    }

    public function testRawConditionWorksWithoutBindingsAndInUpdateAndDelete(): void
    {
        $this->assertSame(1, $this->db->table('users')->whereRaw("status = 'inactive'")->count());
        $this->assertSame(1, $this->db->table('users')->whereRaw('LOWER(email) LIKE ?', ['%tom%'])->update(['score' => 0]));
        $this->assertSame(0, $this->db->table('users')->where('email', 'tom@example.com')->first()['score'] ?? null);
        $this->assertSame(2, $this->db->table('users')->whereRaw('score >= ?', [10])->delete());
        $this->assertSame(['tom@example.com'], array_column($this->db->table('users')->get(), 'email'));
    }

    public function testEmptySqlAndRawBindingsAreRejected(): void
    {
        try {
            $this->db->table('users')->whereRaw('   ');
            $this->fail('Expected QueryException for an empty condition');
        } catch (QueryException $e) {
            $this->assertSame('whereRaw() needs a condition', $e->getDebugMessage());
        }

        try {
            $this->db->table('users')->whereRaw('score > ?', [Database::raw('1')]);
            $this->fail('Expected QueryException for a raw binding');
        } catch (QueryException $e) {
            $this->assertStringContainsString('binds its values', $e->getDebugMessage() ?? '');
        }
    }
}
