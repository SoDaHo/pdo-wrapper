<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Driver\ImplicitCommit;

/**
 * Which statements the MariaDB driver takes for ones that commit implicitly, without a database:
 * the list of the MariaDB documentation and what was measured besides, read from the leading
 * keywords past whitespace and comments - an executable comment before they are decided is not
 * judged (UNJUDGED) -, not the statements that steer transactions themselves, not the TEMPORARY
 * tables, not what a procedure or a compound statement runs inside.
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
            // MariaDB exempts only the temporary table: a temporary sequence commits (measured on 10.11, 11.4 and 12.3)
            'create temporary sequence' => ['CREATE TEMPORARY SEQUENCE s', 'CREATE'],
            'create or replace temporary sequence' => ['CREATE OR REPLACE TEMPORARY SEQUENCE s', 'CREATE'],
            'create temporary sequence, lower case' => ['create temporary sequence s', 'CREATE'],
            'create temporary, a comment before the object' => ['CREATE TEMPORARY /* scratch */ SEQUENCE s', 'CREATE'],
            'create temporary, no object word' => ['CREATE TEMPORARY', 'CREATE'],
            'create temporary, the object word quoted' => ['CREATE TEMPORARY `TABLE` t (id INT)', 'CREATE'],
            'set statement for create temporary sequence' => ['SET STATEMENT a = 1 FOR CREATE TEMPORARY SEQUENCE s', 'SET STATEMENT ... FOR CREATE'],
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
            'analyze tables' => ['ANALYZE TABLES t', 'ANALYZE'],
            'analyze no_write_to_binlog tables' => ['ANALYZE NO_WRITE_TO_BINLOG TABLES t', 'ANALYZE'],
            'analyze table, lower case' => ['analyze table t', 'ANALYZE'],
            'check table' => ['CHECK TABLE t', 'CHECK'],
            'optimize table' => ['OPTIMIZE TABLE t', 'OPTIMIZE'],
            'repair table' => ['REPAIR TABLE t', 'REPAIR'],
            'cache index' => ['CACHE INDEX t IN hot', 'CACHE'],
            'load index' => ['LOAD INDEX INTO CACHE t', 'LOAD INDEX'],
            'flush' => ['FLUSH PRIVILEGES', 'FLUSH'],
            'reset' => ['RESET QUERY CACHE', 'RESET'],
            'shutdown' => ['SHUTDOWN', 'SHUTDOWN'],
            // measured on 10.11 and 12.3: @@in_transaction 0 afterwards, the row before survives a ROLLBACK
            'backup stage' => ['BACKUP STAGE START', 'BACKUP'],
            'backup lock' => ['BACKUP LOCK t', 'BACKUP'],
            'install plugin' => ["INSTALL PLUGIN p SONAME 'p'", 'INSTALL'],
            'install soname' => ["INSTALL SONAME 'p'", 'INSTALL'],
            'uninstall plugin' => ['UNINSTALL PLUGIN p', 'UNINSTALL'],
            'uninstall soname' => ["UNINSTALL SONAME 'p'", 'UNINSTALL'],
            'set default role' => ['SET DEFAULT ROLE NONE', 'SET DEFAULT ROLE'],
            'set default role for' => ["SET DEFAULT ROLE r FOR 'u'", 'SET DEFAULT ROLE'],
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
            'dash comment, a control character after the dashes' => ["--\x01 why\nDROP TABLE t", 'DROP'],
            'dash comment at the very end' => ['DROP TABLE t --', 'DROP'],
            'versioned comment never closed' => ['/*!50700 DROP TABLE t', ImplicitCommit::UNJUDGED],
            'executable comment with a comment inside' => ['/*!50100 CREATE /* x */ TABLE t (id INT) */', ImplicitCommit::UNJUDGED],
            'set statement, a closing parenthesis too many' => ['SET STATEMENT x = 1) FOR DROP TABLE t', 'SET STATEMENT ... FOR DROP'],
            'set statement, a backslash in a quoted name' => ['SET STATEMENT x = `a\\` FOR DROP TABLE t', 'SET STATEMENT ... FOR DROP'],
            'executable comment' => ['/*!50100 CREATE TABLE t (id INT) */', ImplicitCommit::UNJUDGED],
            'executable comment of MariaDB' => ['/*M!100100 ALTER TABLE t ADD c INT */', ImplicitCommit::UNJUDGED],
            'empty executable comment first' => ['/*!*/ DROP TABLE t', ImplicitCommit::UNJUDGED],
            'set statement for ddl' => ['SET STATEMENT max_statement_time = 1 FOR CREATE TABLE t (id INT)', 'SET STATEMENT ... FOR CREATE'],
            'set statement, the word in a value' => ["SET STATEMENT lc_messages = 'for' FOR DROP TABLE t", 'SET STATEMENT ... FOR DROP'],
            'set statement, a comment before the statement' => ['SET STATEMENT max_statement_time = 1 FOR /* why */ DROP TABLE t', 'SET STATEMENT ... FOR DROP'],
            // an executable comment - with a version or without - before the leading keywords are decided is not
            // judged: refused unjudged, whatever MariaDB runs of it (that depends on its version and on how its
            // lexer nests comments: the mixed and the nested comments below create a base table, measured)
            'versioned comment first' => ['/*!50700 DROP TABLE t */', ImplicitCommit::UNJUDGED],
            'versioned comment of MariaDB first' => ['/*M!100100 CREATE TABLE t (id INT) */', ImplicitCommit::UNJUDGED],
            'versioned comment after a block comment' => ['/* why */ /*!50700 SELECT */ DROP TABLE t', ImplicitCommit::UNJUDGED],
            'versioned comment after a hash and a dash comment' => ["# lead\n-- lead\n/*!50700 SELECT */ DROP TABLE t", ImplicitCommit::UNJUDGED],
            'versioned temporary, MySQL version' => ['CREATE /*!50700 TEMPORARY */ TABLE t (id INT)', ImplicitCommit::UNJUDGED],
            'versioned temporary, old version' => ['CREATE /*!40000 TEMPORARY */ TABLE t (id INT)', ImplicitCommit::UNJUDGED],
            'versioned temporary of MariaDB' => ['CREATE /*M!100100 TEMPORARY */ TABLE t (id INT)', ImplicitCommit::UNJUDGED],
            'unversioned temporary' => ['CREATE /*!TEMPORARY */ TABLE t (id INT)', ImplicitCommit::UNJUDGED],
            'unversioned temporary of MariaDB' => ['CREATE /*M! TEMPORARY */ TABLE t (id INT)', ImplicitCommit::UNJUDGED],
            'versioned drop temporary' => ['DROP /*!50700 TEMPORARY */ TABLE t', ImplicitCommit::UNJUDGED],
            'versioned or replace temporary' => ['CREATE OR REPLACE /*!50700 TEMPORARY */ TABLE t (id INT)', ImplicitCommit::UNJUDGED],
            'versioned sequence after temporary' => ['CREATE TEMPORARY /*M!100100 SEQUENCE */ s', ImplicitCommit::UNJUDGED],
            'versioned table after temporary' => ['CREATE TEMPORARY /*!50700 TABLE */ t (id INT)', ImplicitCommit::UNJUDGED],
            'versioned sequence after or replace temporary' => ['CREATE OR REPLACE TEMPORARY /*!50700 SEQUENCE */ s', ImplicitCommit::UNJUDGED],
            'versioned comment between create and or' => ['CREATE /*!50700 OR */ REPLACE TABLE t (id INT)', ImplicitCommit::UNJUDGED],
            'versioned analyze table' => ['ANALYZE /*M!100100 TABLE */ t', ImplicitCommit::UNJUDGED],
            'versioned analyze local table' => ['ANALYZE LOCAL /*!50700 TABLE */ t', ImplicitCommit::UNJUDGED],
            'versioned load index' => ['LOAD /*M!100100 INDEX */ INTO CACHE t', ImplicitCommit::UNJUDGED],
            'versioned start slave' => ['START /*!50700 SLAVE */', ImplicitCommit::UNJUDGED],
            'versioned set password' => ["SET /*M!100100 PASSWORD */ = PASSWORD('x')", ImplicitCommit::UNJUDGED],
            'versioned set default role' => ['SET DEFAULT /*!50700 ROLE */ NONE', ImplicitCommit::UNJUDGED],
            'versioned set statement' => ['SET /*!50700 STATEMENT */ a = 1 FOR DROP TABLE t', ImplicitCommit::UNJUDGED],
            'mixed versioned comments' => ['/*M!100100 CREATE */ /*!50700 TEMPORARY */ TABLE t (id INT)', ImplicitCommit::UNJUDGED],
            'a comment inside a versioned comment' => ['/*!50700 /* nested */ SELECT */ CREATE TABLE t (id INT)', ImplicitCommit::UNJUDGED],
            'a versioned comment inside an executable one' => ['/*M!100100 CREATE /*!50700 /* a */ TEMPORARY */ TABLE t (id INT) */', ImplicitCommit::UNJUDGED],
            'a versioned comment before the for' => ['SET STATEMENT a = 1 /*!50700 , b = 2 */ FOR SELECT 1', ImplicitCommit::UNJUDGED],
            'a versioned comment around the for' => ['SET STATEMENT a = 1 /*M!100100 FOR DROP */ TABLE t', ImplicitCommit::UNJUDGED],
            'a versioned comment right after the for' => ['SET STATEMENT a = 1 FOR /*!50700 CREATE TABLE t (id INT) */', ImplicitCommit::UNJUDGED],
            'a versioned comment never closed' => ['/*!50700 /* x SELECT 1', ImplicitCommit::UNJUDGED],
            'only a versioned comment' => ['/*!50700 */', ImplicitCommit::UNJUDGED],
            // one after that point cannot change the leading keywords: left alone, the statement judged by them
            'versioned comment after a select' => ['SELECT 1 /*!50700 , 2 */', null],
            'versioned hint after select' => ['SELECT /*!40001 SQL_NO_CACHE */ * FROM t', null],
            'versioned comment after insert' => ['INSERT /*!50700 IGNORE */ INTO t (a) VALUES (1)', null],
            'a comment inside a versioned comment after the decision' => ['SELECT 1 /*!50700 /* x */ , 2 */', null],
            'many versioned comments after the decision' => ['SELECT 1' . str_repeat(' /*!50700 , 2 */', 40), null],
            'many versioned comments after a temporary table' => ['CREATE TEMPORARY TABLE t (id INT' . str_repeat(' /*!50700 , c INT */', 40) . ')', null],
            'versioned comment after create table' => ['CREATE TABLE t (id INT) /*!50100 ENGINE = InnoDB */', 'CREATE'],
            'versioned comment after drop table' => ['DROP TABLE /*!40000 IF EXISTS */ t', 'DROP'],
            'versioned comment after create or replace table' => ['CREATE OR REPLACE TABLE /*!50700 t */ (id INT)', 'CREATE'],
            'versioned comment after alter' => ['ALTER /*!50700 ONLINE */ TABLE t ADD c INT', 'ALTER'],
            'versioned comment after analyze select' => ['ANALYZE SELECT /*!50700 1 */', null],
            'versioned comment after set a variable' => ['SET @x = /*!50700 1 */ 2', null],
            'versioned comment after the statement after the for' => ['SET STATEMENT a = 1 FOR SELECT 1 /*!50700 , 2 */', null],
            'versioned comment after ddl after the for' => ['SET STATEMENT a = 1 FOR DROP TABLE /*!40000 IF EXISTS */ t', 'SET STATEMENT ... FOR DROP'],
            'versioned comment between the keywords after the for' => ['SET STATEMENT a = 1 FOR DROP /*!50700 TEMPORARY */ TABLE t', ImplicitCommit::UNJUDGED],
            // not executable: a /*! inside a block comment, a string or a name is text; /*M without ! is a block comment
            'a versioned comment inside a block comment' => ['/* see /*!50700 */ CREATE TABLE t (id INT)', 'CREATE'],
            'a versioned comment inside a string' => ["SELECT '/*!50700 x */' FROM t", null],
            'a block comment that starts with M' => ['/*M CREATE */ DROP TABLE t', 'DROP'],
            // the backslash readings stay: a /*! in a string with backslash escapes is code without them
            'a versioned comment after a backslash ending the string' => ["SET STATEMENT a = '\\' /*!50700 FOR DROP TABLE t */ '", ImplicitCommit::UNJUDGED],
            // strings without backslash escapes (NO_BACKSLASH_ESCAPES): the FOR after the string counts too
            'set statement, a backslash ending the string' => ["SET STATEMENT sql_mode = '\\' FOR DROP TABLE t -- '", 'SET STATEMENT ... FOR DROP'],
            // and the price of that: a backslash-escaped quote reads as the end of the string as well (fail-closed)
            'set statement, a backslash-escaped quote' => ["SET STATEMENT lc_messages = 'it\\'s FOR DROP' FOR SELECT 1", 'SET STATEMENT ... FOR DROP'],
            // commit nothing
            'create temporary table' => ['CREATE TEMPORARY TABLE t (id INT)', null],
            'create temporary, a comment between' => ['CREATE /* scratch */ TEMPORARY TABLE t (id INT)', null],
            'create or replace temporary' => ['CREATE OR REPLACE TEMPORARY TABLE t (id INT)', null],
            'set role' => ['SET ROLE r', null],
            'checksum table' => ['CHECKSUM TABLE t', null],
            'drop temporary table' => ['DROP TEMPORARY TABLE t', null],
            // unlike its CREATE: MariaDB skips the commit for every temporary DROP (measured on 10.11, 11.4 and 12.3)
            'drop temporary sequence' => ['DROP TEMPORARY SEQUENCE s', null],
            'analyze select' => ['ANALYZE SELECT 1', null],
            'analyze format' => ['ANALYZE FORMAT=JSON SELECT 1', null],
            // measured on 10.11 and 12.3: @@in_transaction stays 1, the row before is gone after a ROLLBACK
            'analyze with' => ['ANALYZE WITH c AS (SELECT 1) SELECT * FROM c', null],
            'analyze values' => ['ANALYZE VALUES (1)', null],
            'analyze a query in parentheses' => ['ANALYZE (SELECT 1)', null],
            'analyze update' => ['ANALYZE UPDATE t SET a = 1', null],
            'analyze delete' => ['ANALYZE DELETE FROM t', null],
            'analyze, nothing after it' => ['ANALYZE', null],
            'load data' => ["LOAD DATA INFILE 'f' INTO TABLE t", null],
            'load data local' => ["LOAD DATA LOCAL INFILE 'f' INTO TABLE t", null],
            'load xml local' => ["LOAD XML LOCAL INFILE 'f' INTO TABLE t", null],
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
            'set statement, ddl in a comment before the for' => ['SET STATEMENT max_statement_time = 1 /* FOR DROP TABLE x */ FOR SELECT 1', null],
            'set statement, ddl in a string before the for' => ["SET STATEMENT lc_messages = 'x FOR DROP TABLE t' FOR SELECT 1", null],
            'set statement, ddl in a dash comment' => ["SET STATEMENT max_statement_time = 1 -- FOR DROP TABLE x\nFOR SELECT 1", null],
            'set statement, the for inside parentheses' => ['SET STATEMENT x = (SELECT a FOR DROP) FOR SELECT 1', null],
            'set statement, a string never closed' => ["SET STATEMENT x = 'abc FOR DROP TABLE t", null],
            'set statement without a for' => ['SET STATEMENT max_statement_time = 1', null],
            'set statement, a doubled quote in the string' => ["SET STATEMENT lc_messages = 'it''s FOR DROP' FOR SELECT 1", null],
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
