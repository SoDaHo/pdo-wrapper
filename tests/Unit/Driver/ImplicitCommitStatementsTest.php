<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Driver\ImplicitCommit;

/**
 * Which statements the MariaDB driver takes for ones that commit implicitly, without a database:
 * the list of the MariaDB documentation, read from the leading keywords past whitespace and
 * comments - not the statements that steer transactions themselves, not the TEMPORARY tables, not
 * what a procedure or a compound statement runs inside.
 */
class ImplicitCommitStatementsTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string}>
     */
    public static function statements(): array
    {
        return [
            // DDL
            'create table' => ['CREATE TABLE t (id INT)', 'CREATE'],
            'lower case' => ['create table t (id int)', 'CREATE'],
            'create index' => ['CREATE INDEX i ON t (a)', 'CREATE'],
            'create or replace' => ['CREATE OR REPLACE TABLE t (id INT)', 'CREATE'],
            'create table as select' => ['CREATE TABLE t2 AS SELECT * FROM t', 'CREATE'],
            'create view with definer' => ['CREATE DEFINER = CURRENT_USER VIEW v AS SELECT 1', 'CREATE'],
            'drop table' => ['DROP TABLE t', 'DROP'],
            'drop index' => ['DROP INDEX i ON t', 'DROP'],
            'alter table' => ['ALTER TABLE t ADD c INT', 'ALTER'],
            'alter online table' => ['ALTER ONLINE TABLE t ADD c INT', 'ALTER'],
            'rename table' => ['RENAME TABLE a TO b', 'RENAME'],
            'truncate' => ['TRUNCATE t', 'TRUNCATE'],
            'truncate table' => ['TRUNCATE TABLE t', 'TRUNCATE'],
            // locks and maintenance
            'lock tables' => ['LOCK TABLES t WRITE', 'LOCK'],
            'unlock tables' => ['UNLOCK TABLES', 'UNLOCK'],
            'analyze table' => ['ANALYZE TABLE t', 'ANALYZE'],
            'analyze local table' => ['ANALYZE LOCAL TABLE t', 'ANALYZE'],
            'check table' => ['CHECK TABLE t', 'CHECK'],
            'optimize table' => ['OPTIMIZE TABLE t', 'OPTIMIZE'],
            'repair table' => ['REPAIR TABLE t', 'REPAIR'],
            'cache index' => ['CACHE INDEX t IN hot', 'CACHE'],
            'load index' => ['LOAD INDEX INTO CACHE t', 'LOAD INDEX'],
            'flush' => ['FLUSH PRIVILEGES', 'FLUSH'],
            'reset' => ['RESET QUERY CACHE', 'RESET'],
            'shutdown' => ['SHUTDOWN', 'SHUTDOWN'],
            // accounts
            'create user' => ["CREATE USER 'u'@'%'", 'CREATE'],
            'drop role' => ['DROP ROLE r', 'DROP'],
            'rename user' => ["RENAME USER 'a' TO 'b'", 'RENAME'],
            'grant' => ["GRANT SELECT ON db.* TO 'u'", 'GRANT'],
            'revoke' => ["REVOKE SELECT ON db.* FROM 'u'", 'REVOKE'],
            'set password' => ["SET PASSWORD = PASSWORD('x')", 'SET PASSWORD'],
            'set password for' => ["SET PASSWORD FOR 'u' = PASSWORD('x')", 'SET PASSWORD'],
            // replication
            'change master' => ["CHANGE MASTER TO MASTER_HOST = 'h'", 'CHANGE'],
            'start slave' => ['START SLAVE', 'START SLAVE'],
            'start all slaves' => ['START ALL SLAVES', 'START ALL'],
            'stop replica' => ['STOP REPLICA', 'STOP REPLICA'],
            // comments and whitespace before and between the keywords
            'whitespace' => ["  \n\tDROP TABLE t", 'DROP'],
            'block comment' => ['/* why */ CREATE TABLE t (id INT)', 'CREATE'],
            'empty block comment between' => ['CREATE/**/TABLE t (id INT)', 'CREATE'],
            'dash comment' => ["-- why\nCREATE TABLE t (id INT)", 'CREATE'],
            'dash comment at the end' => ["--\nTRUNCATE t", 'TRUNCATE'],
            'hash comment' => ["# why\nDROP TABLE t", 'DROP'],
            'executable comment' => ['/*!50100 CREATE TABLE t (id INT) */', 'CREATE'],
            'executable comment of MariaDB' => ['/*M!100100 ALTER TABLE t ADD c INT */', 'ALTER'],
            'empty executable comment first' => ['/*!*/ DROP TABLE t', 'DROP'],
            'set statement for ddl' => ['SET STATEMENT max_statement_time = 1 FOR CREATE TABLE t (id INT)', 'SET STATEMENT ... FOR CREATE'],
            'set statement, the word in a value' => ["SET STATEMENT lc_messages = 'for' FOR DROP TABLE t", 'SET STATEMENT ... FOR DROP'],
            // commit nothing
            'create temporary table' => ['CREATE TEMPORARY TABLE t (id INT)', null],
            'create temporary, a comment between' => ['CREATE /* scratch */ TEMPORARY TABLE t (id INT)', null],
            'create or replace temporary' => ['CREATE OR REPLACE TEMPORARY TABLE t (id INT)', null],
            'drop temporary table' => ['DROP TEMPORARY TABLE t', null],
            'analyze select' => ['ANALYZE SELECT 1', null],
            'analyze format' => ['ANALYZE FORMAT=JSON SELECT 1', null],
            'load data' => ["LOAD DATA INFILE 'f' INTO TABLE t", null],
            'select' => ['SELECT * FROM t', null],
            'insert' => ['INSERT INTO t (a) VALUES (?)', null],
            'update' => ['UPDATE t SET a = 1', null],
            'delete' => ['DELETE FROM t', null],
            'replace' => ['REPLACE INTO t (a) VALUES (1)', null],
            'with' => ['WITH x AS (SELECT 1) SELECT * FROM x', null],
            'parenthesis' => ['(SELECT 1) UNION (SELECT 2)', null],
            'set a variable' => ['SET @x = 1', null],
            'set a session variable' => ["SET SESSION sql_mode = 'STRICT_ALL_TABLES'", null],
            'set statement for select' => ['SET STATEMENT max_statement_time = 1 FOR SELECT 1', null],
            'empty' => ['', null],
            'only a comment' => ['/* never closed', null],
            'dashes without whitespace' => ['--1', null],
            // transaction control: the caller's (a documented limit), not refused
            'begin' => ['BEGIN', null],
            'start transaction' => ['START TRANSACTION', null],
            'start transaction read only' => ['START TRANSACTION READ ONLY', null],
            'commit' => ['COMMIT', null],
            'rollback and chain' => ['ROLLBACK AND CHAIN', null],
            'savepoint' => ['SAVEPOINT a', null],
            'set autocommit' => ['SET autocommit = 1', null],
            'set @@autocommit' => ['SET @@autocommit = 0', null],
            'xa start' => ["XA START 'x'", null],
            // what runs inside is not seen (a documented limit)
            'call' => ['CALL p()', null],
            'execute' => ['EXECUTE s', null],
            'compound statement' => ['BEGIN NOT ATOMIC CREATE TABLE t (id INT); END', null],
        ];
    }

    #[DataProvider('statements')]
    public function testTheLeadingKeywordsDecide(string $sql, ?string $expected): void
    {
        $this->assertSame($expected, ImplicitCommit::of($sql));
    }
}
