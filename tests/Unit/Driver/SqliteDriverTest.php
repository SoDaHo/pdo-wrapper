<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit\Driver;

use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;

class SqliteDriverTest extends TestCase
{
    /**
     * No default path: an in-memory database is asked for by name, as in Database::sqlite().
     */
    public function testThePathIsRequired(): void
    {
        $this->assertSame(1, (new ReflectionMethod(SqliteDriver::class, '__construct'))->getNumberOfRequiredParameters());
    }

    /**
     * PDO options replace the library's defaults one by one: what is not passed stays.
     */
    public function testOptionsReplaceTheDefaults(): void
    {
        $default = new SqliteDriver(':memory:');
        $this->assertSame(PDO::FETCH_ASSOC, $default->getPdo()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $default->getPdo()->getAttribute(PDO::ATTR_ERRMODE));

        $driver = new SqliteDriver(':memory:', [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM, PDO::ATTR_TIMEOUT => 7]);
        $this->assertSame(PDO::FETCH_NUM, $driver->getPdo()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE), 'the option that was passed');
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $driver->getPdo()->getAttribute(PDO::ATTR_ERRMODE), 'a default that was not replaced');
        $this->assertSame([[7000]], $driver->query('PRAGMA busy_timeout')->fetchAll(), 'the timeout reached SQLite, in milliseconds');
    }

    /**
     * What options are for on SQLite: a connection that cannot write.
     */
    public function testAReadOnlyConnectionRefusesToWrite(): void
    {
        $file = sys_get_temp_dir() . '/pdo-wrapper-readonly-' . bin2hex(random_bytes(4)) . '.db';
        (new SqliteDriver($file))->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');

        try {
            $driver = new SqliteDriver($file, [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY]);
            $this->assertSame([], $driver->query('SELECT * FROM notes')->fetchAll());

            try {
                $driver->insert('notes', ['body' => 'no']);
                $this->fail('Expected QueryException');
            } catch (QueryException $e) {
                $this->assertSame(8, $e->driverCode, 'SQLITE_READONLY');
            }
        } finally {
            unlink($file);
        }
    }

    public function testExplicitMemoryPath(): void
    {
        $driver = new SqliteDriver(':memory:');

        $this->assertNotNull($driver->getPdo());
    }

    /**
     * SQLite reads the path up to a NUL byte: "data.db\0.txt" would open "data.db".
     */
    public function testRejectsANulByteInThePath(): void
    {
        $path = sys_get_temp_dir() . '/pdo-wrapper-nul-' . bin2hex(random_bytes(4));

        try {
            new SqliteDriver($path . "\0.txt");
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('Invalid character in config value "path"', $e->getDebugMessage());
        }

        $this->assertFileDoesNotExist($path);
    }

    public function testInvalidPathThrowsConnectionException(): void
    {
        $this->expectException(ConnectionException::class);

        new SqliteDriver('/nonexistent/directory/that/does/not/exist/test.db');
    }

    public function testConnectionExceptionHasDebugMessage(): void
    {
        try {
            new SqliteDriver('/nonexistent/directory/test.db');
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertStringContainsString('SQLite', $e->getDebugMessage());
            return;
        }

        $this->fail('Expected ConnectionException was not thrown');
    }
}
