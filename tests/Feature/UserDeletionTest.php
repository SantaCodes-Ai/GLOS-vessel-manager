<?php

use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\User;
use App\Models\Vessel;
use App\Notifications\ApplicantVerifyEmail;
use App\Support\DeleteUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Notification::fake();
    Storage::fake('certificates');
    $this->super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $this->target = User::factory()->create(['role' => 'client', 'is_active' => true, 'department' => 'Operations', 'requested_role' => 'client', 'registration_status' => 'approved']);
    $this->input = ['confirmation' => '1'];
    $this->actingAs($this->super)->get(route('super-admin.users.delete', $this->target))->assertOk();
    Auth::logout();
    $this->vessel = Vessel::create(['vessel_name' => 'Preserved Vessel', 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
    $this->target->vessels()->attach($this->vessel);
});

test('super admins delete pending rejected approved and legacy client or admin users', function (string $role, ?string $state, bool $active) {
    $this->target->role = $role;
    $this->target->registration_status = $state;
    $this->target->is_active = $active;
    $this->target->save();
    $this->actingAs($this->super)->get(route('super-admin.users.delete', $this->target))->assertOk()
        ->assertSee($this->target->email)->assertSee('cannot be undone')->assertSee('Confirm Delete')->assertDontSee('Type DELETE');
    $this->delete(route('super-admin.users.destroy', $this->target), $this->input)->assertSessionHasNoErrors()->assertRedirect(route('super-admin.administrators.index'));
    $this->assertDatabaseMissing('users', ['id' => $this->target->id]);
    $this->assertDatabaseMissing('vessel_user_access', ['user_id' => $this->target->id]);
    $this->assertDatabaseHas('vessels', ['id' => $this->vessel->id]);
    $this->assertDatabaseHas('privilege_audits', ['target_user_id' => $this->target->id, 'actor_user_id' => $this->super->id, 'action' => 'delete_user', 'previous_role' => $role, 'new_role' => 'deleted']);
    Notification::assertNothingSent();
})->with([['client', 'pending', false], ['client', 'rejected', false], ['client', 'approved', true], ['admin', 'approved', true], ['client', null, true], ['admin', null, true]]);

test('deletion preserves certificates versions PDFs and audit evidence and cleans authentication records', function () {
    $type = CertificateType::create(['name' => 'Safety', 'category' => 'Statutory']);
    $certificate = Certificate::create(['vessel_id' => $this->vessel->id, 'certificate_type_id' => $type->id, 'created_by' => $this->target->id, 'remarks' => 'Keep unchanged']);
    $version = $certificate->versions()->create(['version_number' => 1, 'expiry_type' => 'permanent', 'file_path' => 'retained.pdf', 'original_filename' => 'original.pdf', 'uploaded_by' => $this->target->id]);
    Storage::disk('certificates')->put($version->file_path, 'preserved PDF bytes');
    $certificateBefore = $certificate->refresh()->getAttributes();
    $versionBefore = $version->refresh()->getAttributes();
    DB::table('registration_decisions')->insert(['user_id' => $this->target->id, 'actor_user_id' => $this->super->id, 'requested_role' => 'client', 'decision' => 'approved', 'previous_role' => 'client', 'new_role' => 'client', 'reason' => 'Historical approval', 'created_at' => now()]);
    $decisionBefore = (array) DB::table('registration_decisions')->first();
    DB::table('privilege_audits')->insert(['actor_user_id' => $this->target->id, 'target_user_id' => $this->target->id, 'action' => 'activate', 'previous_role' => 'admin', 'new_role' => 'admin', 'previous_is_active' => false, 'new_is_active' => true, 'reason' => 'Historical event', 'created_at' => now()]);
    $auditBefore = (array) DB::table('privilege_audits')->first();
    DB::table('sessions')->insert(['id' => 'deleted-session', 'user_id' => $this->target->id, 'payload' => 'retained only until deletion', 'last_activity' => time()]);
    DB::table('sessions')->insert(['id' => 'super-session', 'user_id' => $this->super->id, 'payload' => 'unrelated', 'last_activity' => time()]);
    DB::table('password_reset_tokens')->insert(['email' => $this->target->email, 'token' => 'obsolete-hashed-token', 'created_at' => now()]);
    $this->actingAs($this->super)->delete(route('super-admin.users.destroy', $this->target), $this->input)->assertSessionHasNoErrors();
    expect($certificate->fresh()->getAttributes())->toBe(array_replace($certificateBefore, ['created_by' => null]))
        ->and($version->fresh()->getAttributes())->toBe(array_replace($versionBefore, ['uploaded_by' => null]))
        ->and((array) DB::table('registration_decisions')->first())->toBe($decisionBefore)
        ->and((array) DB::table('privilege_audits')->where('id', $auditBefore['id'])->first())->toBe($auditBefore)
        ->and(Storage::disk('certificates')->get('retained.pdf'))->toBe('preserved PDF bytes')
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    $this->assertDatabaseMissing('sessions', ['id' => 'deleted-session']);
    $this->assertDatabaseHas('sessions', ['id' => 'super-session']);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->target->email]);
    $deletion = (array) DB::table('privilege_audits')->where('action', 'delete_user')->first();
    expect(json_encode($deletion))->not->toContain($this->target->email, $this->target->name, $this->target->password);
});

test('deleted email can complete a new registration verification and approval with a new identity', function () {
    $oldId = $this->target->id;
    DB::table('registration_decisions')->insert(['user_id' => $oldId, 'actor_user_id' => $this->super->id, 'requested_role' => 'client', 'decision' => 'approved', 'previous_role' => 'client', 'new_role' => 'client', 'reason' => 'First registration', 'created_at' => now()]);
    $this->actingAs($this->super)->delete(route('super-admin.users.destroy', $this->target), $this->input)->assertSessionHasNoErrors();
    Auth::logout();
    $this->post('/register', ['name' => 'New Identity', 'email' => $this->target->email, 'password' => 'ApplicantSecure!123', 'password_confirmation' => 'ApplicantSecure!123', 'department' => 'Operations', 'requested_role' => 'admin'])->assertSessionHasNoErrors();
    $new = User::where('email', $this->target->email)->sole();
    expect($new->id)->not->toBe($oldId)->and($new->registration_status)->toBe('pending')->and($new->is_active)->toBeFalse();
    $url = (new ApplicantVerifyEmail)->toMail($new)->actionUrl;
    $this->get($url)->assertOk();
    $this->actingAs($this->super)->post(route('super-admin.registrations.decide', $new), ['password' => 'password', 'confirm' => '1', 'decision' => 'approved', 'reason' => 'Second registration approved'])->assertSessionHasNoErrors();
    expect($new->fresh()->role)->toBe('admin')->and($new->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseCount('registration_decisions', 2);
});

test('deleted user sessions cannot access protected dashboards or documents', function () {
    $sessionKey = Auth::guard()->getName();
    $this->withSession([$sessionKey => $this->target->id]);
    Auth::forgetGuards();
    $this->get(route('client.dashboard'))->assertOk();
    $this->actingAs($this->super)->delete(route('super-admin.users.destroy', $this->target), $this->input)->assertSessionHasNoErrors();
    Auth::forgetGuards();
    $this->get(route('client.dashboard'))->assertRedirect(route('login'));
    $this->get('/client/certificates/1/preview')->assertRedirect(route('login'));
    $this->get('/client/certificates/1/versions/1/download')->assertRedirect(route('login'));
});

test('self deletion is forbidden', function () {
    $this->actingAs($this->super)->get(route('super-admin.users.delete', $this->super))->assertForbidden();
    $this->delete(route('super-admin.users.destroy', $this->super), $this->input)->assertForbidden();
    $this->assertDatabaseHas('users', ['id' => $this->super->id]);
});

test('other legacy administrators can be deleted without losing the actor access', function (bool $active) {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => $active]);
    $this->actingAs($this->super)->get(route('super-admin.users.delete', $user))->assertOk();
    $this->delete(route('super-admin.users.destroy', $user), $this->input)->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
})->with([true, false]);

test('deletion routes require an active super admin', function (string $actor) {
    if ($actor !== 'guest') {
        $this->actingAs(User::factory()->create(['role' => $actor === 'inactive' ? 'super_admin' : $actor, 'is_active' => $actor !== 'inactive']));
    }
    foreach ([['GET', route('super-admin.users.delete', $this->target)], ['DELETE', route('super-admin.users.destroy', $this->target)]] as [$method, $url]) {
        $response = $this->call($method, $url, $this->input);
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
    }
    $this->assertDatabaseHas('users', ['id' => $this->target->id]);
})->with(['guest', 'client', 'inactive']);

test('deletion requires explicit confirmation', function (mixed $confirmation) {
    $this->actingAs($this->super)->delete(route('super-admin.users.destroy', $this->target), ['confirmation' => $confirmation])->assertSessionHasErrors('confirmation');
    $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    $this->assertDatabaseCount('privilege_audits', 0);
})->with([null, '', '0', 'DELETE']);

test('deletion requires a recent server-side review of the same identity', function (string $scenario) {
    if ($scenario === 'missing') {
        session()->forget('user_deletion.'.$this->target->id);
    } elseif ($scenario === 'expired') {
        $this->travel(11)->minutes();
    } else {
        $this->target->email = 'changed@example.com';
        $this->target->save();
    }
    $this->actingAs($this->super)->delete(route('super-admin.users.destroy', $this->target), $this->input)->assertSessionHasErrors('confirmation');
    $this->assertDatabaseHas('users', ['id' => $this->target->id]);
})->with(['missing', 'expired', 'changed']);

test('repeated deletion returns not found and produces only one audit event', function () {
    $url = route('super-admin.users.destroy', $this->target);
    $this->actingAs($this->super)->delete($url, $this->input)->assertSessionHasNoErrors();
    $this->delete($url, $this->input)->assertNotFound();
    $this->assertDatabaseCount('privilege_audits', 1);
});

test('deletion rolls back account assignments and audit on any write failure', function (string $stage) {
    DB::connection()->beforeExecuting(function ($query) use ($stage) {
        $prefix = $stage === 'audit' ? 'insert into "privilege_audits"' : 'delete from "users"';
        if (str_starts_with(strtolower($query), $prefix)) {
            throw new RuntimeException('Simulated write failure');
        }
    });
    expect(fn () => app(DeleteUser::class)->delete($this->super->id, $this->target->id, DeleteUser::fingerprint($this->target)))->toThrow(RuntimeException::class);
    $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    $this->assertDatabaseHas('vessel_user_access', ['user_id' => $this->target->id]);
    $this->assertDatabaseCount('privilege_audits', 0);
})->with(['audit', 'user']);

test('unexpected restrictive foreign keys fail safely without disabling constraints', function () {
    Schema::create('deletion_test_dependents', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
    });
    DB::table('deletion_test_dependents')->insert(['user_id' => $this->target->id]);
    $this->actingAs($this->super)->from(route('super-admin.users.delete', $this->target))
        ->delete(route('super-admin.users.destroy', $this->target), $this->input)->assertSessionHasErrors('confirmation');
    $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    $this->assertDatabaseHas('vessel_user_access', ['user_id' => $this->target->id]);
    $this->assertDatabaseCount('privilege_audits', 0);
    expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
});

test('deletion rechecks actor and target identity after obtaining locks', function () {
    $fingerprint = DeleteUser::fingerprint($this->target);
    $this->target->role = 'admin';
    $this->target->save();
    expect(fn () => app(DeleteUser::class)->delete($this->super->id, $this->target->id, $fingerprint))->toThrow(ValidationException::class);
    User::whereKey($this->super->id)->update(['role' => 'client']);
    expect(fn () => app(DeleteUser::class)->delete($this->super->id, $this->target->id, DeleteUser::fingerprint($this->target)))->toThrow(HttpException::class);
});

test('deletion locks the privilege sentinel before actor and target', function () {
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
        app(DeleteUser::class)->delete($this->super->id, $this->target->id, DeleteUser::fingerprint($this->target));
        expect($grammar->locks)->toBe(['privilege_management_locks', 'users', 'users']);
    } finally {
        $connection->setQueryGrammar($previous);
    }
});

test('permanent deletion requires CSRF protection even with valid credentials', function () {
    app()->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    $this->actingAs($this->super)->delete(route('super-admin.users.destroy', $this->target), $this->input)->assertStatus(419);
    $this->assertDatabaseHas('users', ['id' => $this->target->id]);
});

test('delete controls appear only for super admins on the relevant management pages', function () {
    $this->actingAs($this->super)->get(route('super-admin.registrations.show', $this->target))->assertSee('Delete User');
    $this->get(route('admin.clients.show', $this->target))->assertSee('Delete User');
    $this->target->role = 'admin';
    $this->target->save();
    $this->get(route('super-admin.administrators.edit', $this->target))->assertSee('Delete User');
    $this->get(route('super-admin.administrators.index'))->assertSee(route('super-admin.users.delete', $this->target), false);
    $this->target->role = 'client';
    $this->target->save();
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]))->get(route('admin.clients.show', $this->target))->assertSee('Delete User');
});

test('deletion attempts are throttled', function () {
    $this->actingAs($this->super);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->delete(route('super-admin.users.destroy', $this->target), array_replace($this->input, ['confirmation' => '0']))->assertSessionHasErrors('confirmation');
    }
    $this->delete(route('super-admin.users.destroy', $this->target), $this->input)->assertStatus(429);
});
