<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract;

/**
 * The PHP types the column types of the binding arrive as - part of the contract (see
 * DriverBinding): `id`, `key`, `int`, `bigint` as int, `double` as float, `decimal` as a string
 * with its 4 decimal places, `text`, `blob` and `timestamp` as string, NULL as null. Through the
 * builder, through a raw query and through the driver's helpers alike.
 */
class ColumnTypesTest extends ContractTestCase
{
    private const ROW = [
        'id' => 1,
        'small' => -7,
        'big' => 9007199254740993,
        'name' => 'Ärger',
        'price' => '12.5000',
        'ratio' => 0.25,
        'bytes' => "\x00\xff\x10",
        'at' => '2026-10-04 12:00:00',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->create('typed', ['id' => 'id', 'small' => 'int', 'big' => 'bigint', 'name' => 'text', 'price' => 'decimal', 'ratio' => 'double', 'bytes' => 'blob', 'at' => 'timestamp NULL']);
        $this->create('keyed', ['code' => 'key', 'note' => 'text']);
    }

    public function testEveryColumnTypeArrivesAsItsPhpType(): void
    {
        $this->db->insert('typed', ['small' => -7, 'big' => 9007199254740993, 'name' => 'Ärger', 'price' => '12.5', 'ratio' => 0.25, 'bytes' => "\x00\xff\x10", 'at' => '2026-10-04 12:00:00']);
        $this->db->insert('keyed', ['code' => 42, 'note' => 'x']);

        $this->assertSame([self::ROW], $this->db->table('typed')->get(), 'through the builder');
        $this->assertSame(self::ROW, $this->db->findOne('typed', ['id' => 1]), 'through findOne()');
        $this->assertSame([self::ROW], $this->db->query('SELECT * FROM typed')->fetchAll(), 'through a raw query');
        $this->assertSame([['code' => 42, 'note' => 'x']], $this->db->table('keyed')->get());
    }

    public function testNullArrivesAsNullInEveryColumnType(): void
    {
        $this->db->insert('typed', ['small' => null, 'big' => null, 'name' => null, 'price' => null, 'ratio' => null, 'bytes' => null, 'at' => null]);

        $this->assertSame(['id' => 1, 'small' => null, 'big' => null, 'name' => null, 'price' => null, 'ratio' => null, 'bytes' => null, 'at' => null], $this->db->findOne('typed', ['id' => 1]));
    }
}
