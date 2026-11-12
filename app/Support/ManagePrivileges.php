<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ManagePrivileges
{
    public function bootstrap(int $id, string $email, string $operator, string $reason): void
    {
        throw ValidationException::withMessages(['action' => 'Super Admin bootstrap is retired. Use administrator accounts and the explicit legacy-role conversion command.']);
    }

    public function change(int $actorId, int $targetId, string $action, string $password, string $reason, string $confirmedEmail): void
    {
        DB::transaction(function () use ($actorId, $targetId, $action, $password, $reason, $confirmedEmail) {
            $this->lock();
            $actor = User::whereKey($actorId)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isAdmin() && $actor->canAccessApplication(), 403);
            $this->require(Hash::check($password, $actor->password), 'Password confirmation failed.', 'password');
            $this->require(mb_strlen(trim($reason)) >= 5 && mb_strlen($reason) <= 1000, 'Provide a reason of 5–1000 characters.');
            $target = User::whereKey($targetId)->lockForUpdate()->firstOrFail();
            $this->require($target->email === $confirmedEmail, 'The target account changed. Review it and confirm again.');
            $this->require(in_array($target->role, ['admin', 'client', 'super_admin'], true) && $target->hasApprovedIdentity(), 'Only approved or legacy accounts can be managed here.');
            $role = $target->role;
            $active = $target->is_active;
            switch ($action) {
                case 'promote':
                    $this->require($role === UserRole::Client->value && $target->canAccessApplication(), 'Only an active, eligible staff member can be promoted.');
                    $role = UserRole::Admin->value;
                    break;
                case 'demote':
                    $this->require($target->isAdmin(), 'Only an administrator can be demoted.');
                    $role = UserRole::Client->value;
                    break;
                case 'activate':
                    $this->require(! $active, 'The account is already active.');
                    $this->require($target->hasApprovedIdentity(), 'Approval and email verification are required for this account.');
                    $active = true;
                    break;
                case 'deactivate':
                    $this->require($active, 'The account is already inactive.');
                    $active = false;
                    break;
                default:
                    $this->require(false, 'Invalid privilege action.');
            }
            if ($target->isAdmin() && $target->canAccessApplication() && ($role === UserRole::Client->value || ! $active)) {
                $activeAdmins = User::accessibleAdministrators()->orderBy('id')->lockForUpdate()->get(['id']);
                $this->require($activeAdmins->count() > 1, 'The last active administrator cannot be demoted or deactivated.');
            }
            $this->persist($target, $role, $active, $action, $reason, $actorId);
        }, 3);
    }

    private function lock(): object
    {
        // One pre-existing sentinel serializes bootstrap, role changes and status changes.
        $lock = DB::table('privilege_management_locks')->where('id', 1)->lockForUpdate()->first();
        $this->require($lock !== null, 'Privilege management is unavailable until its migration is applied.');

        return $lock;
    }

    private function persist(User $target, string $role, bool $active, string $action, string $reason, ?int $actorId, ?string $operator = null): void
    {
        $previousRole = $target->role;
        $previousActive = $target->is_active;
        $target->role = $role;
        $target->is_active = $active;
        $target->save();
        // Audit failure rolls back the account change; no passwords or hashes are recorded.
        DB::table('privilege_audits')->insert([
            'actor_user_id' => $actorId, 'operator_identifier' => $operator,
            'target_user_id' => $target->id, 'action' => $action,
            'previous_role' => $previousRole, 'new_role' => $role,
            'previous_is_active' => $previousActive, 'new_is_active' => $active,
            'reason' => $reason, 'created_at' => now(),
        ]);
    }

    private function require(bool $condition, string $message, string $field = 'action'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
