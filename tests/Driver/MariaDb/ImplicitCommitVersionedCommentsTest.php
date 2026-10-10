<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\PdoWrapper\Driver\ImplicitCommit;
use Sodaho\PdoWrapper\Exception\ImplicitCommitException;
use Sodaho\PdoWrapper\Tests\Contract\TransactionEnd\TransactionEndTestCase;

/**
 * Executable comments against the server. What MariaDB runs of a versioned comment depends on its
 * version (it ignores MySQL's from 5.7 on and runs its own up to the server's) and on how its lexer
 * nests comments: each statement below commits the transaction implicitly on raw PDO - a TEMPORARY
 * inside a MySQL comment is not run, a skipped comment takes one comment inside it along, also
 * inside a comment MariaDB runs, a MariaDB comment adds the TABLE of an ANALYZE or the FOR of a SET
 * STATEMENT. The library does not judge such a comment where it stands before the leading keywords
 * are decided: inside a transaction it refuses the statement unjudged ("VERSIONED COMMENTS"), and
 * nothing is committed. One after that point cannot change the leading keywords: it runs.
 */
class ImplicitCommitVersionedCommentsTest extends TransactionEndTestCase
{
    private const DDL_TABLE = 'implicit_commit_versioned';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute('DROP TABLE IF EXISTS ' . self::DDL_TABLE);
    }

    protected function tearDown(): void
    {
        $this->db->getPdo()->exec('DROP TABLE IF EXISTS ' . self::DDL_TABLE);
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function versionedStatements(): array
    {
        return [
            'a MySQL comment around TEMPORARY' => ['CREATE /*!50700 TEMPORARY */ TABLE ' . self::DDL_TABLE . ' (id INT PRIMARY KEY)'],
            'a MariaDB comment around CREATE, a MySQL one around TEMPORARY' => ['/*M!100100 CREATE */ /*!50700 TEMPORARY */ TABLE ' . self::DDL_TABLE . ' (id INT PRIMARY KEY)'],
            'a comment inside a MySQL comment the server skips' => ['/*!50700 /* nested */ SELECT */ CREATE TABLE ' . self::DDL_TABLE . ' (id INT PRIMARY KEY)'],
            'the same inside a MariaDB comment the server runs' => ['/*M!100100 CREATE /*!50700 /* nested */ TEMPORARY */ TABLE ' . self::DDL_TABLE . ' (id INT PRIMARY KEY) */'],
            'a MariaDB comment around the TABLE of an ANALYZE' => ['ANALYZE /*M!100100 TABLE */ ' . self::TABLE],
            'a MariaDB comment around the FOR of a SET STATEMENT' => ['SET STATEMENT max_statement_time = 0 /*M!100100 FOR CREATE TABLE ' . self::DDL_TABLE . ' (id INT PRIMARY KEY) */'],
        ];
    }

    /**
     * On raw PDO, inside a transaction, each statement commits the transaction before it runs: the
     * row written before it is there for another connection, and no transaction is open any more.
     */
    #[DataProvider('versionedStatements')]
    public function testOnRawPdoTheServerCommitsImplicitly(string $sql): void
    {
        $pdo = $this->db->getPdo();
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO ' . self::TABLE . " (id, name) VALUES (1, 'before')");
        $statement = $pdo->query($sql);
        $this->assertNotFalse($statement);
        $statement->closeCursor();

        $this->assertSame(0, (int) $this->db->query('SELECT @@in_transaction')->fetchColumn(), 'committed implicitly');
        $this->assertSame([1], array_column($this->observer->findAll(self::TABLE), 'id'), 'the row before it is committed');
        $this->assertFalse($pdo->inTransaction(), 'PDO knows it as well');
    }

    /**
     * Outside of a transaction the statement runs - and what it creates is a base table, visible
     * to another connection: the TEMPORARY inside the MySQL comment was not run.
     */
    public function testTheServerIgnoresAMysqlVersionComment(): void
    {
        $this->db->execute('CREATE /*!50700 TEMPORARY */ TABLE ' . self::DDL_TABLE . ' (id INT PRIMARY KEY)');

        $this->assertCount(1, $this->observer->query("SHOW FULL TABLES LIKE '" . self::DDL_TABLE . "'")->fetchAll(), 'a base table, seen from another connection');
    }

    /**
     * Inside a transaction: refused unjudged before it is sent, and the rollback after it is
     * confirmed - the row written before is not there, nothing was created.
     */
    #[DataProvider('versionedStatements')]
    public function testRefusedUnjudgedInsideATransactionItCommitsNothing(string $sql): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before']);
        try {
            $this->db->execute($sql);
            $this->fail('Expected ImplicitCommitException');
        } catch (ImplicitCommitException $e) {
            $this->assertSame(ImplicitCommit::UNJUDGED, $e->statement);
            $this->assertStringStartsWith('Not sent: an executable comment (/*!...*/, /*M!...*/) stands before the statement\'s leading keywords are decided', (string) $e->getDebugMessage());
        }
        $this->assertTrue($this->db->inTransaction(), 'the transaction is still open');
        $this->db->rollback();

        $this->assertSame([self::ROLLED_BACK], array_column($this->ends, 'outcome'));
        $this->assertVisible([]);
        $this->assertSame([], $this->observer->query("SHOW TABLES LIKE '" . self::DDL_TABLE . "'")->fetchAll());
    }

    /**
     * A versioned comment after the leading keywords are decided cannot change them: the statement
     * runs inside the transaction, as MariaDB reads it (the MySQL comment ignored, the old version
     * run), and the transaction goes on - its rollback takes the row back.
     */
    public function testAVersionedCommentAfterTheLeadingKeywordsRuns(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before']);
        $this->assertSame([[1]], $this->db->query('SELECT 1 /*!50700 , 2 */')->fetchAll(\PDO::FETCH_NUM));
        $this->assertSame([[1]], $this->db->query('SELECT /*!40001 SQL_NO_CACHE */ 1')->fetchAll(\PDO::FETCH_NUM));
        $this->assertTrue($this->db->inTransaction(), 'the transaction is still open');
        $this->db->rollback();

        $this->assertSame([self::ROLLED_BACK], array_column($this->ends, 'outcome'));
        $this->assertVisible([]);
    }

    /**
     * SET DEFAULT ROLE commits as well (measured, not on the documented list): refused by its
     * keywords, nothing committed.
     */
    public function testSetDefaultRoleIsRefused(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before']);
        try {
            $this->db->execute('SET DEFAULT ROLE NONE');
            $this->fail('Expected ImplicitCommitException');
        } catch (ImplicitCommitException $e) {
            $this->assertSame('SET DEFAULT ROLE', $e->statement);
        }
        $this->db->rollback();

        $this->assertSame([self::ROLLED_BACK], array_column($this->ends, 'outcome'));
        $this->assertVisible([]);
    }
}
