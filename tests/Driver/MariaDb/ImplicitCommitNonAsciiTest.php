<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\ImplicitCommit;
use Sodaho\PdoWrapper\Exception\ImplicitCommitException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

/**
 * Bytes beyond ASCII before the leading keywords, against the server on a latin1 connection.
 * MariaDB reads 0xA0 there as a space (the ctype table of latin1): CREATE<0xA0>TABLE,
 * <0xA0>CREATE TABLE and a `--` comment opened by 0xA0 commit the open transaction on raw PDO
 * (measured on MariaDB 10.11, 11.4 and 12.3). The library keeps no
 * charset tables: inside a transaction it refuses such a statement unjudged ("NON-ASCII OR CONTROL
 * BYTES"), and nothing is committed. A byte after the point where the leading keywords are decided
 * changes nothing: such a statement is judged by them, and one that commits nothing runs.
 */
class ImplicitCommitNonAsciiTest extends ContractTestCase
{
    private const TABLE = 'implicit_commit_bytes';

    private const DDL_TABLE = 'implicit_commit_bytes_ddl';

    /** The connection under test, with the latin1 charset */
    private DatabaseInterface $latin1;

    /** @var list<string> The outcomes the latin1 connection told */
    private array $outcomes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->create(self::TABLE, ['id' => 'key', 'name' => 'text']);
        $this->db->execute('DROP TABLE IF EXISTS ' . self::DDL_TABLE);
        $this->latin1 = Database::mariadb(TestEnvironment::mariadb() + ['charset' => 'latin1']);
        $this->outcomes = [];
        $this->latin1->on('transaction.end', function (array $data): void {
            $this->outcomes[] = (string) $data['outcome'];
        });
    }

    protected function tearDown(): void
    {
        $this->db->getPdo()->exec('DROP TABLE IF EXISTS ' . self::DDL_TABLE);
        parent::tearDown();
    }

    protected function closeConnections(): void
    {
        unset($this->latin1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function separatedByANoBreakSpace(): array
    {
        return [
            'between the keywords' => ["CREATE\xA0TABLE " . self::DDL_TABLE . ' (id INT PRIMARY KEY)'],
            'before the first keyword' => ["\xA0CREATE TABLE " . self::DDL_TABLE . ' (id INT PRIMARY KEY)'],
            'after the dashes of a comment' => ["--\xA0lead\nCREATE TABLE " . self::DDL_TABLE . ' (id INT PRIMARY KEY)'],
        ];
    }

    /**
     * On raw PDO, inside a transaction, each statement commits the transaction before it runs: the
     * row written before it is there for another connection, no transaction is open any more, and
     * the table was created.
     */
    #[DataProvider('separatedByANoBreakSpace')]
    public function testOnRawPdoTheServerCommitsImplicitly(string $sql): void
    {
        $pdo = $this->latin1->getPdo();
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO ' . self::TABLE . " (id, name) VALUES (1, 'before')");
        $statement = $pdo->query($sql);
        $this->assertNotFalse($statement);
        $statement->closeCursor();

        $this->assertSame(0, (int) $this->latin1->query('SELECT @@in_transaction')->fetchColumn(), 'committed implicitly');
        $this->assertSame([1], array_column($this->db->findAll(self::TABLE), 'id'), 'the row before it is committed');
        $this->assertFalse($pdo->inTransaction(), 'PDO knows it as well');
        $this->assertCount(1, $this->db->query("SHOW TABLES LIKE '" . self::DDL_TABLE . "'")->fetchAll(), 'the table was created');
    }

    /**
     * Inside a transaction: refused unjudged before it is sent, the transaction still open on the
     * server, and the rollback after it is confirmed - the row written before is not there, nothing
     * was created.
     */
    #[DataProvider('separatedByANoBreakSpace')]
    public function testRefusedUnjudgedInsideATransactionItCommitsNothing(string $sql): void
    {
        $this->latin1->beginTransaction();
        $this->latin1->insert(self::TABLE, ['id' => 1, 'name' => 'before']);
        try {
            $this->latin1->execute($sql);
            $this->fail('Expected ImplicitCommitException');
        } catch (ImplicitCommitException $e) {
            $this->assertSame(ImplicitCommit::UNJUDGED_BYTES, $e->statement);
            $this->assertStringStartsWith('Not sent: a byte beyond ASCII (from 0x80 on) or a control character', (string) $e->getDebugMessage());
        }
        $this->assertSame(1, (int) $this->latin1->query('SELECT @@in_transaction')->fetchColumn(), 'the transaction is still open');
        $this->latin1->rollback();

        $this->assertSame([DatabaseInterface::TRANSACTION_ROLLED_BACK], $this->outcomes);
        $this->assertSame([], $this->db->findAll(self::TABLE));
        $this->assertSame([], $this->db->query("SHOW TABLES LIKE '" . self::DDL_TABLE . "'")->fetchAll());
    }

    /**
     * A byte after the point where the leading keywords are decided: a CREATE TABLE with a latin1
     * name is refused as the CREATE it is, a SELECT of a latin1 string runs inside the transaction,
     * and the transaction goes on - its rollback takes the row back.
     */
    public function testAByteAfterTheLeadingKeywordsChangesNothing(): void
    {
        $this->latin1->beginTransaction();
        $this->latin1->insert(self::TABLE, ['id' => 1, 'name' => 'before']);
        try {
            $this->latin1->execute('CREATE TABLE ' . self::DDL_TABLE . "\xFC (id INT PRIMARY KEY)");
            $this->fail('Expected ImplicitCommitException');
        } catch (ImplicitCommitException $e) {
            $this->assertSame('CREATE', $e->statement);
        }
        $this->assertSame([["\xFC"]], $this->latin1->query("SELECT '\xFC'")->fetchAll(PDO::FETCH_NUM));
        $this->assertSame(1, (int) $this->latin1->query('SELECT @@in_transaction')->fetchColumn(), 'the transaction is still open');
        $this->latin1->rollback();

        $this->assertSame([DatabaseInterface::TRANSACTION_ROLLED_BACK], $this->outcomes);
        $this->assertSame([], $this->db->findAll(self::TABLE));
    }
}
