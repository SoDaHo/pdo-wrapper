<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support\Binding;

use Closure;
use PDO;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\AbstractDriver;

/**
 * What the contract tests (tests/Contract) know of a database: how to connect to the test
 * database, and how to write its tables. A driver of this library provides one binding and
 * passes tests/Contract with it; nothing in those tests names an engine or writes DDL itself.
 *
 * Columns are given as a type word, optionally followed by standard SQL that every engine reads
 * the same way (`NOT NULL`, `UNIQUE`, `DEFAULT 1`, `REFERENCES users(id)`):
 * - `id`: an integer primary key the database numbers itself (64 bit)
 * - `key`: an integer primary key the caller numbers (64 bit)
 * - `int`, `bigint`: integers of 32 and 64 bit
 * - `text`: a string of up to 255 characters, compared as written (case and accents count)
 * - `decimal`: an exact number with 4 decimal places
 * - `double`: a floating point number
 * - `blob`: bytes
 * - `timestamp`: a point in time (with `DEFAULT CURRENT_TIMESTAMP` where the row's creation counts)
 *
 * An entry without a name is a table constraint in standard SQL (`PRIMARY KEY (a, b)`,
 * `FOREIGN KEY (user_id) REFERENCES users(id)`). A column that references an `id` is a `bigint`.
 */
interface DriverBinding
{
    /**
     * A driver on the test database, created through the factory a consumer calls.
     *
     * @param array{pdoClass?: class-string<PDO>, options?: array<int, mixed>} $extra
     */
    public function connect(array $extra = []): AbstractDriver;

    /**
     * A PDO object on the test database with the settings the driver gives its own, for tests
     * that build a driver around a PDO object themselves.
     *
     * @param class-string<PDO> $class
     */
    public function pdo(string $class = PDO::class): PDO;

    /**
     * Create the table, after dropping one of that name.
     *
     * @param array<int|string, string> $columns Column name => type word plus standard SQL; a table constraint without a name
     */
    public function create(DatabaseInterface $db, string $table, array $columns): void;

    public function drop(DatabaseInterface $db, string $table): void;

    /**
     * The SQLSTATE and the driver's error number of a statement on a table that does not exist,
     * of a duplicate key, and of a deadlock (what a retry of the transaction looks for).
     *
     * @return array{unknownTable: array{string, int}, duplicate: array{string, int}, deadlock: array{string, int}}
     */
    public function failureCodes(): array;

    /**
     * What sum() and avg() deliver for 1 + 2 in an `int` column, twice 2^53 + 1 in a `bigint`
     * column, 0.1 + 0.2 in a `decimal` column and 0.5 + 0.25 in a `double` column.
     *
     * @return array<string, array{int|float|string, int|float|string}> [sum, avg] by column: small, big, price, ratio
     */
    public function deliveredAggregates(): array;

    /**
     * What a SUM() over an `int` column delivers - through sum() or as a column of a row - when its
     * values add up to $sum: the integer, or the string or float the driver makes of it.
     */
    public function deliveredIntSum(int $sum): int|float|string;

    /**
     * What an AVG() over an `int` column delivers - through avg() or as a column of a row - when its
     * values average $average (a value a float holds exactly): the float, or the string the driver
     * makes of it, with the database's number of decimals.
     */
    public function deliveredIntAvg(float $average): int|float|string;

    /**
     * The name a duplicate key reports for a column declared `UNIQUE` (email) and for the primary key.
     *
     * @return array{email: string, primary: string}
     */
    public function uniqueConstraintNames(): array;

    /**
     * Whether a later assignment of an UPDATE sees the value an earlier one of the same statement
     * set (MariaDB evaluates SET left to right) or the row as it was (standard SQL).
     */
    public function laterAssignmentsSeeEarlierOnes(): bool;

    /**
     * Whether a failed statement aborts the whole open transaction (PostgreSQL) or only itself
     * (InnoDB). A callback that swallows the failure and returns normally commits the earlier rows
     * on the latter; where the transaction is aborted, the commit is refused.
     */
    public function aFailedStatementAbortsTheTransaction(): bool;

    /**
     * Every way a consumer creates this driver on the test database - the factory named after the
     * engine first, then the constructor, Database::connect() and Database::fromEnv() - each taking
     * the extra keys of a configuration ('pdoClass', 'options').
     *
     * @return array<string, Closure(array{pdoClass?: class-string<PDO>|null, options?: array<int, mixed>}): AbstractDriver>
     */
    public function factories(): array;

    /**
     * The class PDO itself offers for this engine (Pdo\Mysql for MariaDB).
     *
     * @return class-string<PDO>
     */
    public function driversOwnPdoClass(): string;
}
