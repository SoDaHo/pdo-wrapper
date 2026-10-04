<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb\EdgeCases;

use PDO;
use PDOException;
use PDOStatement;
use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\UniqueViolationException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

/**
 * The edge cases that need the MariaDB driver itself: subclasses that override query(),
 * lastInsertId() or the binding hooks, and the duplicate entry messages of MySQL and MariaDB
 * servers, replayed. The engine-neutral edge cases are in tests/Contract/EdgeCases.
 */
class EdgeCaseTest extends ContractTestCase
{
    protected function tearDown(): void
    {
        ReplayingPdo::reset();
        parent::tearDown();
    }

    // =========================================================================
    // INSERT ID AND THE QUERY HOOK
    // Bug: insert() read lastInsertId() after the 'query' hook; a listener that inserted
    // replaced the id
    // =========================================================================

    public function testInsertReadsTheIdItselfWhenAnOverriddenQueryBypassesTheDriver(): void
    {
        $driver = new class (TestEnvironment::mariadb()) extends MariaDbDriver {
            public int $reads = 0;

            public function query(string $sql, array $params = []): PDOStatement
            {
                if (!str_starts_with($sql, 'INSERT')) {
                    return parent::query($sql, $params);
                }
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array_values($params));

                return $stmt;
            }

            public function lastInsertId(?string $name = null): string|false
            {
                $this->reads++;

                return parent::lastInsertId($name);
            }
        };
        $this->create('users', ['id' => 'id', 'name' => 'text']);

        $this->assertSame(1, $driver->insert('users', ['name' => 'A']));
        $this->assertSame(2, $driver->insert('users', ['name' => 'B']));
        $this->assertSame(2, $driver->reads);

        // The step set for a bypassed insert does not run with a later statement
        $this->assertSame(2, $driver->table('users')->count());
        $this->assertSame(2, $driver->reads);
    }

    public function testInsertReturnsItsIdWhenAnOverriddenQuerySendsAStatementAhead(): void
    {
        $driver = new class (TestEnvironment::mariadb()) extends MariaDbDriver {
            public int $reads = 0;

            public function query(string $sql, array $params = []): PDOStatement
            {
                parent::query('SELECT ?', ['session setting']);

                return parent::query($sql, $params);
            }

            public function lastInsertId(?string $name = null): string|false
            {
                $this->reads++;

                return parent::lastInsertId($name);
            }
        };
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $this->create('audit', ['id' => 'id', 'note' => 'text']);
        for ($i = 0; $i < 5; $i++) {
            $driver->execute('INSERT INTO audit (note) VALUES (?)', ['filler']);
        }

        $this->assertSame(1, $driver->insert('users', ['name' => 'A']));
        $this->assertSame(1, $driver->reads, 'read once, after the INSERT - not after the statement sent ahead');

        // A listener that inserts when the statement sent ahead is told, and again when the INSERT is
        // told: the outer step survives the first and has run before the second
        $state = new class () {
            public bool $busy = false;
        };
        $driver->on('query', static function (array $data) use ($driver, $state): void {
            if (!$state->busy && ($data['params'] === ['session setting'] || str_contains($data['sql'], '`users`'))) {
                $state->busy = true;
                $driver->insert('audit', ['note' => 'from the listener']);
                $state->busy = false;
            }
        });
        $this->assertSame(2, $driver->insert('users', ['name' => 'B']));
        // counted on raw PDO: a query through the driver would trigger the listener once more
        $audit = $driver->getPdo()->query('SELECT COUNT(*) FROM audit');
        $this->assertInstanceOf(PDOStatement::class, $audit);
        $this->assertSame(7, (int) $audit->fetchColumn());
    }

    /**
     * The id step is taken by the statement it was set for and by nothing else: a listener that
     * runs the very same statement when it is told about a statement the driver sent ahead of it
     * does not take the step or make it run twice. (The listener told about the insert itself:
     * tests/Contract/EdgeCases/EdgeCaseTest::testAListenerRepeatingTheSameInsertDoesNotReplaceTheId.)
     */
    public function testAListenerRepeatingTheInsertWhenAStatementSentAheadIsToldDoesNotReplaceTheId(): void
    {
        // A driver that sends a statement ahead, and a listener that mirrors the insert when that one is told
        $driver = new class (TestEnvironment::mariadb()) extends MariaDbDriver {
            public int $reads = 0;

            public function query(string $sql, array $params = []): PDOStatement
            {
                parent::query('SELECT ?', ['session setting']);

                return parent::query($sql, $params);
            }

            public function lastInsertId(?string $name = null): string|false
            {
                $this->reads++;

                return parent::lastInsertId($name);
            }
        };
        $this->create('notes', ['id' => 'id', 'body' => 'text']);
        $this->create('mirror', ['id' => 'id', 'body' => 'text']);
        // ids 1 to 3, so that the mirror's next id (4) is none the notes get
        $driver->getPdo()->exec("INSERT INTO mirror (body) VALUES ('filler'), ('filler'), ('filler')");
        $mirrored = false;
        $driver->on('query', static function (array $data) use ($driver, &$mirrored): void {
            if (!$mirrored && $data['params'] === ['session setting']) {
                $mirrored = true;
                $driver->getPdo()->exec("INSERT INTO mirror (body) VALUES ('raw, so that the last insert id moves')");
                // the outer insert's own SQL and parameters, run by the listener before the outer statement
                $driver->execute('INSERT INTO `notes` (`body`) VALUES (?)', ['outer']);
            }
        });

        $this->assertSame(2, $driver->insert('notes', ['body' => 'outer']), 'the listener\'s row is 1, the outer row 2');
        $this->assertSame(1, $driver->reads, 'read once, after the outer INSERT: the listener\'s identical statement did not run the step');
    }

    // =========================================================================
    // UNIQUE VIOLATIONS, AS THE SERVER REPORTS THEM (replayed)
    // =========================================================================

    /**
     * The driver on a connection whose prepare() fails the way the server would.
     *
     * @param array{string, int, string}|null $errorInfo
     */
    private function failWith(string $message, ?array $errorInfo): QueryException
    {
        ReplayingPdo::$failure = new PDOException($message);
        ReplayingPdo::$failure->errorInfo = $errorInfo;
        $driver = $this->connect(['pdoClass' => ReplayingPdo::class]);

        try {
            $driver->query('INSERT INTO users (email) VALUES (?)', ['a@test.com']);
        } catch (QueryException $e) {
            return $e;
        }
        $this->fail('Expected QueryException');
    }

    public function testTheDriverReadsTheViolatedKeyFromTheServersMessage(): void
    {
        $cases = [
            // MariaDB prints the key alone (measured on 10.11, 11.4 and 12.3)
            [['23000', 1062, "Duplicate entry 'a@test.com' for key 'email'"], 'email'],
            [['23000', 1062, "Duplicate entry '7' for key 'PRIMARY'"], 'PRIMARY'],
            // a dot belongs to the name
            [['23000', 1062, "Duplicate entry 'n' for key 'my.key'"], 'my.key'],
            // the duplicate value comes from outside: only the end of the message counts
            [['23000', 1062, "Duplicate entry 'x' for key 'evil' for key 'email'"], 'email'],
            // another message language (lc_messages): a duplicate, the name is not readable
            [['23000', 1062, "Doppelter Eintrag 'a@test.com' für Schlüssel 'email'"], null],
        ];

        foreach ($cases as [$errorInfo, $constraint]) {
            $e = $this->failWith('SQLSTATE[' . $errorInfo[0] . ']: ' . $errorInfo[2], $errorInfo);
            $this->assertInstanceOf(UniqueViolationException::class, $e, $errorInfo[2]);
            $this->assertSame($constraint, $e->constraint, $errorInfo[2]);
        }
    }

    /**
     * The driver's own error code decides, not the wording: other constraint failures, and a
     * PDOException without errorInfo (a PDO subclass, a proxy), are plain failed queries.
     */
    public function testAFailureWithoutTheDriversCodeForADuplicateIsNotAUniqueViolation(): void
    {
        $cases = [
            ["Duplicate entry 'a' for key 'email'", null],
            ["Column 'name' cannot be null", ['23000', 1048, "Column 'name' cannot be null"]],
        ];

        foreach ($cases as [$message, $errorInfo]) {
            $e = $this->failWith($message, $errorInfo);
            $this->assertNotInstanceOf(UniqueViolationException::class, $e, $message);
            $this->assertSame('Query failed', $e->getMessage());
        }
    }

    // =========================================================================
    // WHAT A DRIVER MAY BIND
    // =========================================================================

    public function testADriverThatBindsStreamsDecidesWhatItLetsThrough(): void
    {
        $driver = new class (TestEnvironment::mariadb()) extends MariaDbDriver {
            protected function unbindableParameter(array $params): ?string
            {
                return parent::unbindableParameter(array_filter($params, static fn (mixed $value): bool => !is_resource($value)));
            }

            protected function bindAndExecute(PDOStatement $stmt, array $params): bool
            {
                foreach ($params as $key => $value) {
                    $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, is_resource($value) ? PDO::PARAM_LOB : PDO::PARAM_STR);
                }

                return $stmt->execute();
            }
        };
        $this->create('files', ['id' => 'id', 'name' => 'text', 'content' => 'blob']);
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, "binary\x00content");
        rewind($stream);

        $driver->insert('files', ['name' => 'a.bin', 'content' => $stream]);
        fclose($stream);

        $this->assertSame("binary\x00content", $driver->query('SELECT content FROM files')->fetchColumn());
        try {
            $driver->insert('files', ['name' => ['not', 'a', 'name'], 'content' => 'x']);
            $this->fail('The rest of the rule still holds');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Cannot bind a value of type array (parameter #1)', (string) $e->getDebugMessage());
        }
    }
}
