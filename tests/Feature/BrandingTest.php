<?php

use App\Models\User;
use App\Notifications\RegistrationMessage;

test('the published company logo is the exact supplied file', function () {
    expect(is_file(public_path('assets/branding/GLOS logo.jpg')))->toBeTrue()
        ->and(hash_file('sha256', public_path('assets/branding/GLOS logo.jpg')))
        ->toBe(hash_file('sha256', base_path('GLOS logo.jpg')));
});

test('shared authentication pages display the official accessible company logo', function (string $url) {
    $this->get($url)->assertOk()
        ->assertSee('src="'.asset('assets/branding/GLOS logo.jpg').'"', false)
        ->assertSee('alt="Global Link Ocean Streams"', false)
        ->assertSee('width="772" height="228"', false);
})->with(['/login', '/register', '/registration/notice']);

test('both dashboards use escaped first names with a safe fallback', function (string $role, string $name, string $firstName) {
    $user = User::factory()->create(['role' => $role, 'name' => $name, 'is_active' => true]);
    $this->actingAs($user)->get(route($role.'.dashboard'))->assertOk()
        ->assertSee('Welcome back, '.$firstName.'!')
        ->assertSee('src="'.asset('assets/branding/GLOS logo.jpg').'"', false)
        ->assertDontSee('<script>alert(1)</script>', false);
})->with([
    ['admin', 'Ivan Smith', 'Ivan'],
    ['client', 'Sarah Jones', 'Sarah'],
    ['admin', '  Ada   Lovelace ', 'Ada'],
    ['client', "Mary\tJones", 'Mary'],
    ['admin', '', 'there'],
    ['client', '   ', 'there'],
    ['admin', '<script>alert(1)</script> Smith', '<script>alert(1)</script>'],
    ['client', 'Élodie Laurent', 'Élodie'],
]);

test('staff dashboard and navigation retain client routes without client labels', function () {
    $staff = User::factory()->create(['role' => 'client', 'is_active' => true]);
    $this->actingAs($staff)->get(route('client.dashboard'))->assertOk()->assertSee('Staff Dashboard')
        ->assertSee('Staff workspace')->assertSee(route('client.vessels.index'), false)
        ->assertSee(route('client.certificates.index'), false)->assertSee('Sign out')
        ->assertDontSee('Client dashboard')->assertDontSee('Client workspace')->assertDontSee('/admin/', false);
    expect($staff->fresh()->role)->toBe('client');
});

test('staff terminology appears throughout account management without changing effective roles', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'name' => 'Sarah Jones']);
    $this->actingAs($admin)->get(route('admin.clients.index'))->assertOk()
        ->assertSee('Staff Members')->assertSee('Add Staff Member')->assertDontSee('Client accounts');
    $this->get(route('admin.clients.create'))->assertOk()->assertSee('Create staff member')->assertDontSee('client role');
    $this->get(route('admin.clients.show', $staff))->assertOk()->assertSee('Staff details');
    $this->get(route('admin.clients.edit', $staff))->assertOk()->assertSee('Edit staff member');
    $this->get(route('admin.users.index'))->assertOk()->assertSee('Staff / Assignments');
    $this->get(route('super-admin.administrators.edit', $staff))->assertOk()->assertSee('Current role: Staff');
    $this->get(route('super-admin.users.delete', $staff))->assertOk()->assertSee('Staff')->assertDontSee('<dd>client</dd>', false);
    expect($staff->fresh()->role)->toBe('client');
});

test('public role selection and approval details show staff while keeping client values', function () {
    $this->get('/register')->assertOk()->assertSee('value="client"', false)->assertSee('>Staff</option>', false)->assertDontSee('>Client</option>', false);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $pending = User::factory()->create(['role' => 'client', 'requested_role' => 'client', 'registration_status' => 'pending', 'is_active' => false]);
    $this->actingAs($admin)->get(route('super-admin.registrations.index'))->assertOk()->assertSee('Staff');
    $this->get(route('super-admin.registrations.show', $pending))->assertOk()->assertSee('Requested Staff');
    expect($pending->fresh()->requested_role)->toBe('client')->and($pending->fresh()->is_active)->toBeFalse();
});

test('registration notification copy uses staff without changing the queued role data', function () {
    $notification = new RegistrationMessage('submitted', 'client');
    $mail = $notification->toMail(User::factory()->make());
    expect($notification->requestedRole)->toBe('client')
        ->and($mail->introLines)->toContain('A new Staff registration is ready for review.');
});
