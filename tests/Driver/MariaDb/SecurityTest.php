<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Contract\Feature\SecuritySchema;
use Sodaho\PdoWrapper\Tests\Support\ReadsPdoErrorInfo;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

/**
 * Injection and escaping on MariaDB alone: backslashes, NO_BACKSLASH_ESCAPES, emulated prepares,
 * backticks inside identifiers, binary data in a text column.
 */
class SecurityTest extends ContractTestCase
{
    use ReadsPdoErrorInfo;
    use SecuritySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSecuritySchema();
    }

    // MySQL-specific: Test backslash escaping (MySQL uses backslash as escape)
    public function testMySqlBackslashEscaping(): void
    {
        $nameWithBackslash = 'Test\\Name';
        $this->db->insert('users', ['name' => $nameWithBackslash, 'email' => 'backslash@example.com']);

        $user = $this->db->table('users')->where('name', $nameWithBackslash)->first();
        $this->assertNotNull($user);
        $this->assertSame($nameWithBackslash, $user['name']);
    }

    /**
     * NO_BACKSLASH_ESCAPES takes the backslash away as MySQL's default LIKE escape character
     * (MariaDB keeps it). The bound escape character makes escapeLike() hold in that mode too.
     */
    public function testEscapeLikeHoldsUnderNoBackslashEscapes(): void
    {
        $this->db->execute("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_BACKSLASH_ESCAPES')");
        $this->seedLikeNames();

        $this->assertLikeFinds(['100% sure'], '100%');
        $this->assertLikeFinds(['under_score'], 'under_');
        $this->assertLikeFinds(['back\\slash'], 'back\\');
    }

    public function testEscapeLikeHoldsWithEmulatedPrepares(): void
    {
        $this->db = Database::mariadb([
            ...TestEnvironment::mariadb(),
            'options' => [\PDO::ATTR_EMULATE_PREPARES => true],
        ]);
        $this->seedLikeNames();

        $this->assertLikeFinds(['100% sure'], '100%');
        $this->assertLikeFinds(['back\\slash'], 'back\\');

        $this->db->execute("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_BACKSLASH_ESCAPES')");

        $this->assertLikeFinds(['100% sure'], '100%');
        $this->assertLikeFinds(['back\\slash'], 'back\\');
    }

    /**
     * A backtick inside an identifier is doubled, so the name stays one identifier: a column that
     * really carries a backtick works, and a name built to break out of the quoting is just an
     * unknown column.
     */
    public function testBacktickInAnIdentifierStaysInsideTheIdentifier(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS backtick_names');
        $this->db->execute('CREATE TABLE backtick_names (id INT AUTO_INCREMENT PRIMARY KEY, `we``ird` VARCHAR(20)) ENGINE=InnoDB');
        try {
            $id = $this->db->insert('backtick_names', ['we`ird' => 'value']);
            $this->assertSame('value', $this->db->table('backtick_names')->where('we`ird', 'value')->first()['we`ird'] ?? null);
            $this->assertSame(1, $this->db->update('backtick_names', ['we`ird' => 'changed'], ['id' => $id]));
            $this->assertSame(['changed'], array_column($this->db->table('backtick_names')->select('we`ird')->orderBy('we`ird')->get(), 'we`ird'));

            try {
                $this->db->table('backtick_names')->where('id` = 1 OR `id', 999)->get();
                $this->fail('Expected QueryException: the name is one unknown column');
            } catch (QueryException $e) {
                $this->assertSame(1054, $this->errorInfoBehind($e, 1), 'ER_BAD_FIELD_ERROR');
            }
            $this->assertSame(1, $this->db->table('backtick_names')->count(), 'the row is untouched');
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS backtick_names');
        }
    }

    // MySQL-specific: Test that binary data in utf8mb4 TEXT field is rejected
    // (This is expected - for binary data use BLOB type)
    public function testMySqlBinaryDataInTextFieldIsRejected(): void
    {
        $binaryData = "\xFF\xFE"; // Invalid UTF-8 sequence

        $this->expectException(\Sodaho\PdoWrapper\Exception\QueryException::class);
        $this->db->insert('secrets', ['secret_data' => $binaryData]);
    }
}
