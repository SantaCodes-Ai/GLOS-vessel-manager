<?php

namespace Tests\Integration\MySql;

use App\Livewire\Auth\Login;
use App\Models\User;
use App\Notifications\ApplicantVerifyEmail;
use App\Notifications\RegistrationMessage;
use App\Support\DecideRegistration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\MySql\Support\AccountFixtures;
use Tests\MySqlTestCase;

#[Group('mysql-accounts')]
final class RegistrationApprovalAccountsTest extends MySqlTestCase
{
    use AccountFixtures;

    #[DataProvider('requestedRoles')]
    public function test_public_registration_verification_and_admin_approval_use_mysql(string $requested): void
    {
        config(['registration.reviewer_ids' => []]);
        $admin = $this->account('admin');
        $this->post('/register', $this->registrationInput(['requested_role' => $requested]))
            ->assertSessionHasNoErrors()->assertRedirect(route('registration.notice'));
        $applicant = User::where('email', 'applicant@example.test')->sole();
        $this->assertSame('client', $applicant->role);
        $this->assertSame($requested, $applicant->requested_role);
        $this->assertSame('Marine Operations', $applicant->department);
        $this->assertSame('pending', $applicant->registration_status);
        $this->assertFalse($applicant->is_active);
        $this->assertNull($applicant->email_verified_at);
        $this->assertTrue(Hash::check('ApplicantSecure!123', $applicant->password));
        $hash = $applicant->password;
        $this->assertGuest();
        Notification::assertSentTo($applicant, ApplicantVerifyEmail::class);
        Notification::assertSentTo($admin, RegistrationMessage::class, fn ($message) => $message->kind === 'submitted');
        $url = (new ApplicantVerifyEmail)->toMail($applicant)->actionUrl;
        $this->get($url)->assertOk()->assertSee('Email Verified Successfully');
        $this->assertTrue($applicant->fresh()->hasVerifiedEmail());
        $this->assertFalse($applicant->fresh()->is_active);
        $this->assertSame('pending', $applicant->fresh()->registration_status);
        $this->actingAs($admin)->post(route('super-admin.registrations.decide', $applicant), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $user = $applicant->fresh();
        $this->assertTrue($user->is_active);
        $this->assertSame('approved', $user->registration_status);
        $this->assertSame($requested, $user->role);
        $this->assertSame($hash, $user->password);
        $this->assertDatabaseHas('registration_decisions', ['user_id' => $user->id, 'actor_user_id' => $admin->id, 'requested_role' => $requested, 'decision' => 'approved', 'new_role' => $requested]);
        Notification::assertSentTo($user, RegistrationMessage::class, fn ($message) => $message->kind === 'approved');
        Mail::assertNothingSent();
        Auth::logout();
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'ApplicantSecure!123')->call('login')
            ->assertHasNoErrors()->assertRedirect(route($requested.'.dashboard'));
    }

    public static function requestedRoles(): array
    {
        return [['client'], ['admin']];
    }

    public function test_custom_department_is_stored_without_granting_privileges(): void
    {
        $this->post('/register', $this->registrationInput(['department' => 'Other', 'department_other' => 'Technical Assurance', 'requested_role' => 'admin']))->assertSessionHasNoErrors();
        $user = User::where('email', 'applicant@example.test')->sole();
        $this->assertSame('Technical Assurance', $user->department);
        $this->assertSame('client', $user->role);
        $this->assertFalse($user->is_active);
    }

    public function test_duplicate_email_is_generic_and_mysql_uniqueness_is_enforced(): void
    {
        $existing = $this->account('admin', ['email' => 'duplicate@example.test']);
        $before = $existing->getAttributes();
        $this->post('/register', $this->registrationInput(['email' => ' DUPLICATE@example.test ']))->assertSessionHasNoErrors()->assertRedirect(route('registration.notice'));
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        Notification::assertNothingSent();
        $this->assertMysqlConstraint(fn () => $this->account('client', ['email' => 'DUPLICATE@example.test']), 1062);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_rejection_stays_inactive_and_keeps_the_recorded_decision(): void
    {
        $admin = $this->account('admin');
        $applicant = $this->applicant('admin', ['email_verified_at' => now()]);
        $this->actingAs($admin)->post(route('super-admin.registrations.decide', $applicant), ['decision' => 'rejected'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $applicant->fresh()->registration_status);
        $this->assertSame('client', $applicant->fresh()->role);
        $this->assertFalse($applicant->fresh()->is_active);
        $decision = (array) DB::table('registration_decisions')->where('user_id', $applicant->id)->sole();
        $this->post(route('super-admin.registrations.decide', $applicant), ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->assertSame($decision, (array) DB::table('registration_decisions')->where('user_id', $applicant->id)->sole());
        Notification::assertSentTo($applicant, RegistrationMessage::class, fn ($message) => $message->kind === 'rejected');
        Auth::logout();
        Livewire::test(Login::class)->set('email', $applicant->email)->set('password', 'password')->call('login')->assertHasErrors('email');
    }

    public function test_unverified_registration_cannot_be_approved(): void
    {
        $admin = $this->account('admin');
        $applicant = $this->applicant('admin');
        $this->actingAs($admin)->post(route('super-admin.registrations.decide', $applicant), ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->assertFalse($applicant->fresh()->is_active);
        $this->assertDatabaseCount('registration_decisions', 0);
    }

    #[DataProvider('unauthorisedActors')]
    public function test_approval_and_rejection_require_an_active_admin(string $actor): void
    {
        $applicant = $this->applicant('admin', ['email_verified_at' => now()]);
        if ($actor !== 'guest') {
            $this->actingAs($this->account($actor === 'staff' ? 'client' : 'admin', ['is_active' => $actor !== 'inactive']));
        }
        foreach (['approved', 'rejected'] as $decision) {
            $response = $this->post(route('super-admin.registrations.decide', $applicant), compact('decision'));
            $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
        }
        $this->assertDatabaseCount('registration_decisions', 0);
        $this->assertFalse($applicant->fresh()->is_active);
    }

    public static function unauthorisedActors(): array
    {
        return [['staff'], ['guest'], ['inactive']];
    }

    public function test_approval_is_atomic_when_mysql_rejects_a_duplicate_decision_audit(): void
    {
        $admin = $this->account('admin');
        $applicant = $this->applicant('admin', ['email_verified_at' => now()]);
        DB::table('registration_decisions')->insert($this->decisionRow($applicant, $admin));
        $before = (array) DB::table('registration_decisions')->sole();
        $this->assertMysqlConstraint(fn () => app(DecideRegistration::class)->decide($admin->id, $applicant->id, 'approved'), 1062);
        $this->assertSame('client', $applicant->fresh()->role);
        $this->assertSame('pending', $applicant->fresh()->registration_status);
        $this->assertFalse($applicant->fresh()->is_active);
        $this->assertSame($before, (array) DB::table('registration_decisions')->sole());
        Notification::assertNothingSent();
    }

    #[DataProvider('laterDecisions')]
    public function test_repeat_or_conflicting_approval_cannot_change_a_recorded_service_decision(string $later): void
    {
        $admin = $this->account('admin');
        $applicant = $this->applicant('admin', ['email_verified_at' => now()]);
        $this->actingAs($admin)->post(route('super-admin.registrations.decide', $applicant), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $before = (array) DB::table('registration_decisions')->where('user_id', $applicant->id)->sole();
        $this->post(route('super-admin.registrations.decide', $applicant), ['decision' => $later])->assertSessionHasErrors('decision');
        $this->assertSame($before, (array) DB::table('registration_decisions')->where('user_id', $applicant->id)->sole());
        $this->assertDatabaseCount('registration_decisions', 1);
        $this->assertSame('admin', $applicant->fresh()->role);
        $this->assertSame('approved', $applicant->fresh()->registration_status);
        $this->assertTrue($applicant->fresh()->is_active);
        Notification::assertSentToTimes($applicant, RegistrationMessage::class, 1);
    }

    public static function laterDecisions(): array
    {
        return [['approved'], ['rejected']];
    }
}
