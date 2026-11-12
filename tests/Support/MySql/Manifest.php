<?php

namespace Tests\Support\MySql;

final readonly class Manifest
{
    public const TABLES = ['cache', 'cache_locks', 'certificate_types', 'certificate_versions', 'certificates', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'mysql_test_ownership', 'password_reset_tokens', 'privilege_audits', 'privilege_management_locks', 'registration_decisions', 'sessions', 'users', 'vessel_user_access', 'vessels'];

    public array $migrations;

    public string $hash;

    public function __construct(string $root)
    {
        $files = glob($root.'/database/migrations/*.php');
        Profile::require(is_array($files) && $files !== [], 'Migration manifest is unavailable.');
        sort($files);
        $digests = [];
        foreach ($files as $path) {
            $digests[pathinfo($path, PATHINFO_FILENAME)] = hash_file('sha256', $path);
        }
        $this->migrations = array_keys($digests);
        $this->hash = hash('sha256', json_encode($digests, JSON_THROW_ON_ERROR));
    }
}
