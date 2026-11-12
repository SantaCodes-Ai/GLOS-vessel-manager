<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use App\Notifications\ApplicantVerifyEmail;
use App\Notifications\RegistrationMessage;
use App\Support\DecideRegistration;
use App\Support\ManagePrivileges;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Notification::fake();
    $this->super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $this->applicant = User::factory()->create(['role' => 'client', 'department' => 'Operations', 'requested_role' => 'client', 'registration_status' => 'pending', 'is_active' => false]);
    $this->decisionInput = ['decision' => 'approved'];
});

test('super admins review pending registrations and prior decisions', function () {
    $this->actingAs($this->super)->get(route('super-admin.registrations.index'))->assertOk()->assertSee($this->applicant->email)->assertSee('Pending');
    $this->get(route('super-admin.registrations.show', $this->applicant))->assertOk()->assertSee('Approve')->assertDontSee('Your current password');
    $this->post(route('super-admin.registrations.decide', $this->applicant), $this->decisionInput)->assertSessionHasNoErrors();
    $this->get(route('super-admin.registrations.show', $this->applicant))->assertSee('Recorded decision')->assertSee('Verified registration approved by administrator.');
});

test('approval grants only the requested verified role and applicant can sign in', function (string $requested) {
    $this->applicant->requested_role = $requested;
    $this->applicant->save();
    $hash = $this->applicant->password;
    $this->actingAs($this->super)->post(route('super-admin.registrations.decide', $this->applicant), $this->decisionInput)->assertSessionHasNoErrors();
    $user = $this->applicant->fresh();
    expect($user->role)->toBe($requested)->and($user->is_active)->toBeTrue()->and($user->registration_status)->toBe('approved')->and($user->password)->toBe($hash);
    $this->assertDatabaseHas('registration_decisions', ['user_id' => $user->id, 'actor_user_id' => $this->super->id, 'decision' => 'approved', 'requested_role' => $requested, 'new_role' => $requested]);
    Notification::assertSentTo($user, RegistrationMessage::class, fn ($n) => $n->kind === 'approved');
    Auth::logout();
    Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('login')->assertHasNoErrors()->assertRedirect(route($requested.'.dashboard'));
})->with(['client', 'admin']);

test('rejected registrations remain unable to log in', function () {
    $this->actingAs($this->super)->post(route('super-admin.registrations.decide', $this->applicant), array_replace($this->decisionInput, ['decision' => 'rejected']))->assertSessionHasNoErrors();
    $user = $this->applicant->fresh();
    expect($user->is_active)->toBeFalse()->and($user->registration_status)->toBe('rejected');
    Notification::assertSentTo($user, RegistrationMessage::class, fn ($n) => $n->kind === 'rejected');
    Auth::logout();
    Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('login')->assertHasErrors('email');
});

test('unverified applicants cannot be approved', function () {
    $this->applicant->email_verified_at = null;
    $this->applicant->save();
    $this->actingAs($this->super)->post(route('super-admin.registrations.decide', $this->applicant), $this->decisionInput)->assertSessionHasErrors('decision');
    expect($this->applicant->fresh()->is_active)->toBeFalse();
    $this->assertDatabaseCount('registration_decisions', 0);
});

test('pending or unverified public users cannot access protected routes even if incorrectly activated', function (string $status) {
    $this->applicant->registration_status = $status;
    $this->applicant->is_active = true;
    $this->applicant->email_verified_at = null;
    $this->applicant->save();
    Livewire::test(Login::class)->set('email', $this->applicant->email)->set('password', 'password')->call('login')->assertHasErrors('email');
    $this->assertGuest();
    $this->actingAs($this->applicant)->get(route('client.dashboard'))->assertForbidden();
})->with(['pending', 'rejected', 'approved']);

test('approvals require an active super admin', function (string $actor) {
    if ($actor !== 'guest') {
        $this->actingAs(User::factory()->create(['role' => $actor === 'inactive' ? 'super_admin' : $actor, 'is_active' => $actor !== 'inactive']));
    }
    foreach ([['GET', route('super-admin.registrations.index')], ['GET', route('super-admin.registrations.show', $this->applicant)], ['POST', route('super-admin.registrations.decide', $this->applicant)]] as [$method, $url]) {
        $response = $this->call($method, $url, $this->decisionInput);
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
    }
    $this->assertDatabaseCount('registration_decisions', 0);
})->with(['guest', 'client', 'inactive']);

test('decisions validate action and reject privilege overrides', function (array $override, string $field) {
    $this->actingAs($this->super)->post(route('super-admin.registrations.decide', $this->applicant), array_replace($this->decisionInput, $override))->assertSessionHasErrors($field);
    $this->assertDatabaseCount('registration_decisions', 0);
    expect(session()->getOldInput('password'))->toBeNull();
})->with([
    [['decision' => 'super_admin'], 'decision'], [['role' => 'super_admin'], 'role'],
    [['department' => 'Operations', 'requested_role' => 'admin'], 'requested_role'], [['is_active' => true], 'is_active'],
]);

test('duplicate and conflicting decisions cannot alter a prior decision', function (string $later) {
    $this->actingAs($this->super)->post(route('super-admin.registrations.decide', $this->applicant), $this->decisionInput)->assertSessionHasNoErrors();
    $this->post(route('super-admin.registrations.decide', $this->applicant), array_replace($this->decisionInput, ['decision' => $later]))->assertSessionHasErrors('decision');
    $this->assertDatabaseCount('registration_decisions', 1);
    expect($this->applicant->fresh()->registration_status)->toBe('approved');
})->with(['approved', 'rejected']);

test('decision attempts are throttled', function () {
    $this->actingAs($this->super);
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('super-admin.registrations.decide', $this->applicant), array_replace($this->decisionInput, ['decision' => 'invalid']))->assertSessionHasErrors('decision');
    }
    $this->post(route('super-admin.registrations.decide', $this->applicant), $this->decisionInput)->assertStatus(429);
    $this->assertDatabaseCount('registration_decisions', 0);
});

test('decision processing requests the privilege sentinel lock before user locks', function () {
    $connection = DB::connection();
    $previous = $connection->getQueryGrammar();
    $grammar = new class($connection) extends SQLiteGrammar
    {
        public array $locks = [];

        public function compileSelect(Builder $query)
        {
            if ($query->lock === true) {
                $this->locks[] = $query->from;
            }

            return parent::compileSelect($query);
        }
    };
    $connection->setQueryGrammar($grammar);
    try {
        app(DecideRegistration::class)->decide($this->super->id, $this->applicant->id, 'approved');
        expect($grammar->locks)->toBe(['privilege_management_locks', 'users', 'users']);
    } finally {
        $connection->setQueryGrammar($previous);
    }
});

test('decision audit failure rolls back activation and role assignment', function () {
    $this->applicant->requested_role = 'admin';
    $this->applicant->save();
    DB::connection()->beforeExecuting(function ($query) {
        if (str_starts_with(strtolower($query), 'insert into "registration_decisions"')) {
            throw new RuntimeException('Audit unavailable');
        }
    });
    expect(fn () => app(DecideRegistration::class)->decide($this->super->id, $this->applicant->id, 'approved'))->toThrow(RuntimeException::class);
    expect($this->applicant->fresh()->role)->toBe('client')->and($this->applicant->fresh()->is_active)->toBeFalse()->and($this->applicant->fresh()->registration_status)->toBe('pending');
});

test('notification failure cannot decide a pending account but does not undo a legitimate decision', function () {
    Notification::shouldReceive('send')->andThrow(new RuntimeException('Mail transport unavailable'));
    $this->actingAs($this->super)->post(route('super-admin.registrations.decide', $this->applicant), $this->decisionInput)->assertSessionHasNoErrors();
    expect($this->applicant->fresh()->registration_status)->toBe('approved');
    $this->assertDatabaseCount('registration_decisions', 1);
});

test('self approval and requested super admin privileges are refused by the service', function () {
    expect(fn () => app(DecideRegistration::class)->decide($this->super->id, $this->super->id, 'approved'))->toThrow(HttpException::class);
    $this->applicant->requested_role = 'super_admin';
    $this->applicant->save();
    expect(fn () => app(DecideRegistration::class)->decide($this->super->id, $this->applicant->id, 'approved'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('registration_decisions', 0);
});

test('ordinary client editing cannot activate pending or rejected applicants', function (string $state) {
    $this->applicant->registration_status = $state;
    $this->applicant->save();
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->actingAs($admin)->get(route('admin.clients.index'))->assertDontSee($this->applicant->email);
    $this->get(route('admin.clients.edit', $this->applicant))->assertNotFound();
    $this->put(route('admin.clients.update', $this->applicant), ['name' => 'Activate', 'email' => $this->applicant->email, 'is_active' => true])->assertForbidden();
})->with(['pending', 'rejected']);

test('changing an approved public clients email clears verification and requests re-verification', function () {
    $this->applicant->registration_status = 'approved';
    $this->applicant->is_active = true;
    $this->applicant->save();
    $this->actingAs($this->super)->put(route('admin.clients.update', $this->applicant), ['name' => 'Updated', 'email' => 'changed@example.com', 'is_active' => true])->assertSessionHasNoErrors();
    expect($this->applicant->fresh()->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($this->applicant, ApplicantVerifyEmail::class);
});

test('legacy accounts keep access without new verification requirements', function () {
    $legacy = User::factory()->unverified()->create(['role' => 'client', 'is_active' => true]);
    expect($legacy->registration_status)->toBeNull();
    $this->actingAs($legacy)->get(route('client.dashboard'))->assertOk();
});

test('unverified public administrators cannot be promoted or counted as a recovery super admin', function () {
    $target = User::factory()->unverified()->create(['role' => 'admin', 'is_active' => true, 'registration_status' => 'approved', 'department' => 'Operations', 'requested_role' => 'admin']);
    expect(fn () => app(ManagePrivileges::class)->change($this->super->id, $target->id, 'promote', 'password', 'Requested promotion', $target->email))->toThrow(ValidationException::class);
    User::factory()->unverified()->create(['role' => 'super_admin', 'is_active' => true, 'registration_status' => 'approved', 'department' => 'Operations', 'requested_role' => 'admin']);
    expect(fn () => app(ManagePrivileges::class)->change($this->super->id, $this->super->id, 'demote', 'password', 'Requested demotion', $this->super->email))->toThrow(ValidationException::class);
    expect($this->super->fresh()->role)->toBe('super_admin');
});

test('the byte policy applies to administrator-created clients and the create-admin command', function () {
    $password = 'Aa1!'.str_repeat('x', 69);
    $this->actingAs($this->super)->post(route('admin.clients.store'), [
        'name' => 'Too Long', 'email' => 'long-client@example.com', 'password' => $password, 'password_confirmation' => $password, 'is_active' => true,
    ])->assertSessionHasErrors('password');
    $this->artisan('glos:create-admin')->expectsQuestion('Administrator name', 'Too Long')->expectsQuestion('Email address', 'long-admin@example.com')
        ->expectsQuestion('Password', $password)->expectsQuestion('Confirm password', $password)->assertFailed();
    $this->assertDatabaseCount('users', 2);
});

test('existing long-password authentication remains compatible without modifying hashes', function () {
    $password = 'Aa1!'.str_repeat('x', 76);
    $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'password' => Hash::make($password)]);
    Livewire::test(Login::class)->set('email', $user->email)->set('password', $password)->call('login')->assertHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

test('demo seeding refuses production environments before writing accounts', function () {
    app()->instance('env', 'production');
    try {
        expect(fn () => (new DatabaseSeeder)->run())->toThrow(LogicException::class);
    } finally {
        app()->instance('env', 'testing');
    }
    $this->assertDatabaseCount('users', 2);
});
