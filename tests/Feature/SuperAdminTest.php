<?php

use App\Enums\RequestedAccessRole;
use App\Livewire\Auth\Login;
use App\Models\User;
use App\Support\ManagePrivileges;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->payload = ['action' => 'promote', 'password' => 'password', 'confirm' => '1', 'target_email' => $this->admin->email, 'reason' => 'Approved leadership change'];
});

test('super admins log in to the existing administrator dashboard', function () {
    Livewire::test(Login::class)->set('email', $this->super->email)->set('password', 'password')->call('login')
        ->assertHasNoErrors()->assertRedirect(route('admin.dashboard'));
    $this->assertAuthenticatedAs($this->super);
    foreach (['admin.dashboard', 'admin.vessels.index', 'admin.certificate-types.index', 'admin.certificates.index', 'admin.clients.index', 'super-admin.administrators.index'] as $route) {
        $this->get(route($route))->assertOk();
    }
    $this->get(route('client.dashboard'))->assertForbidden();
});

test('super admins retain administrator form authorisation', function () {
    $this->actingAs($this->super)->post(route('admin.vessels.store'), ['vessel_name' => 'Super Fleet', 'vessel_type' => 'Cargo', 'flag_state' => 'UK', 'vessel_status' => 'active'])->assertSessionHasNoErrors();
    $this->post(route('admin.certificate-types.store'), ['name' => 'Super Safety', 'category' => 'Safety'])->assertSessionHasNoErrors();
    $this->post(route('admin.clients.store'), ['name' => 'Super Client', 'email' => 'super-client@example.com', 'password' => 'SecureClient!123', 'password_confirmation' => 'SecureClient!123', 'is_active' => '1'])->assertSessionHasNoErrors();
});

test('management routes deny guests clients administrators and inactive super admins', function (string $actor) {
    if ($actor !== 'guest') {
        $this->actingAs(User::factory()->create(['role' => $actor === 'inactive' ? 'super_admin' : $actor, 'is_active' => $actor !== 'inactive']));
    }
    foreach ([['GET', route('super-admin.administrators.index')], ['GET', route('super-admin.administrators.edit', $this->admin)], ['POST', route('super-admin.administrators.update', $this->admin)]] as [$method, $url]) {
        $response = $this->call($method, $url, $this->payload);
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
    }
    expect($this->admin->fresh()->role)->toBe('admin');
    $this->assertDatabaseCount('privilege_audits', 0);
})->with(['guest', 'client', 'inactive']);

test('confirmed promotion and demotion are audited without credential changes', function () {
    $this->admin->role = 'client';
    $this->admin->save();
    $passwordHash = $this->admin->password;
    $this->actingAs($this->super)->get(route('super-admin.administrators.edit', $this->admin))->assertOk()->assertSee('Your current password');
    $this->post(route('super-admin.administrators.update', $this->admin), $this->payload)->assertSessionHasNoErrors();
    expect($this->admin->fresh()->role)->toBe('admin')->and($this->admin->fresh()->password)->toBe($passwordHash);
    $this->post(route('super-admin.administrators.update', $this->admin), array_replace($this->payload, ['action' => 'demote']))->assertSessionHasNoErrors();
    expect($this->admin->fresh()->role)->toBe('client');
    $this->assertDatabaseCount('privilege_audits', 2);
    $this->assertDatabaseHas('privilege_audits', ['actor_user_id' => $this->super->id, 'target_user_id' => $this->admin->id, 'action' => 'promote', 'previous_role' => 'client', 'new_role' => 'admin']);
    foreach (DB::table('privilege_audits')->get() as $entry) {
        expect((array) $entry)->not->toHaveKeys(['password', 'password_hash']);
    }
    $this->get(route('super-admin.administrators.index'))->assertOk()->assertDontSee($passwordHash);
});

test('privileged changes require fresh password and explicit target confirmation', function (array $overrides, string $field) {
    // A prior password confirmation timestamp is deliberately insufficient.
    $this->actingAs($this->super)->withSession(['auth.password_confirmed_at' => time()]);
    $this->post(route('super-admin.administrators.update', $this->admin), array_replace($this->payload, $overrides))->assertSessionHasErrors($field);
    expect($this->admin->fresh()->role)->toBe('admin')->and(session()->getOldInput('password'))->toBeNull();
    $this->assertDatabaseCount('privilege_audits', 0);
})->with([
    [['password' => ''], 'password'], [['password' => 'wrong'], 'password'], [['confirm' => '0'], 'confirm'],
    [['target_email' => 'different@example.com'], 'target_email'], [['reason' => ''], 'reason'],
    [['action' => 'set-role'], 'action'], [['role' => 'super_admin'], 'role'], [['is_active' => true], 'is_active'],
]);

test('last active super admin cannot be demoted or deactivated', function (string $action) {
    $this->admin->is_active = false;
    $this->admin->save();
    User::factory()->create(['role' => 'super_admin', 'is_active' => false]);
    $this->actingAs($this->super)->post(route('super-admin.administrators.update', $this->super), array_replace($this->payload, ['action' => $action, 'target_email' => $this->super->email]))->assertSessionHasErrors('action');
    expect($this->super->fresh()->role)->toBe('super_admin')->and($this->super->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseCount('privilege_audits', 0);
})->with(['demote', 'deactivate']);

test('super admin status changes are audited and require another active super admin', function () {
    $second = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $this->actingAs($this->super)->post(route('super-admin.administrators.update', $second), array_replace($this->payload, ['action' => 'deactivate', 'target_email' => $second->email]))->assertSessionHasNoErrors();
    expect($second->fresh()->is_active)->toBeFalse();
    $this->post(route('super-admin.administrators.update', $second), array_replace($this->payload, ['action' => 'activate', 'target_email' => $second->email]))->assertSessionHasNoErrors();
    expect($second->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseHas('privilege_audits', ['action' => 'deactivate', 'target_user_id' => $second->id, 'new_is_active' => false]);
});

test('self demotion removes super admin access on subsequent requests', function () {
    User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $sessionKey = Auth::guard()->getName();
    $this->actingAs($this->super)->withSession([$sessionKey => $this->super->id]);
    $this->post(route('super-admin.administrators.update', $this->super), array_replace($this->payload, ['action' => 'demote', 'target_email' => $this->super->email]))->assertSessionHasNoErrors();
    Auth::forgetGuards();
    $this->get(route('super-admin.administrators.index'))->assertForbidden();
    $this->get(route('admin.dashboard'))->assertForbidden();
    $this->get(route('client.dashboard'))->assertOk();
});

test('self deactivation logs out the actor when another active super admin exists', function () {
    User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $this->actingAs($this->super)->post(route('super-admin.administrators.update', $this->super), array_replace($this->payload, ['action' => 'deactivate', 'target_email' => $this->super->email]))->assertRedirect(route('login'));
    $this->assertGuest();
    expect($this->super->fresh()->is_active)->toBeFalse();
});

test('an inactive administrator cannot be promoted and stale target identity is rejected', function () {
    $this->admin->is_active = false;
    $this->admin->save();
    $this->actingAs($this->super)->post(route('super-admin.administrators.update', $this->admin), $this->payload)->assertSessionHasErrors('action');
    expect(fn () => app(ManagePrivileges::class)->change($this->super->id, $this->admin->id, 'activate', 'password', 'Approved change', 'stale@example.com'))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('privilege_audits', 0);
});

test('a stale demoted actor cannot make another privileged change', function () {
    $stale = $this->super;
    User::whereKey($stale->id)->update(['role' => 'client']);
    $this->actingAs($stale)->post(route('super-admin.administrators.update', $this->admin), $this->payload)->assertForbidden();
    expect($this->admin->fresh()->role)->toBe('admin');
});

test('client-management routes protect super admin accounts and reject role escalation', function () {
    $input = ['name' => 'Injected', 'email' => $this->super->email, 'is_active' => false, 'role' => 'admin'];
    $this->actingAs($this->admin)->put(route('admin.clients.update', $this->super), $input)->assertForbidden();
    $this->get(route('admin.clients.edit', $this->super))->assertNotFound();
    $this->actingAs($this->super)->put(route('admin.clients.update', $this->super), $input)->assertForbidden();
    expect($this->super->fresh()->role)->toBe('super_admin')->and($this->super->fresh()->is_active)->toBeTrue();
    $client = User::factory()->create(['role' => 'client']);
    $this->post(route('super-admin.administrators.update', $client), array_replace($this->payload, ['target_email' => $client->email]))->assertSessionHasNoErrors();
    expect($client->fresh()->role)->toBe('admin');
});

test('role privileges remain guarded from mass assignment and requested roles are separate', function () {
    expect((new User)->getFillable())->toBe(['name', 'email', 'password'])
        ->and(array_column(RequestedAccessRole::cases(), 'value'))->toBe(['admin', 'client']);
    $user = new User;
    $user->fill(['name' => 'Applicant', 'role' => 'super_admin', 'is_active' => true]);
    expect($user->role)->toBeNull()->and($user->is_active)->toBeNull();
    $this->get('/register')->assertOk();
});

test('privilege changes roll back when audit insertion fails', function () {
    $this->admin->role = 'client';
    $this->admin->save();
    DB::connection()->beforeExecuting(function ($query) {
        if (str_starts_with(strtolower($query), 'insert into "privilege_audits"')) {
            throw new RuntimeException('Simulated audit failure');
        }
    });
    try {
        app(ManagePrivileges::class)->change($this->super->id, $this->admin->id, 'promote', 'password', 'Approved change', $this->admin->email);
        $this->fail('Audit failure should prevent commit.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated audit failure');
    }
    expect($this->admin->fresh()->role)->toBe('client');
    $this->assertDatabaseCount('privilege_audits', 0);
});

test('privilege changes lock the singleton before account rows', function () {
    $this->admin->role = 'client';
    $this->admin->save();
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
        app(ManagePrivileges::class)->change($this->super->id, $this->admin->id, 'promote', 'password', 'Approved change', $this->admin->email);
        expect($grammar->locks)->toBe(['privilege_management_locks', 'users', 'users']);
    } finally {
        $connection->setQueryGrammar($previous);
    }
});

test('password confirmation attempts are rate limited', function () {
    $this->actingAs($this->super);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('super-admin.administrators.update', $this->admin), array_replace($this->payload, ['password' => 'wrong']))->assertSessionHasErrors('password');
    }
    $this->post(route('super-admin.administrators.update', $this->admin), $this->payload)->assertStatus(429);
});
