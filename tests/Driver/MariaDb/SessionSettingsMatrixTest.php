<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;
use Throwable;

/**
 * The outcomes of transaction() under session settings off the default, each set with SET
 * SESSION after the driver connected: completion_type, autocommit, sql_mode, the isolation level.
 * Four cases each - the commit goes through, the callback throws, the COMMIT fails before it is
 * sent, the COMMIT takes effect and is reported as failed - on a connection of its own. What must
 * hold under every setting: 'committed' and 'lost' after a COMMIT that took effect leave the row
 * committed, 'rolled_back' leaves nothing. A 'lost' after a COMMIT that did not take effect is
 * the fail-closed answer where the driver cannot tell (CHAIN, RELEASE).
 */
class SessionSettingsMatrixTest extends ContractTestCase
{
    private const TABLE = 'session_settings_matrix';

    protected function setUp(): void
    {
        $this->create(self::TABLE, ['id' => 'key', 'name' => 'text']);
    }

    /**
     * @return array<string, array{string, array{string, string, string, string}}>
     */
    public static function settings(): array
    {
        $usual = ['committed', 'rolled_back', 'rolled_back', 'lost'];

        return [
            'completion_type NO_CHAIN' => ["SET SESSION completion_type = 'NO_CHAIN'", $usual],
            'completion_type CHAIN' => ["SET SESSION completion_type = 'CHAIN'", ['committed, chained', 'rolled_back, chained', 'lost, chained', 'lost, chained']],
            'completion_type RELEASE' => ["SET SESSION completion_type = 'RELEASE'", ['committed, closed', 'rolled_back, closed', 'lost, closed', 'lost, closed']],
            'autocommit 0' => ['SET SESSION autocommit = 0', $usual],
            'sql_mode empty' => ["SET SESSION sql_mode = ''", $usual],
            'sql_mode ANSI' => ["SET SESSION sql_mode = 'ANSI'", $usual],
            'sql_mode TRADITIONAL' => ["SET SESSION sql_mode = 'TRADITIONAL'", $usual],
            'READ UNCOMMITTED' => ['SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED', $usual],
            'READ COMMITTED' => ['SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED', $usual],
            'REPEATABLE READ' => ['SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ', $usual],
            'SERIALIZABLE' => ['SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE', $usual],
        ];
    }

    /**
     * @param array{string, string, string, string} $expected What each case ends as: the outcome, and whether the connection is in a chained transaction or closed afterwards
     */
    #[DataProvider('settings')]
    public function testTheOutcomesHoldUnderTheSetting(string $setting, array $expected): void
    {
        $cases = ['commit goes through', 'callback throws', 'COMMIT fails before it is sent', 'COMMIT takes effect, reported as failed'];
        $seen = [];
        foreach ($cases as $index => $case) {
            $seen[] = $this->run1($setting, $index + 1, $case);
        }

        $this->assertSame(array_combine($cases, $expected), array_combine($cases, $seen));
    }

    /**
     * One case on a connection of its own; the row has the id $id. Returns the outcome
     * transaction.end told, with ", chained" or ", closed" for the state of the connection after it.
     */
    private function run1(string $setting, int $id, string $case): string
    {
        $db = $this->connect(['pdoClass' => ScenarioPdo::class]);
        $pdo = $db->getPdo();
        $this->assertInstanceOf(ScenarioPdo::class, $pdo);
        $db->execute($setting);
        $ends = [];
        $db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data['outcome'];
        });

        $thrown = null;
        try {
            $db->transaction(static function (DatabaseInterface $db) use ($pdo, $id, $case): void {
                $db->insert(self::TABLE, ['id' => $id, 'name' => $case]);
                match ($case) {
                    'callback throws' => throw new RuntimeException('the callback failed'),
                    'COMMIT fails before it is sent' => $pdo->failCommit = true,
                    'COMMIT takes effect, reported as failed' => $pdo->failAfterCommit = true,
                    default => null,
                };
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertCount(1, $ends, $case . ': exactly one end');
        $outcome = $ends[0];
        $committed = $this->committed($id);
        match ($outcome) {
            DatabaseInterface::TRANSACTION_COMMITTED => $this->assertTrue($committed, $case . ': committed means the row is there'),
            DatabaseInterface::TRANSACTION_ROLLED_BACK => $this->assertFalse($committed, $case . ': rolled_back means nothing is committed'),
            default => $this->assertSame($case === 'COMMIT takes effect, reported as failed', $committed, $case . ': lost'),
        };
        if ($thrown instanceof CommitFailedException) {
            $this->assertSame($outcome, $thrown->outcome, $case . ': the exception says what the end said');
        }
        if ($outcome === DatabaseInterface::TRANSACTION_COMMITTED && $thrown !== null) {
            $this->assertInstanceOf(CommitHookException::class, $thrown, $case);
        }

        try {
            $chained = $pdo->reallyInTransaction();
            $pdo->exec("SET SESSION completion_type = 'NO_CHAIN'");
            if ($chained) {
                $pdo->rollBack();
            }

            return (string) $outcome . ($chained ? ', chained' : '');
        } catch (Throwable) {
            return (string) $outcome . ', closed';
        }
    }

    private function committed(int $id): bool
    {
        return $this->connect()->table(self::TABLE)->where('id', $id)->count() === 1;
    }
}
