<?php

namespace Tests\Support\MySql\Concurrency;

use Tests\Support\MySql\Profile;

final class Journal
{
    public readonly string $directory;

    public readonly string $run;

    private array $state;

    public function __construct(string $operator)
    {
        Profile::require(trim($operator) !== '' && strlen($operator) <= 100, 'An operator identifier is required.');
        $this->run = bin2hex(random_bytes(16));
        $this->directory = sys_get_temp_dir().'/glos-concurrency-'.$this->run;
        Profile::require(mkdir($this->directory, 0700), 'Cannot create private run directory.');
        mkdir($this->directory.'/storage', 0700);
        $this->state = ['run' => $this->run, 'operator' => $operator, 'fixtures' => [], 'files' => [], 'workers' => [], 'cleanup' => 'not-started', 'lifecycle' => 'none', 'commit_events' => [], 'plan' => []];
        $this->persist();
    }

    public function planFixture(): void
    {
        Profile::require($this->state['lifecycle'] === 'none' && $this->state['fixtures'] === [] && $this->state['files'] === [], 'Fixture scenario already started.');
        $this->state['plan'] = ['scenario' => 'fixture-lifecycle-v1', 'email' => 'fixture+'.$this->run.'@example.test',
            'name' => 'Concurrency '.$this->run, 'expected_records' => 3];
        $this->lifecycle('planned');
    }

    public function lifecycle(string $next): void
    {
        $allowed = match ($this->state['lifecycle']) {
            'none' => ['planned'],
            'planned' => ['prepared', 'rolled-back', 'uncertain'],
            'prepared' => ['commit-attempted', 'rolled-back', 'uncertain'],
            'commit-attempted' => ['committed', 'uncertain'],
            'committed' => ['observed', 'uncertain'],
            'observed' => ['cleaned', 'uncertain'],
            default => [],
        };
        Profile::require(in_array($next, $allowed, true), 'Unsafe fixture lifecycle transition.');
        $this->state['lifecycle'] = $next;
        $this->state['commit_events'][] = ['state' => $next, 'at' => gmdate('c')];
        $this->persist();
    }

    public function stage(): string
    {
        return $this->state['lifecycle'];
    }

    public function manifest(): array
    {
        return array_replace(array_fill_keys(NativeBackend::TABLES, []), $this->state['fixtures']);
    }

    public function assertCleanupAllowed(): void
    {
        Profile::require(in_array($this->stage(), ['none', 'observed'], true), 'Fixture commit/observation is uncertain; cleanup forbidden.');
    }

    public static function fingerprint(array $row): string
    {
        ksort($row);

        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function claim(string $table, array $row): void
    {
        Profile::require(in_array($table, NativeBackend::TABLES, true) && isset($row['id']) && ctype_digit((string) $row['id']) && (int) $row['id'] > 0, 'Invalid fixture identity.');
        $owned = match ($table) {
            'users' => str_ends_with($row['email'] ?? '', '+'.$this->run.'@example.test'),
            'vessels' => ($row['vessel_name'] ?? '') === 'Concurrency '.$this->run,
            'certificate_types' => ($row['name'] ?? '') === 'Concurrency '.$this->run,
            'certificates' => $this->owns('vessels', $row['vessel_id'] ?? 0) && $this->owns('certificate_types', $row['certificate_type_id'] ?? 0),
            'certificate_versions' => $this->owns('certificates', $row['certificate_id'] ?? 0),
            'vessel_user_access' => $this->owns('users', $row['user_id'] ?? 0) && $this->owns('vessels', $row['vessel_id'] ?? 0),
            'registration_decisions' => $this->owns('users', $row['user_id'] ?? 0) && $this->owns('users', $row['actor_user_id'] ?? 0),
            'privilege_audits' => $this->owns('users', $row['target_user_id'] ?? 0) && $this->owns('users', $row['actor_user_id'] ?? 0),
            default => false,
        };
        Profile::require($owned, 'Fixture ownership is not proven.');
        $this->state['fixtures'][$table][(string) $row['id']] = self::fingerprint($row);
        $this->persist();
    }

    private function owns(string $table, mixed $id): bool
    {
        return isset($this->state['fixtures'][$table][(string) $id]);
    }

    public function assertRows(array $rows): void
    {
        Profile::require(array_diff(array_keys($rows), NativeBackend::TABLES) === [], 'Unexpected business table.');
        foreach (NativeBackend::TABLES as $table) {
            $actual = [];
            foreach ($rows[$table] ?? [] as $id => $row) {
                Profile::require((string) ($row['id'] ?? '') === (string) $id, 'Invalid fixture snapshot.');
                $actual[(string) $id] = self::fingerprint($row);
            }
            $expected = $this->state['fixtures'][$table] ?? [];
            ksort($actual);
            ksort($expected);
            Profile::require($actual === $expected, 'Unexpected, missing or changed fixture; retain journal for manual review.');
        }
    }

    public function recordFile(string $relative): void
    {
        Profile::require(preg_match('~\A(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+\.pdf\z~', $relative) === 1, 'Unsafe test file path.');
        $this->state['files'][] = $relative;
        $this->persist();
    }

    public function worker(string $id, string $status, int $attempt = 0, ?int $code = null): void
    {
        Profile::require(preg_match('/\Aw[0-9]{1,3}\z/', $id) === 1 && in_array($status, ['running', 'stopped', 'timeout', 'deadlock', 'rolled-back', 'committed', 'failed'], true)
            && $attempt >= 0 && $attempt <= 100 && in_array($code, [null, 1205, 1213], true), 'Unsafe worker outcome.');
        $this->state['workers'][$id][] = compact('status', 'attempt', 'code');
        $this->persist();
    }

    public function workersStopped(): bool
    {
        foreach ($this->state['workers'] as $events) {
            if (end($events)['status'] !== 'stopped' || array_intersect(array_column($events, 'status'), ['failed', 'timeout']) !== []) {
                return false;
            }
        }

        return true;
    }

    public function cleanup(string $status): void
    {
        Profile::require(in_array($status, ['verified', 'database-clean', 'complete', 'manual-review'], true), 'Invalid cleanup state.');
        $this->state['cleanup'] = $status;
        $this->persist();
    }

    public function files(): array
    {
        return $this->state['files'];
    }

    private function persist(): void
    {
        $path = $this->directory.'/journal.json';
        $temporary = $this->directory.'/journal.tmp';
        $handle = fopen($temporary, 'x');
        Profile::require(is_resource($handle), 'Journal could not be written.');
        chmod($temporary, 0600);
        $json = json_encode($this->state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        try {
            Profile::require(fwrite($handle, $json) === strlen($json) && fflush($handle), 'Journal write failed.');
            Profile::require(fsync($handle), 'Journal sync failed.');
        } finally {
            fclose($handle);
        }
        Profile::require(rename($temporary, $path), 'Journal could not be replaced.');
    }
}
