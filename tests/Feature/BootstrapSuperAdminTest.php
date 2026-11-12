<?php

use App\Models\User;
use App\Models\Vessel;
use App\Support\ManagePrivileges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->legacy = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $this->question = 'Convert legacy Super Admin roles to admin on the configured database?';
    $this->conversionOptions = ['--apply' => true, '--operator' => 'test-operator'];
});

test('retired bootstrap cannot create a third role or alter existing accounts', function () {
    $this->artisan('glos:bootstrap-super-admin', ['user_id' => $this->legacy->id])->assertFailed();
    expect(fn () => app(ManagePrivileges::class)->bootstrap($this->legacy->id, $this->legacy->email, 'operator', 'Historical bootstrap'))->toThrow(ValidationException::class);
    expect($this->legacy->fresh()->role)->toBe('super_admin');
    $this->assertDatabaseCount('privilege_audits', 0);
});

test('conversion defaults to dry run and cancellation changes nothing', function () {
    $this->artisan('glos:convert-admin-roles')->expectsOutputToContain('Dry run only')->assertSuccessful();
    $this->artisan('glos:convert-admin-roles', $this->conversionOptions)->expectsConfirmation($this->question, 'no')->assertFailed();
    expect($this->legacy->fresh()->role)->toBe('super_admin');
    $this->assertDatabaseCount('privilege_audits', 0);
});

test('explicit role conversion preserves credentials assignments audits and bootstrap evidence', function () {
    DB::table('privilege_management_locks')->update(['bootstrapped_at' => now()]);
    $marker = DB::table('privilege_management_locks')->value('bootstrapped_at');
    DB::table('privilege_audits')->insert(['target_user_id' => $this->legacy->id, 'operator_identifier' => 'original', 'action' => 'bootstrap', 'previous_role' => 'admin', 'new_role' => 'super_admin', 'previous_is_active' => true, 'new_is_active' => true, 'reason' => 'Original bootstrap', 'created_at' => now()]);
    $history = (array) DB::table('privilege_audits')->first();
    $before = $this->legacy->getAttributes();
    $vessel = Vessel::create(['vessel_name' => 'Conversion evidence', 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
    $this->legacy->vessels()->attach($vessel);
    $inactive = User::factory()->create(['role' => 'super_admin', 'is_active' => false]);
    $client = User::factory()->create(['role' => 'client']);
    $clientBefore = $client->fresh()->getAttributes();
    $this->artisan('glos:convert-admin-roles', $this->conversionOptions)->expectsConfirmation($this->question, 'yes')->assertSuccessful();
    expect($this->legacy->fresh()->role)->toBe('admin')
        ->and($this->legacy->fresh()->password)->toBe($before['password'])
        ->and($this->legacy->fresh()->email)->toBe($before['email'])
        ->and($this->legacy->fresh()->is_active)->toBeTrue()
        ->and(DB::table('privilege_management_locks')->value('bootstrapped_at'))->toBe($marker)
        ->and((array) DB::table('privilege_audits')->where('id', $history['id'])->first())->toBe($history);
    expect($this->legacy->fresh()->vessels()->pluck('vessels.id')->all())->toBe([$vessel->id])
        ->and($inactive->fresh()->role)->toBe('admin')->and($inactive->fresh()->is_active)->toBeFalse()
        ->and($client->fresh()->getAttributes())->toBe($clientBefore);
    $this->assertDatabaseHas('privilege_audits', ['action' => 'role_conversion', 'previous_role' => 'super_admin', 'new_role' => 'admin', 'operator_identifier' => 'test-operator']);
    $this->actingAs($this->legacy->fresh())->get(route('super-admin.registrations.index'))->assertOk();
    $this->artisan('glos:convert-admin-roles', $this->conversionOptions)->expectsConfirmation($this->question, 'yes')->assertSuccessful();
    $this->assertDatabaseCount('privilege_audits', 3);
});

test('conversion refuses unattended or unidentified writes', function (array $options) {
    $this->artisan('glos:convert-admin-roles', $options)->assertFailed();
    expect($this->legacy->fresh()->role)->toBe('super_admin');
})->with([
    [['--apply' => true]],
    [['--apply' => true, '--operator' => 'operator', '--no-interaction' => true]],
]);

test('conversion refuses a database with no accessible administrator', function () {
    $this->legacy->is_active = false;
    $this->legacy->save();
    $this->artisan('glos:convert-admin-roles', $this->conversionOptions)->expectsConfirmation($this->question, 'yes')->assertFailed();
    expect($this->legacy->fresh()->role)->toBe('super_admin');
    $this->assertDatabaseCount('privilege_audits', 0);
});

test('conversion rolls back every role when audit persistence fails', function () {
    User::factory()->create(['role' => 'super_admin']);
    DB::connection()->beforeExecuting(function ($query) {
        if (str_starts_with(strtolower($query), 'insert into "privilege_audits"')) {
            throw new RuntimeException('Simulated audit failure');
        }
    });
    $this->artisan('glos:convert-admin-roles', $this->conversionOptions)->expectsConfirmation($this->question, 'yes')->assertFailed();
    expect(User::where('role', 'super_admin')->count())->toBe(2);
    $this->assertDatabaseCount('privilege_audits', 0);
});
