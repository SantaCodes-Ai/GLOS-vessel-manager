<?php

namespace Tests\Support\MySql\Concurrency;

use Tests\Support\MySql\Profile;

/** A fixed run-scoped allowlist, not a general relaxation of the clean-baseline guard. */
final class FixtureSet
{
    public static function verify(array $rows, string $run, array $manifest): void
    {
        Profile::require(preg_match('/\A[a-f0-9]{32}\z/', $run) === 1
            && array_keys($manifest) === NativeBackend::TABLES && array_keys($rows) === NativeBackend::TABLES, 'Invalid fixture manifest.');
        foreach (NativeBackend::TABLES as $table) {
            $count = in_array($table, ['users', 'vessels', 'vessel_user_access'], true) ? 1 : 0;
            Profile::require(is_array($rows[$table]) && is_array($manifest[$table]) && count($rows[$table]) === $count && count($manifest[$table]) === $count, 'Unexpected fixture count.');
            foreach ($rows[$table] as $id => $row) {
                Profile::require(ctype_digit((string) $id) && (int) $id > 0 && (string) ($row['id'] ?? '') === (string) $id
                    && is_string($manifest[$table][$id] ?? null) && hash_equals($manifest[$table][$id], Journal::fingerprint($row)), 'Fixture fingerprint changed.');
            }
        }
        $user = reset($rows['users']);
        $vessel = reset($rows['vessels']);
        $access = reset($rows['vessel_user_access']);
        Profile::require(($user['email'] ?? '') === 'fixture+'.$run.'@example.test' && ($user['name'] ?? '') === 'Concurrency '.$run
            && ($user['role'] ?? '') === 'client' && (string) ($user['is_active'] ?? '') === '0'
            && ($user['registration_status'] ?? '') === 'pending' && ($user['requested_role'] ?? '') === 'client'
            && array_key_exists('email_verified_at', $user) && $user['email_verified_at'] === null, 'Unsafe fixture account.');
        Profile::require(($vessel['vessel_name'] ?? '') === 'Concurrency '.$run && ($vessel['vessel_type'] ?? '') === 'Test Fixture'
            && ($vessel['flag_state'] ?? '') === 'TEST' && ($vessel['vessel_status'] ?? '') === 'inactive'
            && array_key_exists('imo_number', $vessel) && $vessel['imo_number'] === null
            && array_key_exists('official_number', $vessel) && $vessel['official_number'] === null, 'Unsafe fixture vessel.');
        Profile::require((string) ($access['user_id'] ?? '') === (string) $user['id']
            && (string) ($access['vessel_id'] ?? '') === (string) $vessel['id'], 'Fixture assignment has unowned parents.');
    }
}
