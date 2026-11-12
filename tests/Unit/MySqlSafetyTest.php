<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\MySqlTestCase;
use Tests\Support\MySql\DatabaseProbe;
use Tests\Support\MySql\Guard;
use Tests\Support\MySql\Manifest;
use Tests\Support\MySql\Preparation;
use Tests\Support\MySql\Profile;
use Tests\Support\MySql\Runtime;
use Tests\Support\MySql\UnsafeMySqlEnvironment;

abstract class AccidentalRefreshCase extends MySqlTestCase
{
    use RefreshDatabase;
}

abstract class AccidentalMigrationCase extends MySqlTestCase
{
    use DatabaseMigrations;
}

#[Group('mysql-safety')]
final class MySqlSafetyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function values(bool $schema = false): array
    {
        return [
            'APP_ENV' => 'testing', 'MYSQL_TEST_HOST' => '127.0.0.1', 'MYSQL_TEST_PORT' => '3306',
            'MYSQL_TEST_DATABASE' => 'glos_vessel_manager_test', 'MYSQL_TEST_USERNAME' => $schema ? 'glos_test_schema' : 'glos_test_runner',
            'MYSQL_TEST_PASSWORD' => str_repeat('x', 24), 'MYSQL_TEST_OWNERSHIP_TOKEN' => str_repeat('a', 64),
        ];
    }

    private function manifest(): Manifest
    {
        return new Manifest(dirname(__DIR__, 2));
    }

    private function identity(bool $schema = false): array
    {
        return ['db' => Profile::DATABASE, 'account' => ($schema ? 'glos_test_schema' : 'glos_test_runner').'@127.0.0.1',
            'port' => '3306', 'server_uuid' => '11111111-1111-1111-1111-111111111111', 'version' => '8.4.13', 'partial_revokes' => 0];
    }

    private function grants(bool $schema = false): array
    {
        $permissions = $schema ? 'SELECT, INSERT, CREATE, ALTER, INDEX, REFERENCES' : 'SELECT, INSERT, UPDATE, DELETE';

        return ['GRANT USAGE ON *.* TO `account`@`127.0.0.1`', 'GRANT '.$permissions.' ON `glos\\_vessel\\_manager\\_test`.* TO `account`@`127.0.0.1`'];
    }

    private function tables(): array
    {
        return array_map(fn ($name) => ['name' => $name, 'type' => 'BASE TABLE', 'engine' => 'InnoDB'], Manifest::TABLES);
    }

    private function marker(): array
    {
        return [['id' => 1, 'protocol_version' => 1, 'ownership_hash' => hash('sha256', str_repeat('a', 64)),
            'migration_hash' => $this->manifest()->hash, 'schema_hash' => str_repeat('b', 64), 'server_uuid' => $this->identity()['server_uuid']]];
    }

    private function counts(bool $marked = true): array
    {
        return array_replace(array_fill_keys(Manifest::TABLES, 0), [
            'migrations' => count($this->manifest()->migrations), 'privilege_management_locks' => 1, 'mysql_test_ownership' => $marked ? 1 : 0,
        ]);
    }

    private function probe(array $overrides = [], bool $schema = false): DatabaseProbe
    {
        $probe = Mockery::mock(DatabaseProbe::class);
        $defaults = ['identity' => $this->identity($schema), 'grants' => $this->grants($schema), 'tables' => $this->tables(),
            'migrations' => $this->manifest()->migrations, 'marker' => $this->marker(), 'schemaHash' => str_repeat('b', 64), 'counts' => $this->counts()];
        foreach (array_replace($defaults, $overrides) as $method => $result) {
            $probe->shouldReceive($method)->andReturn($result);
        }
        $probe->shouldNotReceive('createMarker');
        $probe->shouldNotReceive('recordMarker');

        return $probe;
    }

    #[DataProvider('invalidProfiles')]
    public function test_invalid_private_profiles_fail_without_connecting(array $overrides): void
    {
        $this->expectException(UnsafeMySqlEnvironment::class);
        Profile::fromValues(array_replace($this->values(), $overrides));
    }

    public static function invalidProfiles(): array
    {
        return [
            [['MYSQL_TEST_DATABASE' => 'other_test']], [['MYSQL_TEST_DATABASE' => 'glos_vessel_manager']],
            [['APP_ENV' => 'local']], [['MYSQL_TEST_HOST' => 'remote']], [['MYSQL_TEST_PORT' => '3307']],
            [['MYSQL_TEST_USERNAME' => 'root']], [['MYSQL_TEST_USERNAME' => 'glos_test_schema']],
            [['MYSQL_TEST_PASSWORD' => '']], [['MYSQL_TEST_OWNERSHIP_TOKEN' => '']],
            [['DB_URL' => 'mysql://not-allowed']], [['DB_SOCKET' => '/tmp/mysql.sock']],
        ];
    }

    #[DataProvider('invalidEnvironments')]
    public function test_ambient_overrides_are_rejected(array $environment, array $arguments): void
    {
        $this->expectException(UnsafeMySqlEnvironment::class);
        Profile::assertAmbient(dirname(__DIR__, 2), $environment, $arguments);
    }

    public static function invalidEnvironments(): array
    {
        return [
            [['DB_DATABASE' => 'glos_vessel_manager'], []], [['DB_HOST' => 'remote'], []],
            [['DB_PORT' => '3307'], []], [['APP_ENV' => 'production'], []],
            [['DB_URL' => 'mysql://unexpected'], []], [['DATABASE_URL' => 'mysql://unexpected'], []],
            [['DB_SOCKET' => '/tmp/mysql.sock'], []], [['APP_CONFIG_CACHE' => '/tmp/cache.php'], []],
            [['TEST_TOKEN' => '1'], []], [['PARATEST' => '1'], []], [['DB_READ_HOST' => 'remote'], []],
            [[], ['--parallel']], [[], ['--recreate-databases']], [[], ['--processes=2']],
        ];
    }

    public function test_missing_configuration_and_cached_configuration_fail_before_any_connection(): void
    {
        $root = sys_get_temp_dir().'/glos-mysql-safety-'.bin2hex(random_bytes(8));
        mkdir($root.'/bootstrap/cache', 0700, true);
        try {
            try {
                Profile::load($root, false, []);
                $this->fail('Missing profile must be rejected.');
            } catch (UnsafeMySqlEnvironment $exception) {
                $this->assertStringContainsString('private MySQL test profile', $exception->getMessage());
            }
            file_put_contents($root.'/bootstrap/cache/config.php', '<?php return [];');
            $this->expectException(UnsafeMySqlEnvironment::class);
            Profile::assertAmbient($root, [], []);
        } finally {
            unlink($root.'/bootstrap/cache/config.php');
            rmdir($root.'/bootstrap/cache');
            rmdir($root.'/bootstrap');
            rmdir($root);
        }
    }

    public function test_private_profile_loading_uses_only_the_explicit_file(): void
    {
        $root = sys_get_temp_dir().'/glos-mysql-profile-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $path = $root.'/.env.testing.mysql';
        try {
            $lines = [];
            foreach ($this->values() as $key => $value) {
                $lines[] = $key.'='.$value;
            }
            file_put_contents($path, implode("\n", $lines));
            chmod($path, 0600);
            $profile = Profile::load($root, false, []);
            $this->assertSame('glos_test_runner', $profile->username);
            $this->assertStringNotContainsString(str_repeat('x', 24), json_encode($profile->__debugInfo()));
            chmod($path, 0644);
            $this->expectException(UnsafeMySqlEnvironment::class);
            Profile::load($root, false, []);
        } finally {
            unlink($path);
            rmdir($root);
        }
    }

    #[DataProvider('wrongIdentities')]
    public function test_effective_server_identity_is_verified(array $overrides): void
    {
        $profile = Profile::fromValues($this->values());
        $probe = $this->probe(['identity' => array_replace($this->identity(), $overrides)]);
        $this->expectException(UnsafeMySqlEnvironment::class);
        (new Guard($this->manifest()))->prepared($profile, $probe);
    }

    public static function wrongIdentities(): array
    {
        return [[['db' => 'glos_vessel_manager']], [['db' => 'other_test']], [['account' => 'root@localhost']],
            [['account' => 'glos_test_schema@127.0.0.1']], [['port' => 3307]], [['version' => '10.11-MariaDB']]];
    }

    public function test_a_recognised_clean_schema_is_accepted_without_writing(): void
    {
        (new Guard($this->manifest()))->prepared(Profile::fromValues($this->values()), $this->probe(), true);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidSchemas')]
    public function test_unrecognised_missing_marker_or_changed_baselines_are_rejected(string $scenario): void
    {
        $overrides = match ($scenario) {
            'unknown table' => ['tables' => [['name' => 'company_records', 'type' => 'BASE TABLE', 'engine' => 'InnoDB']]],
            'missing marker table' => ['tables' => array_values(array_filter($this->tables(), fn ($row) => $row['name'] !== 'mysql_test_ownership'))],
            'missing marker row' => ['marker' => []],
            'duplicate marker' => ['marker' => [...$this->marker(), ...$this->marker()]],
            'wrong token' => ['marker' => [array_replace($this->marker()[0], ['ownership_hash' => str_repeat('0', 64)])]],
            'wrong server' => ['marker' => [array_replace($this->marker()[0], ['server_uuid' => 'other'])]],
            'old source' => ['marker' => [array_replace($this->marker()[0], ['migration_hash' => str_repeat('0', 64)])]],
            'modified schema' => ['schemaHash' => str_repeat('0', 64)],
            'missing migration' => ['migrations' => []],
            'leftover data' => ['counts' => array_replace($this->counts(), ['users' => 1])],
            'nontransactional engine' => ['tables' => array_map(fn ($row) => array_replace($row, ['engine' => 'MyISAM']), $this->tables())],
        };
        $this->expectException(UnsafeMySqlEnvironment::class);
        (new Guard($this->manifest()))->prepared(Profile::fromValues($this->values()), $this->probe($overrides), true);
    }

    public static function invalidSchemas(): array
    {
        return array_map(fn ($name) => [$name], ['unknown table', 'missing marker table', 'missing marker row', 'duplicate marker', 'wrong token', 'wrong server', 'old source', 'modified schema', 'missing migration', 'leftover data', 'nontransactional engine']);
    }

    #[DataProvider('unsafeGrants')]
    public function test_broad_or_ddl_runner_privileges_are_rejected(string $grant): void
    {
        $this->expectException(UnsafeMySqlEnvironment::class);
        (new Guard($this->manifest()))->prepared(Profile::fromValues($this->values()), $this->probe(['grants' => [$grant]]));
    }

    public static function unsafeGrants(): array
    {
        return [['GRANT ALL PRIVILEGES ON *.* TO account'], ['GRANT SELECT, INSERT, UPDATE, DELETE ON `glos_vessel_manager_test`.* TO account'],
            ['GRANT DROP ON `glos\\_vessel\\_manager\\_test`.* TO account'], ['GRANT SELECT ON `glos_vessel_manager`.* TO account']];
    }

    #[DataProvider('schemaOperations')]
    public function test_schema_statements_and_unmanaged_writes_are_rejected(string $sql, bool $transaction): void
    {
        $this->expectException(UnsafeMySqlEnvironment::class);
        Guard::statement($sql, $transaction);
    }

    public static function schemaOperations(): array
    {
        return [['DROP TABLE users', true], ['TRUNCATE users', true], ['ALTER TABLE users ADD x INT', true],
            ['CREATE TABLE x (id INT)', true], ['USE glos_vessel_manager', true], ['SET foreign_key_checks=0', true],
            ['INSERT INTO users VALUES (1)', false], ['DELETE FROM users', false], ['COMMIT', true]];
    }

    #[DataProvider('forbiddenTraits')]
    public function test_accidental_automatic_schema_lifecycles_are_rejected(string $class): void
    {
        $this->expectException(UnsafeMySqlEnvironment::class);
        Guard::traits($class);
    }

    public static function forbiddenTraits(): array
    {
        return [[AccidentalRefreshCase::class], [AccidentalMigrationCase::class]];
    }

    public function test_effective_laravel_configuration_cannot_drift(): void
    {
        $profile = Profile::fromValues($this->values());
        $config = ['app' => ['env' => 'testing'], 'database' => ['default' => 'mysql_testing', 'connections' => ['mysql_testing' => $profile->connection()]]];
        Guard::effective($config, $profile);
        $config['database']['connections']['mysql_testing']['database'] = 'glos_vessel_manager';
        $this->expectException(UnsafeMySqlEnvironment::class);
        Guard::effective($config, $profile);
    }

    #[DataProvider('unsafePreparations')]
    public function test_unsafe_preparation_never_invokes_migrations(string $scenario): void
    {
        $schema = $scenario !== 'runner account';
        $profile = Profile::fromValues($this->values($schema), $schema);
        $probe = $this->probe(['tables' => $scenario === 'populated schema' ? $this->tables() : []], $schema);
        $this->expectException(UnsafeMySqlEnvironment::class);
        (new Preparation(new Guard($this->manifest()), $this->manifest()))->run($profile, $probe,
            $scenario === 'missing operator' ? '' : 'operator',
            $scenario === 'cancelled' ? 'NO' : Preparation::CONFIRMATION,
            function () {
                $this->fail('Unsafe preparation must never reach migrations.');
            });
    }

    public static function unsafePreparations(): array
    {
        return [['populated schema'], ['runner account'], ['missing operator'], ['cancelled']];
    }

    public function test_schema_preparation_failure_never_records_a_success_marker(): void
    {
        $profile = Profile::fromValues($this->values(true), true);
        $probe = $this->probe(['tables' => []], true);
        $this->expectException(\RuntimeException::class);
        (new Preparation(new Guard($this->manifest()), $this->manifest()))->run($profile, $probe, 'operator', Preparation::CONFIRMATION,
            fn () => throw new \RuntimeException('Simulated migration failure'));
    }

    public function test_default_sqlite_profile_and_mysql_suite_are_separate(): void
    {
        $root = dirname(__DIR__, 2);
        $xml = simplexml_load_file($root.'/phpunit.mysql.xml');
        $this->assertSame('tests/mysql-bootstrap.php', (string) $xml['bootstrap']);
        $this->assertSame('tests/Integration/MySql', (string) $xml->testsuites->testsuite->directory);
        $this->assertStringNotContainsString('Integration/MySql', file_get_contents($root.'/phpunit.xml'));
        $this->assertStringContainsString("database.connections.sqlite.database') !== ':memory:'", file_get_contents($root.'/tests/TestCase.php'));
        $this->assertTrue((new \ReflectionMethod(MySqlTestCase::class, 'createApplication'))->isFinal());
    }

    public function test_confirmation_gates_preparation_and_marker_is_only_written_after_verified_migrations(): void
    {
        $profile = Profile::fromValues($this->values(true), true);
        $manifest = $this->manifest();
        $stage = 0;
        $probe = Mockery::mock(DatabaseProbe::class);
        $probe->shouldReceive('identity')->andReturn($this->identity(true));
        $probe->shouldReceive('grants')->andReturn($this->grants(true));
        $probe->shouldReceive('tables')->andReturnUsing(function () use (&$stage) {
            return $stage === 0 ? [] : $this->tables();
        });
        $probe->shouldReceive('createMarker')->once()->andReturnUsing(function () use (&$stage) {
            $this->assertSame(1, $stage);
        });
        $probe->shouldReceive('migrations')->andReturn($manifest->migrations);
        $probe->shouldReceive('schemaHash')->andReturn(str_repeat('b', 64));
        $probe->shouldReceive('counts')->andReturnUsing(function () use (&$stage) {
            return $this->counts($stage === 2);
        });
        $probe->shouldReceive('recordMarker')->once()->with([$profile->ownershipHash(), $manifest->hash, str_repeat('b', 64), $this->identity()['server_uuid'], 'operator'])
            ->andReturnUsing(function () use (&$stage) {
                $stage = 2;
            });
        $probe->shouldReceive('marker')->andReturn($this->marker());
        (new Preparation(new Guard($manifest), $manifest))->run($profile, $probe, 'operator', Preparation::CONFIRMATION, function () use (&$stage) {
            $stage = 1;
        });
        $this->assertSame(2, $stage);
    }

    public function test_partial_revokes_literal_schema_grants_are_accepted(): void
    {
        $identity = array_replace($this->identity(), ['partial_revokes' => 1]);
        $grants = ['GRANT SELECT, INSERT, UPDATE, DELETE ON `glos_vessel_manager_test`.* TO account'];
        (new Guard($this->manifest()))->prepared(Profile::fromValues($this->values()), $this->probe(['identity' => $identity, 'grants' => $grants]));
        $this->addToAssertionCount(1);
    }

    public function test_multi_statement_sql_cannot_hide_an_unmanaged_write(): void
    {
        $this->expectException(UnsafeMySqlEnvironment::class);
        Guard::statement('SELECT 1; DELETE FROM users', false);
    }

    public function test_no_op_connection_reads_and_managed_dml_are_allowed(): void
    {
        foreach (['SELECT 1', 'SHOW TABLES', 'INSERT INTO users (id) VALUES (?)', 'UPDATE users SET name = ?', 'DELETE FROM users WHERE id = ?'] as $sql) {
            Guard::statement($sql, true);
            $this->addToAssertionCount(1);
        }
    }

    public function test_the_server_mutex_uses_a_fixed_zero_wait_test_schema_key(): void
    {
        $pdo = Mockery::mock(\PDO::class);
        $statement = Mockery::mock(\PDOStatement::class);
        $pdo->shouldReceive('query')->once()->with("SELECT GET_LOCK('glos_vessel_manager_test:integration-suite', 0)")->andReturn($statement);
        $statement->shouldReceive('fetchColumn')->once()->andReturn(0);
        $this->assertFalse((new DatabaseProbe($pdo))->acquireMutex());
    }

    public function test_mysql_base_cannot_start_without_the_dedicated_bootstrap(): void
    {
        $this->expectException(UnsafeMySqlEnvironment::class);
        Runtime::runner();
    }
}
