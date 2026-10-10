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
 * UPDATE, DELETE, REPLACE, FORMAT=...; measured on 10.11 and 12.3: @@in_transaction stays 1, the
 * row inserted before is gone after the ROLLBACK). What a statement
 * runs inside - a stored procedure (CALL), a prepared statement (EXECUTE), a compound statement
 * (BEGIN NOT ATOMIC) - is not seen.
 *
 * The statement is split into words, quoted strings and names, and single characters; whitespace
 * and comments (`#`, `-- `, `/* *\/`) separate them. An executable comment counts as SQL, as
 * MariaDB runs it - but one with a version (`/*!50700 ...*\/`, `/*M!100100 ...*\/`) runs on some
 * servers and not on others (MariaDB ignores the MySQL versions from 5.7 on): it is read both
 * ways, with its content and without, and the statement commits implicitly when one of the
 * readings does. A TEMPORARY inside such a comment exempts nothing, then. Strings are read with
 * backslash escapes and without (NO_BACKSLASH_ESCAPES) as well. For `SET STATEMENT ... FOR
 * <statement>` the statement after the first FOR outside of strings, comments and parentheses
 * decides. Unknown statements and anything that does not start with a keyword commit nothing.
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
     * The leading keywords of a statement that commits implicitly ("CREATE", "SET PASSWORD",
     * "START SLAVE"), upper case, or null for one that does not - in no reading of it.
     */
    public static function of(string $sql): ?string
    {
        foreach (self::readings($sql) as $tokens) {
            $kind = self::classify($tokens);
            if ($kind !== null) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The token lists the statement may be: versioned executable comments with their content and
     * without, strings with backslash escapes and without. The second of each is read only where
     * the statement could differ (it holds a comment opener or a backslash).
     *
     * @return list<list<string>>
     */
    private static function readings(string $sql): array
    {
        $readings = [];
        foreach (str_contains($sql, '/*') ? [true, false] : [true] as $versioned) {
            foreach (str_contains($sql, '\\') ? [true, false] : [true] as $backslashes) {
                $readings[] = self::tokens($sql, $versioned, $backslashes);
            }
        }

        return $readings;
    }

    /**
     * Classify one reading: the leading keywords, and for SET STATEMENT the statement after its FOR.
     *
     * @param list<string> $tokens
     */
    private static function classify(array $tokens): ?string
    {
        $words = [];
        foreach ($tokens as $token) {
            if (count($words) === self::KEYWORDS || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $token) !== 1) {
                break;
            }
            $words[] = strtoupper($token);
        }
        if ($words === []) {
            return null;
        }
        [$first, $second, $third, $fourth] = array_pad($words, self::KEYWORDS, '');

        return match (true) {
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
            $first === 'SET' && $second === 'STATEMENT' => self::statementAfterFor(array_slice($tokens, 2)),
            default => null,
        };
    }

    /**
     * For SET STATEMENT ... FOR <statement>: what the statement after the first FOR commits - the
     * first FOR word token outside of parentheses (strings and comments are no words here).
     *
     * @param list<string> $tokens What follows SET STATEMENT
     */
    private static function statementAfterFor(array $tokens): ?string
    {
        $depth = 0;
        foreach ($tokens as $at => $token) {
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && strtoupper($token) === 'FOR') {
                $inner = self::classify(array_slice($tokens, $at + 1));

                return $inner === null ? null : 'SET STATEMENT ... FOR ' . $inner;
            }
        }

        return null;
    }

    /**
     * Split the statement into tokens: words (letters, digits, `_`, `$`, any byte from 0x80 on), a
     * quoted string or name as one token, every other character as one. Whitespace and comments
     * separate tokens and leave none. An executable comment's opener and closer leave none either:
     * its content is read on - unless it has a version and $versioned is false, then the whole
     * comment is dropped. A comment or string that is never closed runs to the end.
     *
     * @return list<string>
     */
    private static function tokens(string $sql, bool $versioned, bool $backslashes): array
    {
        $tokens = [];
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
                $close = strpos($sql, '*/', $at + strlen($match[0]));
                if ($match[2] !== '' && !$versioned) {
                    $at = $close === false ? $length : $close + 2; // the server does not run it: a comment
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

        return $tokens;
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
