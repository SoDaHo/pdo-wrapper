<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

/**
 * What the driver refuses when the connection opens - the same at reconnect(): a server that is
 * no MariaDB 10.11 or later, a client that is no mysqlnd, an option that would turn every fetched
 * value into a string, and a connection that turns NULL and '' into each other. The versions are
 * replayed (ReportedVersionPdo).
 */
class ConnectionCheckTest extends ContractTestCase
{
    protected function tearDown(): void
    {
        ReportedVersionPdo::reset();
        parent::tearDown();
    }

    public function testAServerThatIsNoMariaDbOrOlderThan1011IsRefused(): void
    {
        $cases = [
            '8.0.46' => 'The server is no MariaDB (it reports "8.0.46"): this library supports MariaDB 10.11 and later only.',
            '8.4.3-log' => 'The server is no MariaDB (it reports "8.4.3-log"): this library supports MariaDB 10.11 and later only.',
            'some proxy' => 'The server is no MariaDB (it reports "some proxy"): this library supports MariaDB 10.11 and later only.',
            '10.6.18-MariaDB-log' => 'MariaDB 10.6.18 is older than 10.11, the oldest version this library supports.',
            '5.5.5-10.4.8-MariaDB' => 'MariaDB 10.4.8 is older than 10.11, the oldest version this library supports.',
            '10.6.16-11-MariaDB-enterprise-log' => 'MariaDB 10.6.16 is older than 10.11, the oldest version this library supports.',
            '11.4.5-x-MariaDB' => 'The server is no MariaDB (it reports "11.4.5-x-MariaDB"): this library supports MariaDB 10.11 and later only.',
            '11.4.5-3-4-MariaDB' => 'The server is no MariaDB (it reports "11.4.5-3-4-MariaDB"): this library supports MariaDB 10.11 and later only.',
            '11.4.5-3-MariaDBx' => 'The server is no MariaDB (it reports "11.4.5-3-MariaDBx"): this library supports MariaDB 10.11 and later only.',
            '11.4.5-MariaDB_x' => 'The server is no MariaDB (it reports "11.4.5-MariaDB_x"): this library supports MariaDB 10.11 and later only.',
        ];

        foreach ($cases as $version => $problem) {
            ReportedVersionPdo::$server = $version;
            $this->assertRefused($problem, $version);
        }

        ReportedVersionPdo::$server = false;
        $this->assertRefused('The server is no MariaDB (it reports "bool"): this library supports MariaDB 10.11 and later only.', 'no string');
    }

    public function testMariaDb1011AndLaterIsAccepted(): void
    {
        foreach (['10.11.0-MariaDB', '5.5.5-10.11.19-MariaDB-log', '11.4.12-MariaDB-ubu2404', '12.3.3-MariaDB-ubu2404', '13.0.2-mariadb', '11.4.5-3-MariaDB-enterprise', '5.5.5-10.11.11-7-MariaDB-enterprise-log'] as $version) {
            ReportedVersionPdo::$server = $version;
            $db = $this->connect(['pdoClass' => ReportedVersionPdo::class]);
            $this->assertSame(1, (int) $db->query('SELECT 1')->fetchColumn(), $version);
        }
    }

    public function testAClientThatIsNoMysqlndIsRefused(): void
    {
        foreach (['libmysql - 8.0.33', '10.11.19', 'mysqlnd'] as $client) {
            ReportedVersionPdo::$client = $client;
            $this->assertRefused(sprintf('pdo_mysql is not built on mysqlnd (client "%s"): the PHP types of the fetched values would not be the ones this library promises. Use a PHP build whose pdo_mysql uses mysqlnd.', $client), $client);
        }

        ReportedVersionPdo::$client = 7;
        $this->assertRefused('pdo_mysql is not built on mysqlnd (client "int"): the PHP types of the fetched values would not be the ones this library promises. Use a PHP build whose pdo_mysql uses mysqlnd.', 'no string');
    }

    /**
     * The connector checks every connection it opens: a reconnect() to a server that is no longer
     * supported fails, and the old connection stays in place.
     */
    public function testReconnectChecksTheNewConnectionToo(): void
    {
        $db = $this->connect(['pdoClass' => ReportedVersionPdo::class]);
        $pdo = $db->getPdo();
        ReportedVersionPdo::$server = '10.6.18-MariaDB';

        try {
            $db->reconnect();
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('MariaDB 10.6.18 is older than 10.11', (string) $e->getDebugMessage());
        }
        $this->assertSame($pdo, $db->getPdo(), 'the old connection is still in place');
    }

    /**
     * ATTR_STRINGIFY_FETCHES would turn every fetched value into a string: refused before anything
     * is tried. Off, also written as 0, is what the driver sets anyway.
     */
    public function testAnOptionThatStringifiesTheResultsIsRefused(): void
    {
        foreach ([true, 1, '1'] as $on) {
            try {
                $this->connect(['options' => [PDO::ATTR_STRINGIFY_FETCHES => $on]]);
                $this->fail('Expected ConnectionException for ' . var_export($on, true));
            } catch (ConnectionException $e) {
                $this->assertSame('Database connection failed', $e->getMessage());
                $this->assertStringStartsWith('The option ATTR_STRINGIFY_FETCHES would turn every fetched value into a string', (string) $e->getDebugMessage());
                $this->assertNull($e->getPrevious(), 'nothing was tried');
            }
        }

        foreach ([false, 0] as $off) {
            $db = $this->connect(['options' => [PDO::ATTR_STRINGIFY_FETCHES => $off]]);
            $this->assertSame(1, $db->query('SELECT 1 AS one')->fetchColumn());
        }
    }

    /**
     * ATTR_ORACLE_NULLS other than NULL_NATURAL would turn NULL into '' (NULL_TO_STRING) or '' into
     * NULL (NULL_EMPTY_STRING): the connection is refused. PDO takes any spelling of an int for
     * it, so the mode is read back from the connection; every spelling of NULL_NATURAL passes, and
     * NULL arrives as null, '' as '', in native and in emulated prepares.
     */
    public function testAConnectionThatTurnsNullAndEmptyStringIntoEachOtherIsRefused(): void
    {
        $test = TestEnvironment::mariadb();
        foreach ([[PDO::NULL_EMPTY_STRING, '1'], [PDO::NULL_TO_STRING, '2'], [true, '1'], ['2', '2'], ['01', '1']] as [$mode, $reported]) {
            try {
                $this->connect(['options' => [PDO::ATTR_ORACLE_NULLS => $mode]]);
                $this->fail('Expected ConnectionException for ' . var_export($mode, true));
            } catch (ConnectionException $e) {
                $this->assertSame('Database connection failed', $e->getMessage());
                $this->assertSame(
                    sprintf('MariaDB connection to %s:%d refused: ATTR_ORACLE_NULLS is %s on this connection: NULL would arrive as \'\' or \'\' as null, not as this library promises (see MariaDbDriver). Leave it at PDO::NULL_NATURAL and convert in the application.', $test['host'], $test['port'], $reported),
                    $e->getDebugMessage()
                );
            }
        }

        foreach ([null, PDO::NULL_NATURAL, false, '0', '00'] as $mode) {
            foreach ([false, true] as $emulated) {
                $options = [PDO::ATTR_EMULATE_PREPARES => $emulated] + ($mode === null ? [] : [PDO::ATTR_ORACLE_NULLS => $mode]);
                $db = $this->connect(['options' => $options]);
                $this->assertSame(['n' => null, 'e' => ''], $db->query("SELECT NULL AS n, '' AS e WHERE 1 = ?", [1])->fetch(), var_export($mode, true));
            }
        }
    }

    public function testReconnectChecksTheNullModeOfTheNewConnection(): void
    {
        $db = $this->connect(['pdoClass' => ReportedVersionPdo::class]);
        $pdo = $db->getPdo();
        ReportedVersionPdo::$nulls = PDO::NULL_TO_STRING;

        try {
            $db->reconnect();
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('refused: ATTR_ORACLE_NULLS is 2 on this connection', (string) $e->getDebugMessage());
        }
        $this->assertSame($pdo, $db->getPdo(), 'the old connection is still in place');
    }

    private function assertRefused(string $problem, string $case): void
    {
        $test = TestEnvironment::mariadb();
        try {
            $this->connect(['pdoClass' => ReportedVersionPdo::class]);
            $this->fail('Expected ConnectionException: ' . $case);
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage(), $case);
            $this->assertSame(sprintf('MariaDB connection to %s:%d refused: %s', $test['host'], $test['port'], $problem), $e->getDebugMessage(), $case);
        }
    }
}
