<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ApplicantVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct()
    {
        $this->afterCommit();
    }

    protected function buildMailMessage($url)
    {
        return parent::buildMailMessage($url)->line('Email verification does not activate your account. Administrator approval is also required.');
    }
}
