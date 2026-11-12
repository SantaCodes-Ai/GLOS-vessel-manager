<?php

namespace Tests\Support\MySql\Concurrency;

use Tests\Support\MySql\Profile;

final class Worker
{
    public static function authorise(array $lease, #[\SensitiveParameter] string $capability, Backend $backend, callable $alive): void
    {
        Profile::require(($lease['protocol'] ?? null) === 1 && ($lease['database'] ?? null) === Profile::DATABASE
            && is_int($lease['expires'] ?? null) && $lease['expires'] > time() && $lease['expires'] <= time() + 120
            && is_int($lease['pid'] ?? null) && $lease['pid'] > 1 && $alive($lease['pid'])
            && preg_match('/\A[a-f0-9]{64}\z/', $capability) === 1
            && hash_equals($lease['capability_hash'] ?? '', hash('sha256', $capability)), 'Missing or expired coordinator authorisation.');
        $action = $lease['action'] ?? 'probe';
        Profile::require(in_array($action, ['probe', 'fixture-probe'], true), 'Unknown worker action.');
        if ($action === 'fixture-probe') {
            Profile::require(($lease['scenario'] ?? '') === 'fixture-lifecycle-v1' && is_array($lease['fixtures'] ?? null), 'Missing fixture scenario authorisation.');
            $backend->verify(false);
            FixtureSet::verify($backend->rows(), $lease['run'] ?? '', $lease['fixtures']);
        } else {
            $backend->verify(true);
        }
        Profile::require(is_int($lease['mutex_owner'] ?? null) && $lease['mutex_owner'] > 0
            && $backend->mutexOwner() === $lease['mutex_owner'], 'Coordinator no longer owns the suite mutex.');
    }

    public static function readRequest($stream, int $milliseconds = 2000): array
    {
        Profile::require(is_resource($stream) && $milliseconds > 0 && $milliseconds <= 2000, 'Invalid worker input deadline.');
        $deadline = hrtime(true) + $milliseconds * 1000000;
        $input = '';
        do {
            $remaining = max(0, $deadline - hrtime(true));
            $read = [$stream];
            $write = $except = [];
            Profile::require($remaining > 0 && stream_select($read, $write, $except, intdiv($remaining, 1000000000), intdiv($remaining % 1000000000, 1000)) > 0, 'Worker authorisation input timed out.');
            $byte = fread($stream, 1);
            Profile::require($byte !== false && $byte !== '', 'Incomplete worker input.');
            $input .= $byte;
            Profile::require(strlen($input) <= 8192, 'Worker input is too large.');
        } while ($byte !== "\n");
        $request = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
        Profile::require(is_array($request) && array_keys($request) === ['lease', 'capability', 'action'], 'Invalid worker input.');

        return $request;
    }

    public static function readLease(string $path): array
    {
        $directory = dirname($path);
        Profile::require(basename($path) === 'lease.json' && preg_match('/\Aglos-concurrency-[a-f0-9]{32}\z/', basename($directory)) === 1
            && realpath(dirname($directory)) === realpath(sys_get_temp_dir()) && ! is_link($directory) && ! is_link($path)
            && is_file($path) && (fileperms($directory) & 0077) === 0 && (fileperms($path) & 0077) === 0
            && filesize($path) <= 8192, 'Invalid private coordinator lease.');

        return json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    }
}
