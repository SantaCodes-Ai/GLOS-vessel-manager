<?php

namespace Tests\Support\MySql;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithCachedConfig;

class Guard
{
    public function __construct(private Manifest $manifest) {}

    public function identity(Profile $profile, DatabaseProbe $database): array
    {
        $identity = $database->identity();
        Profile::require(($identity['db'] ?? null) === Profile::DATABASE
            && ($identity['account'] ?? null) === $profile->username.'@'.Profile::HOST
            && (string) ($identity['port'] ?? '') === Profile::PORT
            && str_starts_with($identity['version'] ?? '', '8.4.')
            && preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $identity['server_uuid'] ?? '') === 1,
            'Connected MySQL database/server/account is not approved.');
        $required = $profile->schema ? ['SELECT', 'INSERT', 'CREATE', 'ALTER', 'INDEX', 'REFERENCES'] : ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
        $found = [];
        foreach ($database->grants() as $grant) {
            if (preg_match('/\AGRANT USAGE ON \*\.\* TO /', $grant) && ! str_contains($grant, 'WITH GRANT OPTION')) {
                continue;
            }
            Profile::require(preg_match('/\AGRANT ([A-Z, ]+) ON (.+) TO [^\r\n]+\z/', $grant, $matches) === 1 && ! str_contains($grant, 'WITH GRANT OPTION'), 'Unexpected test-account privileges.');
            $scope = str_replace('`', '', $matches[2]);
            $expectedScope = (bool) $identity['partial_revokes'] ? Profile::DATABASE : str_replace('_', '\\_', Profile::DATABASE);
            Profile::require($scope === $expectedScope.'.*', 'Test-account privileges are not restricted to the exact test schema.');
            $permissions = array_map('trim', explode(',', $matches[1]));
            Profile::require(array_diff($permissions, $required) === [], 'Unexpected elevated test-account privileges.');
            $found = array_unique([...$found, ...$permissions]);
        }
        Profile::require(array_diff($required, $found) === [], 'Required scoped test privileges are missing.');

        return $identity;
    }

    public function prepared(Profile $profile, DatabaseProbe $database, bool $clean = false): void
    {
        $identity = $this->identity($profile, $database);
        $this->tables($database);
        Profile::require($database->migrations() === $this->manifest->migrations, 'Prepared migration baseline differs.');
        $markers = $database->marker();
        Profile::require(count($markers) === 1, 'A single verified test ownership marker is required.');
        $marker = $markers[0];
        Profile::require((int) ($marker['id'] ?? 0) === 1 && (int) ($marker['protocol_version'] ?? 0) === 1
            && hash_equals($profile->ownershipHash(), $marker['ownership_hash'] ?? '')
            && hash_equals($this->manifest->hash, $marker['migration_hash'] ?? '')
            && ($marker['server_uuid'] ?? '') === $identity['server_uuid']
            && hash_equals($database->schemaHash(), $marker['schema_hash'] ?? ''),
            'Test schema ownership or schema fingerprint is not verified.');
        if ($clean) {
            $this->clean($database);
        }
    }

    public function tables(DatabaseProbe $database): void
    {
        $tables = $database->tables();
        Profile::require(array_column($tables, 'name') === Manifest::TABLES, 'Unrecognised or unprepared test schema.');
        foreach ($tables as $table) {
            Profile::require($table['type'] === 'BASE TABLE' && $table['engine'] === 'InnoDB', 'Every test table must use InnoDB.');
        }
    }

    public function clean(DatabaseProbe $database, bool $marked = true): void
    {
        $expected = array_fill_keys(Manifest::TABLES, 0);
        $expected['migrations'] = count($this->manifest->migrations);
        $expected['privilege_management_locks'] = 1;
        $expected['mysql_test_ownership'] = $marked ? 1 : 0;
        Profile::require($database->counts() === $expected, 'The test schema contains unexpected records; no automatic cleanup is allowed.');
    }

    public static function traits(string $class): void
    {
        $forbidden = [
            RefreshDatabase::class,
            LazilyRefreshDatabase::class,
            DatabaseMigrations::class,
            DatabaseTruncation::class,
            DatabaseTransactions::class,
            WithCachedConfig::class,
        ];
        Profile::require(array_intersect(array_keys(class_uses_recursive($class)), $forbidden) === [], 'Automatic database/schema lifecycle traits are forbidden in MySQL tests.');
    }

    public static function statement(string $sql, bool $inTransaction): void
    {
        Profile::require(! str_contains($sql, ';') && preg_match('/\A\s*(select|show|explain|insert|update|delete)\b/i', $sql) === 1, 'DDL, connection changes and manual schema operations are forbidden in test execution.');
        if (preg_match('/\A\s*(insert|update|delete)\b/i', $sql)) {
            Profile::require($inTransaction, 'DML requires the managed test transaction.');
        }
    }

    public static function effective(array $config, Profile $profile): void
    {
        Profile::require(($config['app']['env'] ?? null) === 'testing'
            && ($config['database']['default'] ?? null) === 'mysql_testing'
            && ($config['database']['connections'] ?? []) === ['mysql_testing' => $profile->connection()],
            'Effective Laravel database configuration differs from the isolated profile.');
    }
}
