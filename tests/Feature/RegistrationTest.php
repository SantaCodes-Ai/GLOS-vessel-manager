<?php

use App\Models\User;
use App\Notifications\ApplicantVerifyEmail;
use App\Notifications\RegistrationMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function applicantInput(array $overrides = []): array
{
    return array_replace(['name' => 'New Applicant', 'email' => 'applicant@example.com', 'password' => 'ApplicantSecure!123', 'password_confirmation' => 'ApplicantSecure!123', 'department' => 'Operations', 'requested_role' => 'client'], $overrides);
}

beforeEach(function () {
    Notification::fake();
    $this->reviewer = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
});

test('registration displays branded fields without super admin selection', function () {
    $this->get('/register')->assertOk()->assertSee('Global Link Ocean Streams')->assertSee('Create Account')->assertSee('Administrator')->assertSee('Staff')->assertDontSee('value="super_admin"', false);
});

test('public requests remain inactive pending clients with separate requested roles', function (string $role) {
    $this->post('/register', applicantInput(['requested_role' => $role]))->assertSessionHasNoErrors()->assertRedirect(route('registration.notice'));
    $user = User::where('email', 'applicant@example.com')->sole();
    expect($user->role)->toBe('client')->and($user->requested_role)->toBe($role)->and($user->registration_status)->toBe('pending')
        ->and($user->is_active)->toBeFalse()->and($user->email_verified_at)->toBeNull()->and(Hash::check('ApplicantSecure!123', $user->password))->toBeTrue();
    $this->assertGuest();
    Notification::assertSentTo($user, ApplicantVerifyEmail::class);
    Notification::assertSentTo($this->reviewer, RegistrationMessage::class, fn ($n) => $n->kind === 'submitted');
})->with(['client', 'admin']);

test('registration validates required fields and rejects privilege input', function (array $override, string $field) {
    $this->post('/register', applicantInput($override))->assertSessionHasErrors($field);
    $this->assertDatabaseCount('users', 1);
    expect(session()->getOldInput('password'))->toBeNull();
})->with([
    [['name' => ''], 'name'], [['email' => ''], 'email'], [['email' => 'invalid'], 'email'], [['password' => ''], 'password'],
    [['password_confirmation' => 'different'], 'password'], [['requested_role' => ''], 'requested_role'], [['requested_role' => 'super_admin'], 'requested_role'],
    [['role' => 'admin'], 'role'], [['is_active' => true], 'is_active'], [['registration_status' => 'approved'], 'registration_status'],
    [['email_verified_at' => '2026-01-01'], 'email_verified_at'], [['website' => 'spam.example'], 'website'],
]);

test('password byte boundaries handle ASCII multibyte and null characters', function (string $password, bool $valid) {
    $response = $this->post('/register', applicantInput(['password' => $password, 'password_confirmation' => $password]));
    $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('password');
    $this->assertDatabaseCount('users', $valid ? 2 : 1);
})->with([
    ['Aa1!'.str_repeat('x', 68), true], ['Aa1!'.str_repeat('x', 69), false],
    ['Aa1!'.str_repeat('é', 34), true], ['Aa1!'.str_repeat('é', 35), false], ["Applicant!123\0secret", false], ['short', false],
]);

test('duplicate email requests return generic success and preserve the account', function () {
    $this->post('/register', applicantInput())->assertSessionHasNoErrors();
    $message = session('status');
    $before = User::where('email', 'applicant@example.com')->sole()->getAttributes();
    $this->post('/register', applicantInput(['email' => ' APPLICANT@example.com ', 'name' => 'Changed']))->assertSessionHas('status', $message)->assertSessionHasNoErrors();
    expect(User::find($before['id'])->getAttributes())->toBe($before);
    $this->assertDatabaseCount('users', 2);
    Notification::assertSentToTimes(User::find($before['id']), ApplicantVerifyEmail::class, 1);
});

test('database duplicate-email races return generic success', function () {
    User::creating(function () {
        throw new UniqueConstraintViolationException('sqlite', 'insert', [], new PDOException('duplicate'));
    });
    $this->post('/register', applicantInput())->assertRedirect(route('registration.notice'))->assertSessionHasNoErrors();
    $this->assertDatabaseCount('users', 1);
});

test('signed verification works without login and never activates applicants', function () {
    $this->post('/register', applicantInput());
    $user = User::where('email', 'applicant@example.com')->sole();
    $url = (new ApplicantVerifyEmail)->toMail($user)->actionUrl;
    $this->get($url)->assertOk()->assertSee('Email Verified Successfully')->assertSee('Awaiting Administrator Approval');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()->and($user->fresh()->is_active)->toBeFalse()->and($user->fresh()->registration_status)->toBe('pending');
    $this->assertGuest();
    $this->get($url)->assertOk();
});

test('expired tampered and wrong-identity verification links fail', function (string $kind) {
    $user = User::factory()->unverified()->create(['registration_status' => 'pending', 'department' => 'Operations', 'requested_role' => 'client', 'is_active' => false]);
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    if ($kind === 'expired') {
        $this->travel(61)->minutes();
    } elseif ($kind === 'tampered') {
        $url .= '&tampered=1';
    } else {
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('wrong@example.com')]);
    }
    $this->get($url)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
})->with(['expired', 'tampered', 'wrong-hash']);

test('resends are generic and rate limited', function () {
    $user = User::factory()->unverified()->create(['email' => 'pending@example.com', 'registration_status' => 'pending', 'is_active' => false]);
    $this->post(route('verification.send'), ['email' => $user->email])->assertSessionHasNoErrors();
    $message = session('status');
    $this->post(route('verification.send'), ['email' => 'unknown@example.com'])->assertSessionHas('status', $message);
    Notification::assertSentTo($user, ApplicantVerifyEmail::class);
    for ($i = 0; $i < 2; $i++) {
        $this->post(route('verification.send'), ['email' => $user->email])->assertRedirect();
    }
    $this->post(route('verification.send'), ['email' => $user->email])->assertStatus(429);
});

test('registration is rate limited by IP', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/register', applicantInput(['email' => 'applicant'.$i.'@example.com']))->assertRedirect();
    }
    $this->post('/register', applicantInput(['email' => 'sixth@example.com']))->assertStatus(429);
});

test('notification dispatch failure leaves registrations pending', function () {
    Notification::shouldReceive('send')->andThrow(new RuntimeException('Mail transport unavailable'));
    $this->post('/register', applicantInput())->assertRedirect(route('registration.notice'))->assertSessionHasNoErrors();
    expect(User::where('email', 'applicant@example.com')->sole()->is_active)->toBeFalse();
});

test('designated reviewers and notification content contain no credentials', function () {
    Mail::fake();
    $other = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    config(['registration.reviewer_ids' => [$other->id]]);
    $this->post('/register', applicantInput());
    $user = User::where('email', 'applicant@example.com')->sole();
    Notification::assertNotSentTo($this->reviewer, RegistrationMessage::class);
    Notification::assertSentTo($other, RegistrationMessage::class);
    $html = (string) (new ApplicantVerifyEmail)->toMail($user)->render();
    expect($html)->not->toContain('ApplicantSecure!123', $user->password)->and($html)->toContain('signature=');
    Mail::assertNothingSent();
});
