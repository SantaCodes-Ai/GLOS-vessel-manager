<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Support\DeleteUser;
use App\Support\Departments;
use App\Support\ManagePrivileges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Notification::fake();
    $this->administrator = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->pending = User::factory()->create(['role' => 'client', 'requested_role' => 'admin', 'registration_status' => 'pending', 'is_active' => false, 'department' => 'Marine Operations']);
});

test('only two effective roles are granted with a compatible legacy alias', function () {
    expect(array_column(UserRole::cases(), 'value'))->toBe(['admin', 'client']);
    $legacy = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    expect($legacy->isAdmin())->toBeTrue();
    $this->actingAs($legacy)->get(route('admin.users.index'))->assertOk();
    expect($legacy->fresh()->role)->toBe('super_admin'); // Reading never converts a live account.
});

test('ordinary administrators can review and approve with just a decision', function (string $requested) {
    $this->pending->requested_role = $requested;
    $this->pending->save();
    $this->actingAs($this->administrator)->get(route('super-admin.registrations.show', $this->pending))
        ->assertOk()->assertSee('Marine Operations')->assertSee('Approve')
        ->assertDontSee('name="password"', false)->assertDontSee('name="reason"', false)->assertDontSee('name="confirm"', false);
    $this->post(route('super-admin.registrations.decide', $this->pending), ['decision' => 'approved'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($this->pending->fresh()->role)->toBe($requested)->and($this->pending->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseHas('registration_decisions', ['actor_user_id' => $this->administrator->id, 'user_id' => $this->pending->id, 'reason' => 'Verified registration approved by administrator.']);
})->with(['admin', 'client']);

test('rejection has an explicit dialog and a server-generated audit reason', function () {
    $this->actingAs($this->administrator)->get(route('super-admin.registrations.show', $this->pending))
        ->assertSee('<dialog', false)->assertSee('Cancel')->assertSee('Confirm Rejection');
    $this->post(route('super-admin.registrations.decide', $this->pending), ['decision' => 'rejected', 'reason' => 'Untrusted override'])
        ->assertSessionHasNoErrors();
    expect($this->pending->fresh()->registration_status)->toBe('rejected')->and($this->pending->fresh()->is_active)->toBeFalse();
    $this->assertDatabaseHas('registration_decisions', ['user_id' => $this->pending->id, 'reason' => 'Registration rejected by administrator.']);
});

test('ordinary administrators can confirm deletion without credentials and retain business data', function () {
    $this->actingAs($this->administrator)->get(route('super-admin.users.delete', $this->pending))
        ->assertOk()->assertSee('Are you sure you want to permanently delete this user? This action cannot be undone.')
        ->assertSee('Confirm Delete')->assertSee('Cancel')->assertSee('<dialog', false)
        ->assertDontSee('name="password"', false)->assertDontSee('name="target_email"', false);
    $this->delete(route('super-admin.users.destroy', $this->pending), ['confirmation' => '1'])->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('users', ['id' => $this->pending->id]);
    $this->assertDatabaseHas('privilege_audits', ['actor_user_id' => $this->administrator->id, 'target_user_id' => $this->pending->id, 'action' => 'delete_user']);
});

test('the sole accessible administrator cannot lose access', function (string $action) {
    User::factory()->create(['role' => 'admin', 'is_active' => false]);
    User::factory()->unverified()->create(['role' => 'admin', 'is_active' => true, 'registration_status' => 'approved']);
    if ($action === 'delete') {
        expect(fn () => app(DeleteUser::class)->delete($this->administrator->id, $this->administrator->id, DeleteUser::fingerprint($this->administrator)))->toThrow(HttpException::class);
    } else {
        expect(fn () => app(ManagePrivileges::class)->change($this->administrator->id, $this->administrator->id, $action, 'password', 'Requested account change', $this->administrator->email))->toThrow(ValidationException::class);
    }
    expect($this->administrator->fresh()->is_active)->toBeTrue()->and($this->administrator->fresh()->role)->toBe('admin');
    $this->assertDatabaseCount('privilege_audits', 0);
})->with(['demote', 'deactivate', 'delete']);

test('management navigation has collapsible groups active links and secure logout', function () {
    $response = $this->actingAs($this->administrator)->get(route('admin.users.index'));
    $response->assertOk()->assertSee('Global Link')->assertSee('Ocean Streams')->assertSee('Certificate Management')
        ->assertSee('User Management')->assertSee('Registration Approvals')->assertSee('Administrators')->assertDontSee('Super Admin')
        ->assertSee('<details class="admin-navigation" open>', false)
        ->assertSee('<details class="nav-group"  open', false)
        ->assertSee('aria-current="page"', false)->assertSee(route('logout'), false)->assertSee('name="_token"', false);
});

test('clients cannot use management or department mutation endpoints', function () {
    $client = User::factory()->create(['role' => 'client', 'is_active' => true]);
    $this->actingAs($client);
    foreach (['admin.users.index', 'super-admin.administrators.index', 'super-admin.registrations.index'] as $route) {
        $this->get(route($route))->assertForbidden();
    }
    $this->patch(route('admin.users.department.update', $client), ['department' => 'Operations'])->assertForbidden();
    $this->post(route('super-admin.registrations.decide', $this->pending), ['decision' => 'approved'])->assertForbidden();
    $this->delete(route('super-admin.users.destroy', $this->pending), ['confirmation' => '1'])->assertForbidden();
    expect($client->fresh()->department)->toBeNull()->and($this->pending->fresh()->registration_status)->toBe('pending');
});

test('department options and custom department register without granting requested privileges', function (string $requested, string $department, ?string $custom, string $stored) {
    $this->post('/register', [
        'name' => 'Department Applicant', 'email' => 'department@example.com',
        'password' => 'SafeApplicant!123', 'password_confirmation' => 'SafeApplicant!123',
        'requested_role' => $requested, 'department' => $department, 'department_other' => $custom,
    ])->assertSessionHasNoErrors();
    $user = User::where('email', 'department@example.com')->sole();
    expect($user->department)->toBe($stored)->and($user->role)->toBe('client')
        ->and($user->requested_role)->toBe($requested)->and($user->registration_status)->toBe('pending')
        ->and($user->is_active)->toBeFalse();
    $this->assertGuest();
})->with([
    ['admin', 'Operations', null, 'Operations'],
    ['client', 'Marine Operations', null, 'Marine Operations'],
    ['admin', 'Other', 'Technical Assurance', 'Technical Assurance'],
    ['client', 'Other', '  Offshore Support  ', 'Offshore Support'],
]);

test('invalid registration departments are rejected server side', function (mixed $department, mixed $custom, string $field) {
    $this->post('/register', [
        'name' => 'Invalid Department', 'email' => 'invalid-department@example.com',
        'password' => 'SafeApplicant!123', 'password_confirmation' => 'SafeApplicant!123',
        'requested_role' => 'client', 'department' => $department, 'department_other' => $custom,
    ])->assertSessionHasErrors($field);
    $this->assertDatabaseMissing('users', ['email' => 'invalid-department@example.com']);
})->with([
    [null, null, 'department'], ['Unlisted Department', null, 'department'],
    [['Operations'], null, 'department'], ['Other', null, 'department_other'],
    ['Other', '   ', 'department_other'], ['Other', str_repeat('x', 256), 'department_other'],
]);

test('administrators can edit department for every account type without changing access', function (string $role, ?string $department, ?string $custom, ?string $stored) {
    $target = User::factory()->create(['role' => $role, 'department' => 'Operations']);
    $before = $target->getAttributes();
    $this->actingAs($this->administrator)->get(route('admin.users.department.edit', $target))->assertOk();
    $this->patch(route('admin.users.department.update', $target), ['department' => $department, 'department_other' => $custom])
        ->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));
    expect($target->fresh()->department)->toBe($stored)->and($target->fresh()->role)->toBe($before['role'])
        ->and($target->fresh()->password)->toBe($before['password'])->and($target->fresh()->is_active)->toBeTrue();
    $this->get(route('admin.users.index'))->assertSee($stored ?? 'Not recorded');
})->with([
    ['admin', 'Finance / Accounts', null, 'Finance / Accounts'],
    ['client', 'Other', 'Legal', 'Legal'],
    ['super_admin', null, null, null],
]);

test('department editing rejects role injection and invalid values', function (array $input, string $field) {
    $this->actingAs($this->administrator)->patch(route('admin.users.department.update', $this->pending), $input)->assertSessionHasErrors($field);
    expect($this->pending->fresh()->department)->toBe('Marine Operations')->and($this->pending->fresh()->role)->toBe('client');
})->with([
    [['department' => 'Operations', 'role' => 'admin'], 'role'],
    [['department' => 'Operations', 'is_active' => true], 'is_active'],
    [['department' => 'Other', 'department_other' => ''], 'department_other'],
    [['department' => 'anything'], 'department'],
]);

test('every suggested department has a validated stable representation', function () {
    foreach (Departments::options() as $option) {
        if ($option !== 'Other') {
            expect(validator(['department' => $option], Departments::rules(true))->passes())->toBeTrue();
        }
    }
    expect((new User)->getFillable())->toBe(['name', 'email', 'password']);
    expect(DB::table('users')->where('id', $this->administrator->id)->value('department'))->toBeNull();
});
