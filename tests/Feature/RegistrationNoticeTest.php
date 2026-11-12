<?php

use App\Models\User;
use App\Notifications\ApplicantVerifyEmail;
use App\Notifications\RegistrationMessage;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Notification::fake();
    $this->applicant = User::factory()->unverified()->create([
        'role' => 'client', 'department' => 'Operations', 'requested_role' => 'client', 'registration_status' => 'pending', 'is_active' => false,
    ]);
});

function noticeVerificationUrl(User $user): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
}

test('unverified notice offers verification and resend without revealing an account', function () {
    $this->get(route('registration.notice'))->assertOk()->assertSee('Email Verification Required')->assertSee('Resend verification email');
    $this->assertGuest();
});

test('verified pending notice hides the email form and reloads authoritative state', function () {
    $this->get(noticeVerificationUrl($this->applicant))->assertOk()->assertSee('Email Verified Successfully')
        ->assertSee('Awaiting Administrator Approval')->assertDontSee('Resend verification email')->assertDontSee('name="email"', false);
    $this->get(route('registration.notice'))->assertOk()->assertSee('Awaiting Administrator Approval')->assertDontSee('Resend verification email');
    expect($this->applicant->fresh()->registration_status)->toBe('pending')->and($this->applicant->fresh()->is_active)->toBeFalse();
    $this->assertGuest();
});

test('approved notice reflects a later decision without resending or changing state', function () {
    $this->get(noticeVerificationUrl($this->applicant))->assertOk();
    $this->applicant->refresh();
    $this->applicant->registration_status = 'approved';
    $this->applicant->is_active = true;
    $this->applicant->save();
    $before = $this->applicant->fresh()->getAttributes();
    $this->get(route('registration.notice'))->assertOk()->assertSee('Registration Approved')->assertSee('You can now log in')
        ->assertSee(route('login'), false)->assertDontSee('Resend verification email');
    expect($this->applicant->fresh()->getAttributes())->toBe($before);
});

test('rejected notice contains no internal decision details or resend form', function () {
    $this->applicant->registration_status = 'rejected';
    $this->applicant->save();
    $before = $this->applicant->fresh()->getAttributes();
    $this->get(noticeVerificationUrl($this->applicant))->assertOk()->assertSee('Registration Not Approved')->assertDontSee('Resend verification email');
    $this->get(route('registration.notice'))->assertSee('Registration Not Approved');
    expect($this->applicant->fresh()->getAttributes())->toBe($before);
});

test('notice ignores untrusted IDs and status flags in URL parameters', function () {
    $this->get(route('registration.notice', ['id' => $this->applicant->id, 'verified' => true, 'registration_status' => 'approved']))
        ->assertOk()->assertSee('Email Verification Required')->assertDontSee('Registration Approved')->assertDontSee('Email Verified Successfully');
});

test('email changes invalidate old notice identity and tampered links do not establish context', function () {
    $url = noticeVerificationUrl($this->applicant);
    $this->get($url)->assertOk();
    $this->applicant->email = 'changed@example.com';
    $this->applicant->save();
    $this->get(route('registration.notice'))->assertSee('Email Verification Required')->assertDontSee('Email Verified Successfully');
    $this->flushSession();
    $this->get($url.'&tampered=1')->assertForbidden();
    $this->get(route('registration.notice'))->assertSee('Email Verification Required');
});

test('approved but inactive and approved unverified accounts are not presented as ready to log in', function () {
    $this->get(noticeVerificationUrl($this->applicant))->assertOk();
    $this->applicant->refresh();
    $this->applicant->registration_status = 'approved';
    $this->applicant->save();
    $this->get(route('registration.notice'))->assertSee('currently inactive')->assertDontSee('You can now log in');
    $this->applicant->email_verified_at = null;
    $this->applicant->save();
    $this->get(route('registration.notice'))->assertSee('verify your current email')->assertSee('Resend verification email');
});

test('reviewer notifications select active eligible super admins only', function () {
    $active = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $inactive = User::factory()->create(['role' => 'super_admin', 'is_active' => false]);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $pending = User::factory()->unverified()->create(['role' => 'super_admin', 'is_active' => true, 'registration_status' => 'pending']);
    $this->post('/register', ['name' => 'New Applicant', 'email' => 'fresh@example.com', 'department' => 'Operations', 'requested_role' => 'admin', 'password' => 'ApplicantSecure!123', 'password_confirmation' => 'ApplicantSecure!123'])->assertSessionHasNoErrors();
    Notification::assertSentToTimes($active, RegistrationMessage::class, 1);
    Notification::assertNotSentTo($inactive, RegistrationMessage::class);
    Notification::assertSentTo($admin, RegistrationMessage::class);
    Notification::assertNotSentTo($pending, RegistrationMessage::class);
});

test('registration queues one job per recipient and verification never duplicates reviewer notifications', function () {
    config(['queue.default' => 'database']);
    // Use the real notification dispatcher and fake only the queue: no SMTP or database jobs execute.
    Notification::swap(new ChannelManager($this->app));
    Queue::fake();
    $first = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $second = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    User::factory()->create(['role' => 'super_admin', 'is_active' => false]);
    $input = ['name' => 'Queued Applicant', 'email' => 'queued@example.com', 'department' => 'Operations', 'requested_role' => 'client', 'password' => 'ApplicantSecure!123', 'password_confirmation' => 'ApplicantSecure!123'];
    $this->post('/register', $input)->assertSessionHasNoErrors();
    $user = User::where('email', $input['email'])->sole();
    Queue::assertPushed(SendQueuedNotifications::class, 3);
    foreach ([$first, $second] as $reviewer) {
        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->notification instanceof RegistrationMessage
            && $job->notification->kind === 'submitted' && $job->notifiables->contains('id', $reviewer->id)
            && $job->afterCommit === true && $job->tries === 3 && $job->backoff() === [60, 300]);
    }
    Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->notification instanceof ApplicantVerifyEmail && $job->notifiables->contains('id', $user->id));
    $this->post('/register', $input)->assertSessionHasNoErrors();
    $this->get(noticeVerificationUrl($user))->assertOk();
    $this->get(noticeVerificationUrl($user))->assertOk();
    $this->post(route('verification.send'), ['email' => $user->email])->assertRedirect();
    Queue::assertPushed(SendQueuedNotifications::class, 3);
});
