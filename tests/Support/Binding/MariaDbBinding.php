<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support\Binding;

use PDO;
use Pdo\Mysql;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Driver\MySqlDriver;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;
use UnexpectedValueException;

/**
 * The binding of the MariaDB driver: the test database from the MYSQL_* variables
 * (TestEnvironment), tables in InnoDB with case- and accent-sensitive strings.
 */
final class MariaDbBinding implements DriverBinding
{
    private const array TYPES = [
        'id' => 'BIGINT AUTO_INCREMENT PRIMARY KEY',
        'key' => 'BIGINT PRIMARY KEY',
        'int' => 'INT',
        'bigint' => 'BIGINT',
        'text' => 'VARCHAR(255) COLLATE utf8mb4_bin',
        'decimal' => 'DECIMAL(30,4)',
        'double' => 'DOUBLE',
        'blob' => 'LONGBLOB',
        'timestamp' => 'TIMESTAMP',
    ];

    public function connect(array $extra = []): AbstractDriver
    {
        return Database::mysql(TestEnvironment::mysql() + $extra);
    }

    public function pdo(string $class = PDO::class): PDO
    {
        $settings = TestEnvironment::mysql();

        return new $class(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $settings['host'], $settings['port'], $settings['database']),
            $settings['username'],
            $settings['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    }

    public function create(DatabaseInterface $db, string $table, array $columns): void
    {
        $this->drop($db, $table);
        $definitions = [];
        foreach ($columns as $name => $type) {
            if (is_int($name)) {
                $definitions[] = $type; // a table constraint, standard SQL

                continue;
            }
            $word = strtok($type, ' ');
            if ($word === false || !isset(self::TYPES[$word])) {
                throw new UnexpectedValueException(sprintf('Unknown column type "%s" for %s.%s', $type, $table, $name));
            }
            $definitions[] = sprintf('`%s` %s%s', $name, self::TYPES[$word], substr($type, strlen($word)));
        }
        $db->execute(sprintf('CREATE TABLE `%s` (%s) ENGINE=InnoDB', $table, implode(', ', $definitions)));
    }

    public function drop(DatabaseInterface $db, string $table): void
    {
        // A connection a test left open with a transaction on the table fails the DROP instead of hanging it
        $db->execute('SET SESSION lock_wait_timeout = 5');
        $db->execute(sprintf('DROP TABLE IF EXISTS `%s`', $table));
    }

    public function failureCodes(): array
    {
        return ['unknownTable' => ['42S02', 1146], 'duplicate' => ['23000', 1062]];
    }

    public function deliveredAggregates(): array
    {
        // Sums and averages of integer and DECIMAL columns are DECIMAL (a numeric string), those of
        // a DOUBLE column are DOUBLE (a float).
        return [
            'small' => ['3', '1.5000'],
            'big' => ['18014398509481986', '9007199254740993.0000'],
            'price' => ['0.3000', '0.15000000'],
            'ratio' => [0.75, 0.375],
        ];
    }

    public function uniqueConstraintNames(): array
    {
        return ['email' => 'email', 'primary' => 'PRIMARY'];
    }

    public function laterAssignmentsSeeEarlierOnes(): bool
    {
        return true; // MariaDB evaluates the SET list left to right
    }

    public function aFailedStatementAbortsTheTransaction(): bool
    {
        return false; // InnoDB rolls back only the failed statement (a deadlock is the exception, see tests/Driver/MariaDb)
    }

    public function factories(): array
    {
        $config = TestEnvironment::mysql();

        return [
            'Database::mysql()' => static fn (array $extra): AbstractDriver => Database::mysql($config + $extra),
            'new MySqlDriver()' => static fn (array $extra): AbstractDriver => new MySqlDriver($config + $extra),
            'Database::connect()' => static fn (array $extra): AbstractDriver => self::driver(Database::connect(['driver' => 'mysql'] + $config + $extra)),
            'Database::fromEnv() with everything passed' => static fn (array $extra): AbstractDriver => self::driver(Database::fromEnv(['driver' => 'mysql'] + $config + $extra)),
            'Database::fromEnv() with DB_*' => static function (array $extra) use ($config): AbstractDriver {
                $_ENV['DB_DRIVER'] = 'mysql';
                $_ENV['DB_HOST'] = $config['host'];
                $_ENV['DB_PORT'] = (string) $config['port'];
                $_ENV['DB_DATABASE'] = $config['database'];
                $_ENV['DB_USERNAME'] = $config['username'];
                $_ENV['DB_PASSWORD'] = $config['password'];
                try {
                    return self::driver(Database::fromEnv($extra));
                } finally {
                    unset($_ENV['DB_DRIVER'], $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_DATABASE'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD']);
                }
            },
        ];
    }

    public function driversOwnPdoClass(): string
    {
        return Mysql::class;
    }

    private static function driver(DatabaseInterface $db): AbstractDriver
    {
        if (!$db instanceof AbstractDriver) {
            throw new UnexpectedValueException('The factory returned no driver of this library');
        }

        return $db;
    }
}
