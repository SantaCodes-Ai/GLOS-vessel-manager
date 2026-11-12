<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use Livewire\Livewire;

test('the login page renders', function () {
    $this->get('/login')->assertOk()->assertSeeLivewire(Login::class);
});

test('the login form contains an accessible submit button and loading state', function () {
    $response = $this->get('/login')->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $button = $xpath->query('//form[@*[name()="wire:submit"]="login"]//button[@type="submit"]')->item(0);

    expect($button)->toBeInstanceOf(DOMElement::class);
    expect($button->getAttribute('class'))->toBe('login-submit')
        ->and($button->hasAttribute('disabled'))->toBeFalse()
        ->and($button->hasAttribute('wire:loading.attr'))->toBeTrue()
        ->and($button->getAttribute('wire:target'))->toBe('login')
        ->and($button->textContent)->toContain('Log In', 'Logging in…');
});

test('active users log in and receive the correct dashboard', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'is_active' => true]);
    $this->withSession(['marker' => 'retained']);
    $oldId = session()->getId();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertSet('password', '')
        ->assertRedirect(route($role.'.dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($oldId);
})->with(['admin', 'client']);

test('invalid credentials and inactive accounts receive the same error', function (string $scenario) {
    $user = User::factory()->create(['is_active' => $scenario !== 'inactive']);

    Livewire::test(Login::class)
        ->set('email', $scenario === 'unknown' ? 'missing@example.com' : $user->email)
        ->set('password', $scenario === 'incorrect' ? 'incorrect' : 'password')
        ->call('login')
        ->assertHasErrors(['email'])
        ->assertSee('These credentials do not match our records.')
        ->assertSet('password', '');

    $this->assertGuest();
})->with(['incorrect', 'inactive', 'unknown']);

test('users can access only their own dashboard', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'is_active' => true]);
    $otherRole = $role === 'admin' ? 'client' : 'admin';

    $this->actingAs($user)->get('/'.$role.'/dashboard')->assertOk()->assertSee('Sign out');
    $this->get('/'.$otherRole.'/dashboard')->assertForbidden();
    $this->get('/login')->assertRedirect(route($role.'.dashboard'));
})->with(['admin', 'client']);

test('guests cannot access dashboards', function (string $role) {
    $this->get('/'.$role.'/dashboard')->assertRedirect(route('login'));
})->with(['admin', 'client']);

test('inactive users with an existing session cannot access dashboards', function () {
    $user = User::factory()->create(['role' => 'admin', 'is_active' => false]);

    $this->actingAs($user)->get('/admin/dashboard')->assertForbidden();
});

test('logout ends authentication and replaces the session and csrf token', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['private_data' => 'secret']);
    $oldId = session()->getId();
    $oldToken = session()->token();

    $this->post('/logout')->assertRedirect(route('login'))->assertSessionMissing('private_data');

    $this->assertGuest();
    expect(session()->getId())->not->toBe($oldId)
        ->and(session()->token())->not->toBe($oldToken);
    $this->get('/client/dashboard')->assertRedirect(route('login'));
});

test('logout does not accept get requests', function () {
    $this->actingAs(User::factory()->create())->get('/logout')->assertStatus(405);
    $this->assertAuthenticated();
});

test('login is rate limited after five failed attempts and recovers after expiry', function () {
    $user = User::factory()->create();

    $component = Livewire::test(Login::class)->set('email', $user->email);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $component->set('password', 'incorrect')->call('login')->assertHasErrors('email');
    }

    $component->set('password', 'password')->call('login')
        ->assertHasErrors('email')->assertSee('Too many login attempts.');
    $this->assertGuest();

    $this->travel(61)->seconds();

    $component->set('password', 'password')->call('login')
        ->assertHasNoErrors()->assertRedirect(route('client.dashboard'));
    $this->assertAuthenticatedAs($user);
});
