<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\ApplicantVerifyEmail;
use App\Notifications\RegistrationMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegistrationNotifications
{
    public function submitted(User $applicant): void
    {
        $this->verification($applicant);
        $reviewers = User::administrators()->where('is_active', true)
            ->when(config('registration.reviewer_ids'), fn ($query, $ids) => $query->whereIn('id', $ids))->get();
        $eligible = 0;
        $accepted = 0;
        foreach ($reviewers as $reviewer) {
            if ($reviewer->canAccessApplication()) {
                $eligible++;
                $accepted += (int) $this->send($reviewer, new RegistrationMessage('submitted', $applicant->requested_role));
            }
        }
        if ($eligible === 0) {
            Log::warning('No eligible administrator registration reviewers selected.');
        }
        // Dispatch acceptance is not proof of worker processing or SMTP delivery.
        Log::info('Registration reviewer notification dispatch completed.', [
            'eligible_reviewers' => $eligible, 'dispatches_accepted' => $accepted, 'queue_connection' => config('queue.default'),
        ]);
    }

    public function verification(User $applicant): void
    {
        $this->send($applicant, new ApplicantVerifyEmail);
    }

    public function decided(User $applicant): void
    {
        $this->send($applicant, new RegistrationMessage($applicant->registration_status));
    }

    private function send(User $recipient, Notification $notification): bool
    {
        try {
            $recipient->notify($notification);

            return true;
        } catch (Throwable $exception) {
            // Do not log notification payloads, verification URLs, credentials or mail transport errors.
            Log::error('Registration notification dispatch failed.', ['recipient_id' => $recipient->id, 'exception_type' => $exception::class]);

            return false;
        }
    }
}
