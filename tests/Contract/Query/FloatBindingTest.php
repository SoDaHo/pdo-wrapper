<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Fetched;

/**
 * A float is bound as the float it is - all its digits, not the 14 of PHP's precision setting -
 * in insert(), update(), the conditions and raw bindings.
 */
class FloatBindingTest extends ContractTestCase
{
    private const SIXTEEN_DIGITS = 0.1234567890123456;

    protected function setUp(): void
    {
        parent::setUp();
        $this->create('measures', ['id' => 'key', 'value' => 'double', 'big' => 'bigint']);
    }

    public function testTheFloatArrivesWithAllItsDigits(): void
    {
        $this->db->insert('measures', ['id' => 1, 'value' => self::SIXTEEN_DIGITS, 'big' => 9007199254740994.0]);
        $row = $this->db->findOne('measures', ['id' => 1]) ?? [];

        $this->assertSame(self::SIXTEEN_DIGITS, $row['value'] ?? null);
        $this->assertSame(9007199254740994, Fetched::int($row['big'] ?? null), 'beyond 2^53 into an integer column');
    }

    public function testConditionsCompareTheWholeFloat(): void
    {
        $this->db->insert('measures', ['id' => 1, 'value' => self::SIXTEEN_DIGITS]);
        $this->db->insert('measures', ['id' => 2, 'value' => 0.12345678901235]);
        $ids = fn ($builder): array => array_column($builder->orderBy('id')->get(), 'id');

        $this->assertSame([1], $ids($this->db->table('measures')->where('value', self::SIXTEEN_DIGITS)));
        $this->assertSame([1], $ids($this->db->table('measures')->whereIn('value', [self::SIXTEEN_DIGITS, 5.5])));
        $this->assertSame([1], $ids($this->db->table('measures')->whereBetween('value', [0.1234567890123455, 0.1234567890123457])));
        $this->assertSame([2], $ids($this->db->table('measures')->where('value', '>', self::SIXTEEN_DIGITS)));
        $this->assertCount(1, $this->db->findAll('measures', ['value' => self::SIXTEEN_DIGITS]));
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM measures WHERE value = ?', [self::SIXTEEN_DIGITS])->fetchColumn());
    }

    public function testUpdatesAndRawBindingsCarryTheWholeFloat(): void
    {
        $value = fn (): mixed => $this->db->findOne('measures', ['id' => 1])['value'] ?? null;
        $this->db->insert('measures', ['id' => 1, 'value' => 0.0]);
        $this->db->update('measures', ['value' => self::SIXTEEN_DIGITS], ['id' => 1]);
        $this->assertSame(self::SIXTEEN_DIGITS, $value());

        $this->db->table('measures')->where('id', 1)->update(['value' => Database::raw('? * 2', [self::SIXTEEN_DIGITS])]);
        $this->assertSame(self::SIXTEEN_DIGITS * 2, $value());
    }
}
