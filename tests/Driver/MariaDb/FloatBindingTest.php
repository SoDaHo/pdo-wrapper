<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * Floats on MariaDB: bound as their exact text whatever PHP's precision setting says; and the
 * other side of exactness - a computed float is no longer rounded into a DECIMAL it was meant
 * to equal.
 */
class FloatBindingTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create('prices', ['id' => 'key', 'price' => 'decimal', 'value' => 'double']);
        $this->db->insert('prices', ['id' => 1, 'price' => '0.30', 'value' => 0.0]);
    }

    public function testThePrecisionSettingDoesNotCount(): void
    {
        $precision = ini_get('precision');
        try {
            ini_set('precision', '5');
            $this->db->update('prices', ['value' => 0.1234567890123456], ['id' => 1]);
        } finally {
            ini_set('precision', (string) $precision);
        }

        $this->assertSame(0.1234567890123456, $this->db->findOne('prices', ['id' => 1])['value'] ?? null);
    }

    /**
     * 0.1 + 0.2 is 0.30000000000000004 and is bound so: it does not equal the DECIMAL 0.3000. A
     * string or a rounded float does.
     */
    public function testAComputedFloatIsNoDecimal(): void
    {
        $this->assertSame(0, $this->db->table('prices')->where('price', 0.1 + 0.2)->count());
        $this->assertSame(1, $this->db->table('prices')->where('price', round(0.1 + 0.2, 2))->count());
        $this->assertSame(1, $this->db->table('prices')->where('price', '0.30')->count());
    }
}
