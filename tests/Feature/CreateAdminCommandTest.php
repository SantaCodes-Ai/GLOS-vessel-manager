<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('creates an active administrator with a hashed password', function () {
    $this->artisan('glos:create-admin')
        ->expectsQuestion('Administrator name', ' GLOS Admin ')
        ->expectsQuestion('Email address', ' ADMIN@example.com ')
        ->expectsQuestion('Password', 'SecureAdmin!123')
        ->expectsQuestion('Confirm password', 'SecureAdmin!123')
        ->expectsOutput('Administrator account created successfully.')
        ->doesntExpectOutputToContain('SecureAdmin!123')
        ->assertSuccessful();

    $user = User::sole();

    expect($user->name)->toBe('GLOS Admin')
        ->and($user->email)->toBe('admin@example.com')
        ->and($user->isAdmin())->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->password)->not->toBe('SecureAdmin!123')
        ->and(Hash::check('SecureAdmin!123', $user->password))->toBeTrue();
});

test('rejects invalid administrator input without creating an account', function ($name, $email, $password, $confirmation) {
    $this->artisan('glos:create-admin')
        ->expectsQuestion('Administrator name', $name)
        ->expectsQuestion('Email address', $email)
        ->expectsQuestion('Password', $password)
        ->expectsQuestion('Confirm password', $confirmation)
        ->doesntExpectOutputToContain('Administrator account created successfully.')
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
})->with([
    'missing name' => ['', 'admin@example.com', 'SecureAdmin!123', 'SecureAdmin!123'],
    'invalid email' => ['Admin', 'invalid', 'SecureAdmin!123', 'SecureAdmin!123'],
    'missing password' => ['Admin', 'admin@example.com', '', ''],
    'short password' => ['Admin', 'admin@example.com', 'Ab1!', 'Ab1!'],
    'missing mixed case' => ['Admin', 'admin@example.com', 'secureadmin!123', 'secureadmin!123'],
    'missing number' => ['Admin', 'admin@example.com', 'SecureAdmin!!!', 'SecureAdmin!!!'],
    'missing symbol' => ['Admin', 'admin@example.com', 'SecureAdmin123', 'SecureAdmin123'],
    'mismatched confirmation' => ['Admin', 'admin@example.com', 'SecureAdmin!123', 'Different!123'],
]);

test('does not overwrite or promote an existing account', function () {
    $existing = User::factory()->create(['email' => 'admin@example.com', 'is_active' => false]);
    $original = $existing->refresh()->getAttributes();

    $this->artisan('glos:create-admin')
        ->expectsQuestion('Administrator name', 'Admin')
        ->expectsQuestion('Email address', 'ADMIN@example.com')
        ->expectsQuestion('Password', 'SecureAdmin!123')
        ->expectsQuestion('Confirm password', 'SecureAdmin!123')
        ->assertFailed();

    $this->assertDatabaseCount('users', 1);
    expect($existing->fresh()->getAttributes())->toBe($original);
});

test('user privileges remain excluded from mass assignment', function () {
    expect((new User)->getFillable())->toBe(['name', 'email', 'password']);
});
