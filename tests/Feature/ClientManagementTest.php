<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

function clientInput(array $overrides = []): array
{
    return array_replace([
        'name' => 'Ocean Client', 'email' => 'client@example.com', 'is_active' => '1',
        'password' => 'ClientPassword!123', 'password_confirmation' => 'ClientPassword!123',
    ], $overrides);
}

function clientEditInput(array $overrides = []): array
{
    $data = clientInput($overrides);
    unset($data['password'], $data['password_confirmation']);

    return $data;
}

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->vessels = collect(['Ocean Pioneer', 'Ocean Explorer'])->map(fn ($name) => Vessel::create([
        'vessel_name' => $name, 'vessel_type' => 'Cargo', 'flag_state' => 'UK',
    ]));
});

test('administrator creates a client with a hashed password and multiple vessel assignments', function () {
    $this->actingAs($this->admin)->get(route('admin.clients.create'))->assertOk()->assertSee('Create staff member');
    $this->post(route('admin.clients.store'), clientInput(['email' => ' CLIENT@example.com ', 'vessel_ids' => $this->vessels->pluck('id')->all()]))
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    $client = User::where('role', 'client')->sole();
    expect($client->email)->toBe('client@example.com')
        ->and($client->role)->toBe('client')->and($client->is_active)->toBeTrue()
        ->and($client->password)->not->toBe('ClientPassword!123')
        ->and(Hash::check('ClientPassword!123', $client->password))->toBeTrue()
        ->and($client->vessels()->count())->toBe(2)
        ->and((new User)->getFillable())->toBe(['name', 'email', 'password']);
    $this->get(route('admin.clients.show', $client))->assertOk()->assertSee('Ocean Pioneer')->assertSee('Ocean Explorer')
        ->assertDontSee($client->password)->assertDontSee('ClientPassword!123');
    $this->get(route('admin.clients.edit', $client))->assertOk()->assertDontSee('name="password"', false)->assertDontSee($client->password);
    expect(session()->getOldInput('password'))->toBeNull();
});

test('client validation rejects invalid fields and never flashes passwords', function (array $overrides, string $field) {
    $this->actingAs($this->admin)->post(route('admin.clients.store'), clientInput($overrides))->assertSessionHasErrors($field);
    $this->assertDatabaseCount('users', 1);
    expect(session()->getOldInput('password'))->toBeNull()->and(session()->getOldInput('password_confirmation'))->toBeNull();
})->with([
    'name required' => [['name' => ''], 'name'],
    'email required' => [['email' => ''], 'email'],
    'email format' => [['email' => 'invalid'], 'email'],
    'password required' => [['password' => ''], 'password'],
    'short password' => [['password' => 'weak', 'password_confirmation' => 'weak'], 'password'],
    'confirmation mismatch' => [['password_confirmation' => 'different'], 'password'],
    'confirmation required' => [['password_confirmation' => ''], 'password_confirmation'],
    'status required' => [['is_active' => null], 'is_active'],
    'invalid boolean' => [['is_active' => 'active'], 'is_active'],
    'invalid vessel' => [['vessel_ids' => [9999]], 'vessel_ids.0'],
    'duplicate vessel' => [['vessel_ids' => [1, 1]], 'vessel_ids.0'],
    'vessels not an array' => [['vessel_ids' => 'invalid'], 'vessel_ids'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
]);

test('duplicate emails across all roles are rejected on create and edit', function () {
    $client = User::factory()->create(['role' => 'client', 'email' => 'other@example.com']);
    $this->actingAs($this->admin)->post(route('admin.clients.store'), clientInput(['email' => strtoupper($this->admin->email)]))->assertSessionHasErrors('email');
    $this->put(route('admin.clients.update', $client), clientEditInput(['email' => $this->admin->email]))->assertSessionHasErrors('email');
    expect($client->fresh()->email)->toBe('other@example.com');
});

test('administrator edits client details toggles status and replaces or removes assignments', function () {
    $client = User::factory()->create(['role' => 'client', 'is_active' => true]);
    $originalPassword = $client->password;
    $client->vessels()->attach($this->vessels->pluck('id'));
    $vesselAttributes = $this->vessels->map(fn ($vessel) => $vessel->refresh()->getAttributes())->all();
    $this->actingAs($this->admin)->put(route('admin.clients.update', $client), clientEditInput([
        'name' => 'Updated Client', 'is_active' => '0', 'vessel_ids' => [$this->vessels->first()->id],
    ]))->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($client->fresh()->name)->toBe('Updated Client')->and($client->fresh()->is_active)->toBeFalse()
        ->and($client->vessels()->count())->toBe(1)->and($client->fresh()->password)->toBe($originalPassword);
    $this->put(route('admin.clients.update', $client), clientEditInput(['email' => $client->fresh()->email, 'is_active' => '1']))->assertSessionHasNoErrors();
    expect($client->fresh()->is_active)->toBeTrue()->and($client->vessels()->count())->toBe(0)
        ->and($this->vessels->map(fn ($vessel) => $vessel->fresh()->getAttributes())->all())->toEqual($vesselAttributes);
    $this->get(route('admin.clients.show', $client))->assertSee('No vessels assigned');
});

test('clients can be created inactive without assignments and cannot log in', function () {
    $this->actingAs($this->admin)->post(route('admin.clients.store'), clientInput(['is_active' => '0']))->assertSessionHasNoErrors();
    $client = User::where('role', 'client')->sole();
    Auth::logout();
    Livewire::test(Login::class)->set('email', $client->email)->set('password', 'ClientPassword!123')->call('login')->assertHasErrors('email');
    $this->assertGuest();
});

test('an existing client session loses dashboard access after administrator deactivation', function () {
    $client = User::factory()->create(['role' => 'client', 'is_active' => true]);
    $sessionKey = Auth::guard()->getName();
    $this->withSession([$sessionKey => $client->id]);
    Auth::forgetGuards();
    $this->get(route('client.dashboard'))->assertOk();
    $this->actingAs($this->admin)->put(route('admin.clients.update', $client), clientEditInput(['email' => $client->email, 'is_active' => '0']))->assertSessionHasNoErrors();
    // A new request resolves the same existing session's user afresh from the provider.
    Auth::forgetGuards();
    $this->get(route('client.dashboard'))->assertForbidden();
});

test('role and internal authentication fields cannot be submitted through client management', function (string $field, mixed $value) {
    $client = User::factory()->create(['role' => 'client']);
    $this->actingAs($this->admin)->post(route('admin.clients.store'), clientInput([$field => $value]))->assertSessionHasErrors($field);
    $this->put(route('admin.clients.update', $client), clientEditInput([$field => $value]))->assertSessionHasErrors($field);
    expect($client->fresh()->role)->toBe('client');
})->with([
    ['role', 'admin'], ['remember_token', 'injected-token'], ['email_verified_at', '2026-01-01'],
]);

test('editing cannot change passwords or administrator accounts', function () {
    $client = User::factory()->create(['role' => 'client']);
    $original = $client->password;
    $adminAttributes = $this->admin->refresh()->getAttributes();
    $this->actingAs($this->admin)->put(route('admin.clients.update', $client), clientInput())->assertSessionHasErrors('password');
    expect($client->fresh()->password)->toBe($original);
    $this->get(route('admin.clients.show', $this->admin))->assertNotFound();
    $this->get(route('admin.clients.edit', $this->admin))->assertNotFound();
    $this->put(route('admin.clients.update', $this->admin), clientEditInput(['vessel_ids' => [$this->vessels->first()->id]]))->assertForbidden();
    expect($this->admin->fresh()->getAttributes())->toEqual($adminAttributes);
    $this->assertDatabaseCount('vessel_user_access', 0);
});

test('client listing excludes administrators searches and paginates', function () {
    $this->actingAs($this->admin)->get(route('admin.clients.index'))->assertSee('No staff accounts yet');
    for ($index = 1; $index <= 16; $index++) {
        User::factory()->create(['role' => 'client', 'name' => 'Ocean Client '.$index, 'email' => 'ocean'.$index.'@example.com']);
    }
    $this->get(route('admin.clients.index', ['search' => 'Ocean']))->assertOk()->assertDontSee($this->admin->email)
        ->assertViewHas('clients', fn ($rows) => $rows->total() === 16 && $rows->count() === 15 && str_contains($rows->nextPageUrl(), 'search=Ocean'));
    $this->get(route('admin.clients.index', ['page' => 2]))->assertViewHas('clients', fn ($rows) => $rows->count() === 1);
    $this->get(route('admin.clients.index', ['search' => 'ocean1@example.com']))->assertViewHas('clients', fn ($rows) => $rows->total() === 1);
    $this->get(route('admin.clients.index', ['search' => 'missing']))->assertSee('No matching staff members');
});

test('all client endpoints require active administrator access', function (string $actor) {
    $target = User::factory()->create(['role' => 'client']);
    if ($actor !== 'guest') {
        $this->actingAs(User::factory()->create(['role' => $actor === 'client' ? 'client' : 'admin', 'is_active' => $actor !== 'inactive']));
    }
    foreach ([['GET', '/admin/clients'], ['GET', '/admin/clients/create'], ['POST', '/admin/clients'], ['GET', '/admin/clients/'.$target->id], ['GET', '/admin/clients/'.$target->id.'/edit'], ['PUT', '/admin/clients/'.$target->id], ['PATCH', '/admin/clients/'.$target->id]] as [$method, $uri]) {
        $response = $this->call($method, $uri, clientEditInput(['vessel_ids' => [$this->vessels->first()->id]]));
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
    }
    $this->assertDatabaseCount('vessel_user_access', 0);
})->with(['guest', 'client', 'inactive']);

test('account and assignment changes roll back together on assignment failure', function (string $operation) {
    $client = User::factory()->create(['role' => 'client', 'name' => 'Before']);
    $client->vessels()->attach($this->vessels->first()->id);
    DB::connection()->beforeExecuting(function ($query) {
        if (str_starts_with(strtolower($query), 'insert into "vessel_user_access"')) {
            throw new RuntimeException('Simulated pivot insert failure');
        }
    });
    $this->actingAs($this->admin);
    $response = $operation === 'create'
        ? $this->post(route('admin.clients.store'), clientInput(['vessel_ids' => [$this->vessels->last()->id]]))
        : $this->put(route('admin.clients.update', $client), clientEditInput(['vessel_ids' => [$this->vessels->last()->id]]));
    $response->assertSessionHasErrors('name');
    $this->assertDatabaseCount('users', 2);
    expect($client->fresh()->name)->toBe('Before')->and($client->vessels()->pluck('vessels.id')->all())->toBe([$this->vessels->first()->id]);
    expect(session()->getOldInput('password'))->toBeNull()->and(session()->getOldInput('password_confirmation'))->toBeNull();
})->with(['create', 'update']);

test('database email conflicts return safe validation errors', function () {
    User::creating(function () {
        throw new UniqueConstraintViolationException('sqlite', 'test insert', [], new PDOException('duplicate'));
    });
    $this->actingAs($this->admin)->post(route('admin.clients.store'), clientInput())->assertSessionHasErrors('email');
    $this->assertDatabaseCount('users', 1);
});

test('client accounts have no deletion route and public registration cannot set active privileges', function () {
    $client = User::factory()->create(['role' => 'client']);
    $this->actingAs($this->admin)->delete(route('admin.clients.show', $client))->assertStatus(405);
    $this->get('/register')->assertRedirect(route('admin.dashboard'));
    Auth::logout();
    $this->get('/register')->assertOk();
    $this->post('/register', clientInput(['department' => 'Operations', 'requested_role' => 'client']))->assertSessionHasErrors('is_active');
    $this->assertDatabaseCount('users', 2);
});
