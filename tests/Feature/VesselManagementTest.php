<?php

use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\UniqueConstraintViolationException;

function vesselInput(array $overrides = []): array
{
    return array_replace([
        'vessel_name' => 'Ocean Pioneer',
        'imo_number' => '9074729',
        'vessel_type' => 'Cargo',
        'flag_state' => 'United Kingdom',
        'official_number' => 'UK-123',
        'vessel_status' => 'active',
    ], $overrides);
}

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
});

test('administrator registers a vessel and the dashboard count updates', function () {
    $this->actingAs($this->admin)->get(route('admin.vessels.create'))->assertOk()->assertSee('Register vessel');
    $this->post(route('admin.vessels.store'), vesselInput(['id' => 999, 'created_at' => '2000-01-01']))
        ->assertSessionHasNoErrors()->assertSessionHas('success')
        ->assertRedirect(route('admin.vessels.show', Vessel::sole()));
    $this->assertDatabaseHas('vessels', vesselInput());
    expect(Vessel::sole()->id)->not->toBe(999);
    $this->get(route('admin.dashboard'))->assertViewHas('totalVessels', 1);
});

test('administrator edits a vessel while keeping its own IMO number', function () {
    $vessel = Vessel::create(vesselInput());
    $this->actingAs($this->admin)->get(route('admin.vessels.edit', $vessel))->assertOk();
    $this->put(route('admin.vessels.update', $vessel), vesselInput(['vessel_name' => 'Ocean Explorer', 'vessel_status' => 'inactive']))
        ->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect(route('admin.vessels.show', $vessel));
    expect($vessel->fresh()->vessel_name)->toBe('Ocean Explorer')
        ->and($vessel->fresh()->vessel_status)->toBe('inactive');
});

test('vessel validation rejects invalid input', function (array $overrides, string $field) {
    $this->actingAs($this->admin)->post(route('admin.vessels.store'), vesselInput($overrides))
        ->assertSessionHasErrors($field);
    $this->assertDatabaseCount('vessels', 0);
})->with([
    'name required' => [['vessel_name' => ''], 'vessel_name'],
    'type required' => [['vessel_type' => ''], 'vessel_type'],
    'flag required' => [['flag_state' => ''], 'flag_state'],
    'status required' => [['vessel_status' => ''], 'vessel_status'],
    'invalid status' => [['vessel_status' => 'deleted'], 'vessel_status'],
    'short IMO' => [['imo_number' => '123456'], 'imo_number'],
    'long IMO' => [['imo_number' => '12345678'], 'imo_number'],
    'non-digit IMO' => [['imo_number' => 'IMO1234'], 'imo_number'],
    'bad checksum' => [['imo_number' => '9074728'], 'imo_number'],
    'name too long' => [['vessel_name' => str_repeat('a', 256)], 'vessel_name'],
    'official number too long' => [['official_number' => str_repeat('a', 256)], 'official_number'],
]);

test('IMO uniqueness is enforced on registration and update', function () {
    Vessel::create(vesselInput());
    $other = Vessel::create(vesselInput(['imo_number' => null]));
    $this->actingAs($this->admin)->post(route('admin.vessels.store'), vesselInput())->assertSessionHasErrors('imo_number');
    $this->put(route('admin.vessels.update', $other), vesselInput())->assertSessionHasErrors('imo_number');
    expect($other->fresh()->imo_number)->toBeNull();
    $this->assertDatabaseCount('vessels', 2);
});

test('multiple vessels can have no IMO or official number', function () {
    $this->actingAs($this->admin);
    for ($index = 0; $index < 2; $index++) {
        $this->post(route('admin.vessels.store'), vesselInput(['imo_number' => '', 'official_number' => '']))->assertSessionHasNoErrors();
    }
    $this->assertDatabaseCount('vessels', 2);
    expect(Vessel::whereNull('imo_number')->count())->toBe(2);
});

test('vessels can be searched by name or IMO and filtered by status together', function () {
    $active = Vessel::create(vesselInput(['vessel_name' => 'Northern Star']));
    $inactive = Vessel::create(vesselInput(['vessel_name' => 'Southern Star', 'imo_number' => null, 'vessel_status' => 'inactive']));
    $this->actingAs($this->admin)->get(route('admin.vessels.index', ['search' => 'Northern']))
        ->assertViewHas('vessels', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $active->id);
    $this->get(route('admin.vessels.index', ['search' => '9074729']))
        ->assertViewHas('vessels', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $active->id);
    $this->get(route('admin.vessels.index', ['search' => 'Star', 'status' => 'inactive']))
        ->assertViewHas('vessels', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $inactive->id);
    $this->get(route('admin.vessels.index', ['status' => 'active']))
        ->assertViewHas('vessels', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $active->id);
    $this->get(route('admin.vessels.index', ['search' => 'missing']))->assertSee('No matching vessels');
});

test('listing paginates and preserves filters', function () {
    for ($index = 0; $index < 16; $index++) {
        Vessel::create(vesselInput(['imo_number' => null]));
    }
    $this->actingAs($this->admin)->get(route('admin.vessels.index', ['search' => 'Ocean', 'status' => 'active']))
        ->assertOk()->assertViewHas('vessels', fn ($rows) => $rows->count() === 15 && $rows->total() === 16 && str_contains($rows->nextPageUrl(), 'search=Ocean') && str_contains($rows->nextPageUrl(), 'status=active'));
    $this->get(route('admin.vessels.index', ['page' => 2]))
        ->assertViewHas('vessels', fn ($rows) => $rows->count() === 1);
});

test('administrator can view details and empty listings', function () {
    $this->actingAs($this->admin)->get(route('admin.vessels.index'))->assertOk()->assertSee('No vessels registered yet');
    $vessel = Vessel::create(vesselInput());
    $this->get(route('admin.vessels.show', $vessel))->assertOk()->assertSee('Ocean Pioneer')->assertSee('9074729')->assertSee('UK-123');
    $this->get('/admin/vessels/9999')->assertNotFound();
    $this->delete(route('admin.vessels.show', $vessel))->assertStatus(405);
    $this->assertDatabaseCount('vessels', 1);
});

test('all vessel endpoints require an active administrator', function (string $actor) {
    $vessel = Vessel::create(vesselInput());
    if ($actor !== 'guest') {
        $this->actingAs(User::factory()->create(['role' => $actor === 'client' ? 'client' : 'admin', 'is_active' => $actor !== 'inactive']));
    }
    foreach ([['GET', '/admin/vessels'], ['GET', '/admin/vessels/create'], ['POST', '/admin/vessels'], ['GET', '/admin/vessels/'.$vessel->id], ['GET', '/admin/vessels/'.$vessel->id.'/edit'], ['PUT', '/admin/vessels/'.$vessel->id], ['PATCH', '/admin/vessels/'.$vessel->id]] as [$method, $uri]) {
        $response = $this->call($method, $uri, vesselInput(['vessel_name' => 'Unauthorized change']));
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
    }
    $this->assertDatabaseCount('vessels', 1);
    expect($vessel->fresh()->vessel_name)->toBe('Ocean Pioneer');
})->with(['guest', 'client', 'inactive']);

test('database uniqueness races return a safe field error', function (string $operation) {
    $vessel = Vessel::create(vesselInput(['imo_number' => null]));
    Vessel::saving(function () {
        throw new UniqueConstraintViolationException('sqlite', 'test insert', [], new PDOException('duplicate'));
    });
    $this->actingAs($this->admin);
    $response = $operation === 'store'
        ? $this->post(route('admin.vessels.store'), vesselInput())
        : $this->put(route('admin.vessels.update', $vessel), vesselInput());
    $response->assertSessionHasErrors(['imo_number' => 'This IMO number is already registered.']);
    $this->assertDatabaseCount('vessels', 1);
})->with(['store', 'update']);
