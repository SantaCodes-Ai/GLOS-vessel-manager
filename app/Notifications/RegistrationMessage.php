<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public string $kind, public string $requestedRole = '')
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->kind === 'submitted') {
            return (new MailMessage)->subject('New registration for review')
                ->line('A new '.match ($this->requestedRole) {
                    'client' => 'Staff', 'admin' => 'Administrator', default => 'account'
                }.' registration is ready for review.')
                ->line('Approval requires verified email and an administrator decision.')
                ->action('Review registrations', route('super-admin.registrations.index'));
        }

        return (new MailMessage)->subject($this->kind === 'approved' ? 'Registration approved' : 'Registration not approved')
            ->line($this->kind === 'approved' ? 'Your registration has been approved. You can now sign in.' : 'Your registration was not approved. Contact Global Link Ocean Streams if you need assistance.')
            ->action('Sign in', route('login'));
    }
}
