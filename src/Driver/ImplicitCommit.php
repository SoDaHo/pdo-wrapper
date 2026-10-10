<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

/**
 * Which SQL statements commit the open transaction implicitly on MariaDB, read from their leading
 * keywords - the list of the MariaDB documentation ("SQL statements that cause an implicit commit"),
 * completed by measurement on 10.11 and 12.3: DDL (ALTER, CREATE, DROP, RENAME, TRUNCATE), LOCK and
 * UNLOCK TABLES, BACKUP STAGE and BACKUP LOCK, the table maintenance statements (ANALYZE
 * [LOCAL | NO_WRITE_TO_BINLOG] TABLE or TABLES, CHECK, OPTIMIZE, REPAIR TABLE), the account statements (CREATE/DROP/RENAME USER and ROLE, GRANT, REVOKE,
 * SET PASSWORD, SET DEFAULT ROLE), INSTALL and UNINSTALL PLUGIN/SONAME, CACHE INDEX, LOAD INDEX
 * INTO CACHE, FLUSH, RESET, SHUTDOWN and the replication statements (CHANGE MASTER, START/STOP
 * SLAVE). MariaDB commits before such a statement runs, also when it then fails.
 *
 * Not on the list, although MariaDB commits for them too: the statements that steer transactions
 * themselves (BEGIN, START TRANSACTION, SET autocommit, XA) - raw transaction control is the
 * caller's (decided 2026-10-03, see the README). Not either: CREATE TEMPORARY TABLE and DROP
 * TEMPORARY TABLE, which commit nothing, SET ROLE and CHECKSUM TABLE, the ANALYZE that runs a
 * statement and reports on it (ANALYZE SELECT, WITH, VALUES, a query in parentheses, INSERT,
 * UPDATE, DELETE, REPLACE, FORMAT=...), and LOAD DATA [LOCAL] INFILE and LOAD XML - the START
 * TRANSACTION page of the documentation names LOAD DATA among the statements that commit, the
 * list of those statements does not; on InnoDB nothing is committed (all measured on 10.11 and
 * 12.3: @@in_transaction stays 1, the rows loaded and the row inserted before them are gone after
 * the ROLLBACK; pinned by tests for the LOCAL forms, LOAD DATA INFILE from the server's own disk
 * measured by hand). What a statement
 * runs inside - a stored procedure (CALL), a prepared statement (EXECUTE), a compound statement
 * (BEGIN NOT ATOMIC) - is not seen.
 *
 * The statement is split into words, quoted strings and names, and single characters; whitespace
 * and comments (`#`, `-- `, `/* *\/`) separate them. An executable comment counts as SQL, as
 * MariaDB runs it - but one with a version (`/*!50700 ...*\/`, `/*M!100100 ...*\/`) runs on some
 * servers and not on others (MariaDB ignores the MySQL versions from 5.7 on and runs its own up to
 * the server's version): each is read both ways, with its content and without, independently of
 * the others - `/*M!100100 CREATE *\/ /*!50700 TEMPORARY *\/ TABLE` is a CREATE TABLE on MariaDB -,
 * and the statement commits implicitly when one of the readings does. Without its content it is
 * skipped as MariaDB skips it: one level of comments inside it, up to the closer after them -
 * `/*!50700 /* x *\/ SELECT *\/ CREATE TABLE` is a CREATE TABLE on MariaDB (skippedEnd()). A TEMPORARY inside such a
 * comment exempts nothing, then. Only a comment that comes before the point a reading was decided
 * at is read the other way as well: one after it cannot change that reading. A statement whose
 * versioned comments before that point allow more than MAX_READINGS readings is not judged further
 * and counts as one that commits ("VERSIONED COMMENTS"): fail-closed. Strings are read with
 * backslash escapes and without (NO_BACKSLASH_ESCAPES, a setting of the session) as well. For
 * `SET STATEMENT ... FOR <statement>` the statement after the first FOR outside of strings,
 * comments and parentheses decides. Unknown statements and anything that does not start with a
 * keyword commit nothing.
 *
 * @internal The MariaDB driver's answer to AbstractDriver::implicitCommitOf()
 */
final class ImplicitCommit
{
    /** Leading keywords that commit whatever follows them */
    private const ALWAYS = [
        'ALTER', 'BACKUP', 'CACHE', 'CHANGE', 'CHECK', 'FLUSH', 'GRANT', 'INSTALL', 'LOCK', 'OPTIMIZE', 'RENAME',
        'REPAIR', 'RESET', 'REVOKE', 'SHUTDOWN', 'TRUNCATE', 'UNINSTALL', 'UNLOCK',
    ];

    /** How many keywords are read at most: enough for CREATE OR REPLACE TEMPORARY */
    private const KEYWORDS = 4;

    /**
     * How many readings of one statement are judged at most: 2^8, eight versioned comments before
     * the point it is decided at - seven when the statement holds a backslash, its strings are read
     * both ways as well. More is no statement anybody writes
     */
    private const MAX_READINGS = 256;

    /** What of() answers for a statement whose readings are too many to judge */
    public const UNJUDGED = 'VERSIONED COMMENTS';

    /**
     * The leading keywords of a statement that commits implicitly ("CREATE", "SET PASSWORD",
     * "START SLAVE"), upper case, or null for one that does not - in no reading of it.
     * UNJUDGED for a statement whose versioned comments allow more readings than are judged.
     */
    public static function of(string $sql): ?string
    {
        $readings = 0;
        foreach (str_contains($sql, '\\') ? [true, false] : [true] as $backslashes) {
            $kind = self::readingsFrom($sql, [], $backslashes, $readings);
            if ($kind !== null) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Depth first through the ways the versioned comments may run, from the reading in which they
     * run as $runs says and every one met beyond it runs: what the first reading that commits
     * implicitly commits, or null. That reading is read again with one of its comments switched
     * off - those before it as in that reading - for each comment it met, beyond $runs, before the
     * point it was decided at: a comment after that point changes only what comes after it, and the
     * reading cannot change. UNJUDGED once more than MAX_READINGS readings were read.
     *
     * @param list<bool> $runs
     * @param int $readings How many readings of the statement were read so far
     */
    private static function readingsFrom(string $sql, array $runs, bool $backslashes, int &$readings): ?string
    {
        if (++$readings > self::MAX_READINGS) {
            return self::UNJUDGED;
        }
        [$tokens, $comments] = self::tokens($sql, $runs, $backslashes);
        [$kind, $decidedBy] = self::classify($tokens);
        foreach ($comments as $comment => $tokensBefore) {
            if ($kind !== null || $tokensBefore >= $decidedBy) {
                break; // decided, or this comment and every later one come after the point it was decided at
            }
            if ($comment >= count($runs)) { // one the reading did not take from $runs: it ran
                $kind = self::readingsFrom($sql, [...$runs, ...array_fill(0, $comment - count($runs), true), false], $backslashes, $readings);
            }
        }

        return $kind;
    }

    /**
     * Classify one reading: the leading keywords, and for SET STATEMENT the statement after its FOR.
     * Also how many of the tokens decided it: the tokens after them could not change it - or one more
     * than there are, when it ran out of tokens (one more could).
     *
     * @param list<string> $tokens
     *
     * @return array{?string, int}
     */
    private static function classify(array $tokens): array
    {
        $words = [];
        $decidedBy = count($tokens) + 1;
        foreach ($tokens as $at => $token) {
            if (count($words) === self::KEYWORDS || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $token) !== 1) {
                $decidedBy = $at + 1;

                break;
            }
            $words[] = strtoupper($token);
        }
        if ($words === []) {
            return [null, $decidedBy];
        }
        [$first, $second, $third, $fourth] = array_pad($words, self::KEYWORDS, '');
        if ($first === 'SET' && $second === 'STATEMENT') {
            [$kind, $forDecidedBy] = self::statementAfterFor($tokens);

            return [$kind, max($decidedBy, $forDecidedBy)];
        }

        return [match (true) {
            in_array($first, self::ALWAYS, true) => $first,
            // CREATE TEMPORARY TABLE and DROP TEMPORARY TABLE commit nothing; every other CREATE and DROP does
            $first === 'CREATE' => $second === 'TEMPORARY' || ($second === 'OR' && $third === 'REPLACE' && $fourth === 'TEMPORARY') ? null : 'CREATE',
            $first === 'DROP' => $second === 'TEMPORARY' ? null : 'DROP',
            // ANALYZE [LOCAL | NO_WRITE_TO_BINLOG] TABLE[S] maintains a table and commits; every other ANALYZE
            // runs a statement and reports on it (SELECT, WITH, VALUES, a query in parentheses, ...): nothing
            $first === 'ANALYZE' => in_array($second, ['TABLE', 'TABLES'], true)
                || (in_array($second, ['LOCAL', 'NO_WRITE_TO_BINLOG'], true) && in_array($third, ['TABLE', 'TABLES'], true)) ? 'ANALYZE' : null,
            $first === 'LOAD' => $second === 'INDEX' ? 'LOAD INDEX' : null,
            ($first === 'START' || $first === 'STOP') && in_array($second, ['SLAVE', 'REPLICA', 'ALL'], true) => $first . ' ' . $second,
            $first === 'SET' && $second === 'PASSWORD' => 'SET PASSWORD',
            $first === 'SET' && $second === 'DEFAULT' && $third === 'ROLE' => 'SET DEFAULT ROLE',
            default => null,
        }, $decidedBy];
    }

    /**
     * For SET STATEMENT ... FOR <statement>: what the statement after the first FOR commits - the
     * first FOR word token outside of parentheses (strings and comments are no words here) -, and
     * how many of the tokens decided it (see classify()).
     *
     * @param list<string> $tokens The whole statement, SET STATEMENT first
     *
     * @return array{?string, int}
     */
    private static function statementAfterFor(array $tokens): array
    {
        $depth = 0;
        foreach (array_slice($tokens, 2) as $offset => $token) {
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && strtoupper($token) === 'FOR') {
                $after = $offset + 3; // SET, STATEMENT, the tokens up to FOR, FOR itself
                [$inner, $decidedBy] = self::classify(array_slice($tokens, $after));

                return [$inner === null ? null : 'SET STATEMENT ... FOR ' . $inner, $after + $decidedBy];
            }
        }

        return [null, count($tokens) + 1];
    }

    /**
     * Split the statement into tokens: words (letters, digits, `_`, `$`, any byte from 0x80 on), a
     * quoted string or name as one token, every other character as one. Whitespace and comments
     * separate tokens and leave none. An executable comment's opener and closer leave none either:
     * its content is read on - unless it has a version and $runs says it does not run, then the
     * whole comment is dropped, as far as the server skips it (skippedEnd()). A comment or string
     * that is never closed runs to the end.
     *
     * @param list<bool> $runs Whether each versioned comment runs, in the order they are met; one met beyond the list runs
     *
     * @return array{list<string>, list<int>} The tokens, and for each versioned comment met how many tokens came before it
     */
    private static function tokens(string $sql, array $runs, bool $backslashes): array
    {
        $tokens = [];
        $comments = [];
        $length = strlen($sql);
        $inExecutable = false;
        $at = 0;
        while ($at < $length) {
            $char = $sql[$at];
            if (ctype_space($char)) {
                $at++;
            } elseif ($char === '#' || ($char === '-' && substr($sql, $at, 2) === '--' && ($at + 2 === $length || ctype_space($sql[$at + 2]) || ctype_cntrl($sql[$at + 2])))) {
                $end = strpos($sql, "\n", $at);
                $at = $end === false ? $length : $end + 1;
            } elseif (preg_match('/\G\/\*(M?)!(\d*)/', $sql, $match, 0, $at) === 1) {
                $skipped = false;
                if ($match[2] !== '') {
                    $skipped = !($runs[count($comments)] ?? true);
                    $comments[] = count($tokens);
                }
                if ($skipped) {
                    $at = self::skippedEnd($sql, $at + strlen($match[0])); // the server does not run it: a comment
                } else {
                    $inExecutable = true;
                    $at += strlen($match[0]);
                }
            } elseif ($char === '*' && $inExecutable && substr($sql, $at, 2) === '*/') {
                $inExecutable = false;
                $at += 2;
            } elseif ($char === '/' && substr($sql, $at, 2) === '/*') {
                $close = strpos($sql, '*/', $at + 2);
                $at = $close === false ? $length : $close + 2;
            } elseif ($char === "'" || $char === '"' || $char === '`') {
                $end = self::quoteEnd($sql, $at, $backslashes && $char !== '`');
                $tokens[] = substr($sql, $at, $end - $at);
                $at = $end;
            } elseif (preg_match('/\G[A-Za-z0-9_$\x80-\xff]+/', $sql, $match, 0, $at) === 1) {
                $tokens[] = $match[0];
                $at += strlen($match[0]);
            } else {
                $tokens[] = $char;
                $at++;
            }
        }

        return [$tokens, $comments];
    }

    /**
     * Where a versioned comment the server does not run ends - the offset after its closer, or the
     * end -, its content starting at $from. MariaDB skips such a comment with one level of comments
     * inside it (consume_comment(1) in sql_lex.cc, the same in 10.11, 11.4 and 12.3): a `/*` that
     * comes before the next closer - a versioned one too - opens a comment that ends at its own first
     * closer, and the next closer outside of those ends the skipped one. One level only: inside such
     * an inner comment a `/*` is text. Ended at its first closer instead, a skipped comment let
     * `/*!50700 /* x *\/ SELECT *\/ CREATE TABLE` be read as a SELECT, while MariaDB skips up to the
     * second closer and runs the CREATE TABLE.
     */
    private static function skippedEnd(string $sql, int $from): int
    {
        $at = $from;
        while (true) {
            $close = strpos($sql, '*/', $at);
            if ($close === false) {
                return strlen($sql);
            }
            $open = strpos($sql, '/*', $at);
            if ($open === false || $close < $open) {
                return $close + 2;
            }
            // A comment inside: up to its own first closer - searched from after its `/*`, so that `/*/` does not close it
            $inner = strpos($sql, '*/', $open + 2);
            if ($inner === false) {
                return strlen($sql);
            }
            $at = $inner + 2;
        }
    }

    /**
     * Where a quoted string or name that opens at $at ends (the offset after its closing quote, or
     * the end): a doubled quote is one quote of the content, and a backslash escapes the next
     * character where $backslashes says so.
     */
    private static function quoteEnd(string $sql, int $at, bool $backslashes): int
    {
        $quote = $sql[$at];
        $length = strlen($sql);
        for ($i = $at + 1; $i < $length; $i++) {
            if ($backslashes && $sql[$i] === '\\') {
                $i++;
            } elseif ($sql[$i] === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $i++;
                } else {
                    return $i + 1;
                }
            }
        }

        return $length;
    }
}
