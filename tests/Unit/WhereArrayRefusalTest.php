<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;

/**
 * where() with an array is all or nothing: a refused entry leaves the builder as it was, so a
 * caller that catches the exception and goes on does not query with part of the filter. No
 * database needed.
 */
class WhereArrayRefusalTest extends TestCase
{
    private function table(): QueryBuilder
    {
        $db = new class () extends AbstractDriver {
        };

        return $db->table('users');
    }

    public function testARefusedArrayAddsNoneOfItsConditions(): void
    {
        $query = $this->table()->where('active', 1);
        try {
            $query->where(['tenant_id' => 7, 'role' => null]);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringStartsWith('Cannot use null value for column "role" in where()', (string) $e->getDebugMessage());
        }

        $this->assertSame(['SELECT * FROM `users` WHERE `active` = ?', [1]], $query->toSql(), 'tenant_id = 7 was not added');
    }

    public function testAnArrayWithoutNullAddsEveryCondition(): void
    {
        $this->assertSame(
            ['SELECT * FROM `users` WHERE `tenant_id` = ? AND `role` = ?', [7, 'admin']],
            $this->table()->where(['tenant_id' => 7, 'role' => 'admin'])->toSql()
        );
    }
}
