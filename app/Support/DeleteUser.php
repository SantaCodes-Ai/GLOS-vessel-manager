<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteUser
{
    public function delete(int $actorId, int $targetId, string $expectedFingerprint): void
    {
        DB::transaction(function () use ($actorId, $targetId, $expectedFingerprint) {
            // Same lock order as promotion, demotion and approval.
            abort_unless(DB::table('privilege_management_locks')->where('id', 1)->lockForUpdate()->first(), 503);
            $actor = User::whereKey($actorId)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isAdmin() && $actor->canAccessApplication() && $actorId !== $targetId, 403);
            $target = User::whereKey($targetId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($target->role, ['admin', 'client', 'super_admin'], true), 403);
            if (! hash_equals(self::fingerprint($target), $expectedFingerprint) || ! in_array($target->registration_status, [null, 'pending', 'approved', 'rejected'], true)) {
                throw ValidationException::withMessages(['confirmation' => 'The account changed or has an unsupported status. Review it again before deleting.']);
            }
            if ($target->isAdmin() && $target->canAccessApplication()) {
                $activeAdmins = User::accessibleAdministrators()->orderBy('id')->lockForUpdate()->get(['id']);
                if ($activeAdmins->count() <= 1) {
                    throw ValidationException::withMessages(['confirmation' => 'The last active administrator cannot be deleted.']);
                }
            }
            DB::table('privilege_audits')->insert([
                'actor_user_id' => $actorId, 'operator_identifier' => null, 'target_user_id' => $target->id,
                'action' => 'delete_user', 'previous_role' => $target->role, 'new_role' => 'deleted',
                'previous_is_active' => $target->is_active, 'new_is_active' => false,
                'reason' => 'Permanent user deletion explicitly confirmed. Registration status: '.($target->registration_status ?? 'legacy').'.', 'created_at' => now(),
            ]);
            $target->vessels()->detach();
            DB::table('password_reset_tokens')->where('email', $target->email)->delete();
            // The users migration creates this table on the primary database.
            DB::table('sessions')->where('user_id', $target->id)->delete();
            // Existing SET NULL / CASCADE constraints remain enabled. No filesystem work.
            $target->delete();
        }, 3);
    }

    public static function fingerprint(User $user): string
    {
        return hash('sha256', json_encode([$user->id, $user->email, $user->role, $user->registration_status, $user->is_active, $user->getRawOriginal('updated_at')]));
    }
}
