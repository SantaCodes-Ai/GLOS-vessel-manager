<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecideRegistration
{
    public function decide(int $actorId, int $applicantId, string $decision): User
    {
        return DB::transaction(function () use ($actorId, $applicantId, $decision) {
            abort_unless(DB::table('privilege_management_locks')->where('id', 1)->lockForUpdate()->first(), 503);
            $actor = User::whereKey($actorId)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isAdmin() && $actor->canAccessApplication() && $actorId !== $applicantId, 403);
            $applicant = User::whereKey($applicantId)->lockForUpdate()->firstOrFail();
            if ($applicant->registration_status !== 'pending' || $applicant->role !== 'client' || $applicant->is_active
                || ! in_array($applicant->requested_role, ['admin', 'client'], true)
                || ! in_array($decision, ['approved', 'rejected'], true)) {
                throw ValidationException::withMessages(['decision' => 'This registration cannot be decided. It may already have a decision.']);
            }
            if ($decision === 'approved' && ! $applicant->hasVerifiedEmail()) {
                throw ValidationException::withMessages(['decision' => 'Email verification is required before approval.']);
            }
            $previous = $applicant->role;
            $applicant->registration_status = $decision;
            $applicant->is_active = $decision === 'approved';
            $applicant->role = $decision === 'approved' ? $applicant->requested_role : 'client';
            $applicant->save();
            DB::table('registration_decisions')->insert([
                'user_id' => $applicantId, 'actor_user_id' => $actorId, 'requested_role' => $applicant->requested_role,
                'decision' => $decision, 'previous_role' => $previous, 'new_role' => $applicant->role,
                'reason' => $decision === 'approved' ? 'Verified registration approved by administrator.' : 'Registration rejected by administrator.', 'created_at' => now(),
            ]);

            return $applicant;
        }, 3);
    }
}
