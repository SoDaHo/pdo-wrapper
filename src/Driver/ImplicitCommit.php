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
 * and comments (`#`, `-- `, `/* *\/` up to its first closer) separate them. An executable comment
 * (`/*!...*\/`, `/*M!...*\/`, with a version or without) is not read at all: where one stands before
 * the leading keywords are decided - before the first keyword, between the keywords that decide
 * (`CREATE /*!50700 TEMPORARY *\/ TABLE`, `ANALYZE /*M!100100 TABLE *\/ t`), before the statement
 * after the FOR of SET STATEMENT -, the statement is not judged and counts as one that commits
 * (UNJUDGED): fail-closed. Such comments are what mysqldump writes, no application builds its
 * statements with them; which of them MariaDB runs depends on its version and on how its lexer
 * nests comments, and rebuilding that grammar here was attack surface without a use (decided
 * 2026-10-10, after the reviews of three candidates in a row turned on its readings). One
 * after that point cannot change the leading keywords and is left alone (`SELECT 1 /*!50700 , 2 *\/`,
 * `SELECT /*!40001 SQL_NO_CACHE *\/ ...`). Strings are read with backslash escapes and without
 * (NO_BACKSLASH_ESCAPES, a setting of the session): a statement is refused when one of the two
 * readings commits. For `SET STATEMENT ... FOR <statement>` the statement after the first FOR
 * outside of strings, comments and parentheses decides. Unknown statements and anything that does
 * not start with a keyword commit nothing.
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

    /** What of() answers for a statement with an executable comment before its leading keywords are decided */
    public const UNJUDGED = 'VERSIONED COMMENTS';

    /**
     * The leading keywords of a statement that commits implicitly ("CREATE", "SET PASSWORD",
     * "START SLAVE"), upper case, or null for one that does not - with backslash escapes in its
     * strings and without. UNJUDGED for a statement with an executable comment before its leading
     * keywords are decided.
     */
    public static function of(string $sql): ?string
    {
        foreach (str_contains($sql, '\\') ? [true, false] : [true] as $backslashes) {
            [$tokens, $executableComment] = self::tokens($sql, $backslashes);
            [$kind, $read] = self::classify($tokens);
            // The answer depends on a token after the last one read: the comment, or what comes after it
            if ($executableComment && $read > count($tokens)) {
                return self::UNJUDGED;
            }
            if ($kind !== null) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Classify the statement: the leading keywords, and for SET STATEMENT the statement after its
     * FOR. Also how many of the tokens the answer depends on - one more than there are when it
     * looked past the last one (where a further token could change it).
     *
     * @param list<string> $tokens
     *
     * @return array{?string, int}
     */
    private static function classify(array $tokens): array
    {
        $read = 0;
        $first = self::word($tokens, 0, $read);
        if ($first === 'SET' && self::word($tokens, 1, $read) === 'STATEMENT') {
            return self::statementAfterFor($tokens);
        }
        // Each arm reads only the keywords its answer depends on: SELECT is decided by its first word
        $kind = match (true) {
            in_array($first, self::ALWAYS, true) => $first,
            // CREATE [OR REPLACE] TEMPORARY TABLE and DROP TEMPORARY TABLE commit nothing; every other CREATE and DROP does
            $first === 'CREATE' => self::createsATemporaryTable($tokens, $read) ? null : 'CREATE',
            $first === 'DROP' => self::word($tokens, 1, $read) === 'TEMPORARY' ? null : 'DROP',
            // ANALYZE [LOCAL | NO_WRITE_TO_BINLOG] TABLE[S] maintains a table and commits; every other ANALYZE
            // runs a statement and reports on it (SELECT, WITH, VALUES, a query in parentheses, ...): nothing
            $first === 'ANALYZE' => self::analyzesATable($tokens, $read) ? 'ANALYZE' : null,
            $first === 'LOAD' => self::word($tokens, 1, $read) === 'INDEX' ? 'LOAD INDEX' : null,
            $first === 'START', $first === 'STOP' => self::replication($first, $tokens, $read),
            $first === 'SET' => match (self::word($tokens, 1, $read)) {
                'PASSWORD' => 'SET PASSWORD',
                'DEFAULT' => self::word($tokens, 2, $read) === 'ROLE' ? 'SET DEFAULT ROLE' : null,
                default => null,
            },
            default => null,
        };

        return [$kind, $read];
    }

    /**
     * The token at $at as a keyword, upper case - or '' for one that is no word (a string, a
     * character) and for one beyond the last. $read counts the tokens read so far, that one included.
     *
     * @param list<string> $tokens
     */
    private static function word(array $tokens, int $at, int &$read): string
    {
        $read = max($read, $at + 1);
        $token = $tokens[$at] ?? '';

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $token) === 1 ? strtoupper($token) : '';
    }

    /**
     * Whether a CREATE creates a temporary table: CREATE TEMPORARY, CREATE OR REPLACE TEMPORARY.
     *
     * @param list<string> $tokens
     */
    private static function createsATemporaryTable(array $tokens, int &$read): bool
    {
        $second = self::word($tokens, 1, $read);

        return $second === 'TEMPORARY'
            || ($second === 'OR' && self::word($tokens, 2, $read) === 'REPLACE' && self::word($tokens, 3, $read) === 'TEMPORARY');
    }

    /**
     * Whether an ANALYZE maintains a table: ANALYZE [LOCAL | NO_WRITE_TO_BINLOG] TABLE or TABLES.
     *
     * @param list<string> $tokens
     */
    private static function analyzesATable(array $tokens, int &$read): bool
    {
        $second = self::word($tokens, 1, $read);
        if (in_array($second, ['TABLE', 'TABLES'], true)) {
            return true;
        }

        return in_array($second, ['LOCAL', 'NO_WRITE_TO_BINLOG'], true) && in_array(self::word($tokens, 2, $read), ['TABLE', 'TABLES'], true);
    }

    /**
     * START or STOP of the replication (SLAVE, REPLICA, ALL SLAVES) - "START SLAVE" -, or null: START
     * TRANSACTION is transaction control, the caller's.
     *
     * @param list<string> $tokens
     */
    private static function replication(string $first, array $tokens, int &$read): ?string
    {
        $second = self::word($tokens, 1, $read);

        return in_array($second, ['SLAVE', 'REPLICA', 'ALL'], true) ? $first . ' ' . $second : null;
    }

    /**
     * For SET STATEMENT ... FOR <statement>: what the statement after the first FOR commits - the
     * first FOR word token outside of parentheses (strings and comments are no words here) -, and
     * how many of the tokens the answer depends on (see classify()).
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
                [$inner, $read] = self::classify(array_slice($tokens, $after));

                return [$inner === null ? null : 'SET STATEMENT ... FOR ' . $inner, $after + $read];
            }
        }

        return [null, count($tokens) + 1];
    }

    /**
     * Split the statement into tokens, up to its first executable comment: words (letters, digits,
     * `_`, `$`, any byte from 0x80 on), a quoted string or name as one token, every other character
     * as one. Whitespace and comments separate tokens and leave none; a block comment ends at its
     * first closer, as MariaDB ends one (a `/*!` inside it is text). A comment or string that is
     * never closed runs to the end.
     *
     * @return array{list<string>, bool} The tokens, and whether an executable comment stopped the split
     */
    private static function tokens(string $sql, bool $backslashes): array
    {
        $tokens = [];
        $length = strlen($sql);
        $at = 0;
        while ($at < $length) {
            $char = $sql[$at];
            if (ctype_space($char)) {
                $at++;
            } elseif ($char === '#' || ($char === '-' && substr($sql, $at, 2) === '--' && ($at + 2 === $length || ctype_space($sql[$at + 2]) || ctype_cntrl($sql[$at + 2])))) {
                $end = strpos($sql, "\n", $at);
                $at = $end === false ? $length : $end + 1;
            } elseif (preg_match('/\G\/\*M?!/', $sql, $match, 0, $at) === 1) {
                return [$tokens, true]; // what MariaDB runs of it is not judged here
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

        return [$tokens, false];
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
