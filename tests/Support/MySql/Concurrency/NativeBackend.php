<?php

namespace Tests\Support\MySql\Concurrency;

use PDO;
use Tests\Support\MySql\DatabaseProbe;
use Tests\Support\MySql\Guard;
use Tests\Support\MySql\Profile;
use Throwable;

final class NativeBackend implements Backend
{
    // Child-first order. Protected baseline tables are deliberately absent.
    public const TABLES = ['certificate_versions', 'vessel_user_access', 'registration_decisions', 'privilege_audits', 'certificates', 'certificate_types', 'vessels', 'users'];

    public function __construct(private PDO $pdo, private Profile $profile, private Guard $guard)
    {
        Profile::require(! $profile->schema, 'Only the restricted runner is allowed.');
    }

    public function verify(bool $clean): void
    {
        $this->guard->prepared($this->profile, new DatabaseProbe($this->pdo), $clean);
        Profile::require(array_map('intval', $this->pdo->query('SELECT id FROM privilege_management_locks ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)) === [1], 'Unexpected privilege lock baseline.');
        if (! $clean) {
            // These infrastructure tables may never become worker fixtures.
            foreach (['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'password_reset_tokens', 'sessions'] as $table) {
                Profile::require((int) $this->pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn() === 0, 'Unexpected infrastructure records.');
            }
        }
    }

    public function mutexOwner(): int
    {
        return (int) $this->pdo->query("SELECT IS_USED_LOCK('glos_vessel_manager_test:integration-suite')")->fetchColumn();
    }

    public function rows(): array
    {
        return $this->snapshot(false);
    }

    private function snapshot(bool $lock): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = $this->pdo->query('SELECT * FROM `'.$table.'` ORDER BY id'.($lock ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
            // FETCH_UNIQUE removes the first column; include IDs explicitly for hashing.
            foreach ($rows[$table] as $id => &$row) {
                $row['id'] = $id;
            }
            unset($row);
        }

        return $rows;
    }

    public function createFixture(Journal $journal, int $mutexOwner): void
    {
        $this->verify(true);
        Profile::require($mutexOwner > 0 && $this->mutexOwner() === $mutexOwner && ! $this->pdo->inTransaction(), 'Unsafe fixture transaction.');
        $journal->planFixture(); // Durable fictional identifiers exist before the first INSERT.
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
        $attempted = false;
        try {
            Profile::require($this->pdo->beginTransaction(), 'Fixture transaction could not start.');
            $user = $this->pdo->prepare("INSERT INTO users (name, email, password, role, is_active, registration_status, requested_role, created_at, updated_at) VALUES (?, ?, ?, 'client', 0, 'pending', 'client', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            Profile::require($user->execute(['Concurrency '.$journal->run, 'fixture+'.$journal->run.'@example.test', password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT)]), 'Fixture insertion failed.');
            $userId = $this->capture('users', $journal);
            $vessel = $this->pdo->prepare("INSERT INTO vessels (vessel_name, vessel_type, flag_state, vessel_status, created_at, updated_at) VALUES (?, 'Test Fixture', 'TEST', 'inactive', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            Profile::require($vessel->execute(['Concurrency '.$journal->run]), 'Fixture insertion failed.');
            $vesselId = $this->capture('vessels', $journal);
            $access = $this->pdo->prepare('INSERT INTO vessel_user_access (user_id, vessel_id, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
            Profile::require($access->execute([$userId, $vesselId]), 'Fixture insertion failed.');
            $this->capture('vessel_user_access', $journal);
            $this->verify(false);
            $rows = $this->snapshot(true);
            $journal->assertRows($rows);
            FixtureSet::verify($rows, $journal->run, $journal->manifest());
            Profile::require($this->mutexOwner() === $mutexOwner, 'Fixture mutex ownership was lost.');
            $journal->lifecycle('prepared');
            $journal->lifecycle('commit-attempted'); // fsync before COMMIT; ambiguity never authorises cleanup.
            $attempted = true;
            Profile::require($this->pdo->commit(), 'Fixture commit was not acknowledged.');
            $journal->lifecycle('committed');
        } catch (Throwable $exception) {
            // A lost COMMIT response can mean committed data. Do not infer rollback from an exception.
            $rolledBack = false;
            try {
                if ($this->pdo->inTransaction()) {
                    $rolledBack = $this->pdo->rollBack();
                }
            } catch (Throwable) {
                // Preserve uncertainty; no retry or automatic repair.
            }
            $journal->lifecycle(! $attempted && $rolledBack && in_array($journal->stage(), ['planned', 'prepared'], true) ? 'rolled-back' : 'uncertain');
            throw $exception;
        }
    }

    private function capture(string $table, Journal $journal): int
    {
        Profile::require(in_array($table, ['users', 'vessels', 'vessel_user_access'], true), 'Unsafe fixture table.');
        $id = $this->pdo->lastInsertId();
        Profile::require(is_string($id) && ctype_digit($id) && (int) $id > 0, 'Fixture ID could not be determined.');
        $select = $this->pdo->prepare('SELECT * FROM `'.$table.'` WHERE id = ?');
        $select->execute([(int) $id]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        Profile::require(is_array($row) && (string) ($row['id'] ?? '') === $id, 'Fixture row could not be verified.');
        $journal->claim($table, $row);

        return (int) $id;
    }

    public function deleteExact(array $snapshot, int $mutexOwner): void
    {
        $this->verify(false);
        Profile::require($mutexOwner > 0 && $this->mutexOwner() === $mutexOwner, 'Cleanup mutex ownership was lost.');
        Profile::require(array_keys($snapshot) === self::TABLES && ! $this->pdo->inTransaction(), 'Unsafe cleanup transaction.');
        // Fixed per-session bound; no global settings or schema changes.
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
        $this->pdo->beginTransaction();
        try {
            Profile::require($this->snapshot(true) === $snapshot, 'Cleanup snapshot changed.');
            foreach (self::TABLES as $table) {
                $statement = $this->pdo->prepare('DELETE FROM `'.$table.'` WHERE id = ?');
                foreach ($snapshot[$table] as $id => $row) {
                    Profile::require(ctype_digit((string) $id) && (int) $id > 0, 'Invalid cleanup ID.');
                    $statement->execute([$id]);
                    Profile::require($statement->rowCount() === 1, 'Cleanup row changed.');
                }
            }
            $this->verify(true);
            Profile::require($this->mutexOwner() === $mutexOwner, 'Cleanup mutex ownership was lost.');
            Profile::require($this->pdo->commit(), 'Cleanup commit was not acknowledged.');
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
