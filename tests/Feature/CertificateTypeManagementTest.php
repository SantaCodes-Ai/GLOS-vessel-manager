<?php

use App\Models\CertificateType;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

function certificateTypeInput(array $overrides = []): array
{
    return array_replace([
        'name' => 'Safety Equipment Certificate',
        'category' => 'Statutory',
        'description' => 'Equipment safety certification.',
        'default_validity_months' => 12,
    ], $overrides);
}

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
});

test('administrator registers a certificate type and dashboard totals update', function () {
    $this->actingAs($this->admin)->get(route('admin.certificate-types.create'))->assertOk()->assertSee('Register certificate type');
    $this->post(route('admin.certificate-types.store'), certificateTypeInput(['id' => 999, 'created_at' => '2000-01-01']))
        ->assertSessionHasNoErrors()->assertSessionHas('success')
        ->assertRedirect(route('admin.certificate-types.show', CertificateType::sole()));
    $this->assertDatabaseHas('certificate_types', certificateTypeInput());
    expect(CertificateType::sole()->id)->not->toBe(999);
    $this->get(route('admin.dashboard'))->assertViewHas('totalCertificateTypes', 1);
});

test('administrator edits a certificate type and can retain its name', function () {
    $type = CertificateType::create(certificateTypeInput());
    $this->actingAs($this->admin)->get(route('admin.certificate-types.edit', $type))->assertOk();
    $this->put(route('admin.certificate-types.update', $type), certificateTypeInput(['category' => 'Safety', 'default_validity_months' => 24]))
        ->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect(route('admin.certificate-types.show', $type));
    expect($type->fresh()->category)->toBe('Safety')->and($type->fresh()->default_validity_months)->toBe(24);
    $this->put(route('admin.certificate-types.update', $type), certificateTypeInput(['name' => 'Updated Safety Certificate']))->assertSessionHasNoErrors();
    expect($type->fresh()->name)->toBe('Updated Safety Certificate');
});

test('certificate type validation rejects invalid input', function (array $overrides, string $field) {
    $this->actingAs($this->admin)->post(route('admin.certificate-types.store'), certificateTypeInput($overrides))
        ->assertSessionHasErrors($field);
    $this->assertDatabaseCount('certificate_types', 0);
})->with([
    'name required' => [['name' => ''], 'name'],
    'category required' => [['category' => ''], 'category'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'category too long' => [['category' => str_repeat('a', 256)], 'category'],
    'description not text' => [['description' => ['invalid']], 'description'],
    'description too long' => [['description' => str_repeat('a', 10001)], 'description'],
    'zero months' => [['default_validity_months' => 0], 'default_validity_months'],
    'negative months' => [['default_validity_months' => -1], 'default_validity_months'],
    'fractional months' => [['default_validity_months' => 1.5], 'default_validity_months'],
    'non-numeric months' => [['default_validity_months' => 'annual'], 'default_validity_months'],
    'overflow months' => [['default_validity_months' => '4294967296'], 'default_validity_months'],
]);

test('duplicate names are rejected during registration and update', function () {
    CertificateType::create(certificateTypeInput());
    $other = CertificateType::create(certificateTypeInput(['name' => 'Load Line']));
    $this->actingAs($this->admin)->post(route('admin.certificate-types.store'), certificateTypeInput())->assertSessionHasErrors('name');
    $this->put(route('admin.certificate-types.update', $other), certificateTypeInput())->assertSessionHasErrors('name');
    expect($other->fresh()->name)->toBe('Load Line');
    $this->assertDatabaseCount('certificate_types', 2);
});

test('description and validity months can be omitted and cleared', function () {
    $this->actingAs($this->admin)->post(route('admin.certificate-types.store'), ['name' => 'Load Line', 'category' => 'Statutory'])->assertSessionHasNoErrors();
    $type = CertificateType::sole();
    expect($type->description)->toBeNull()->and($type->default_validity_months)->toBeNull();
    $this->put(route('admin.certificate-types.update', $type), certificateTypeInput(['description' => '', 'default_validity_months' => '']))->assertSessionHasNoErrors();
    expect($type->fresh()->description)->toBeNull()->and($type->fresh()->default_validity_months)->toBeNull();
});

test('certificate types can be searched by name or category', function () {
    $safety = CertificateType::create(certificateTypeInput());
    $class = CertificateType::create(certificateTypeInput(['name' => 'Hull Survey', 'category' => 'Classification']));
    $this->actingAs($this->admin)->get(route('admin.certificate-types.index', ['search' => 'Equipment']))
        ->assertOk()->assertViewHas('certificateTypes', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $safety->id);
    $this->get(route('admin.certificate-types.index', ['search' => 'Classification']))
        ->assertViewHas('certificateTypes', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $class->id);
    $this->get(route('admin.certificate-types.index', ['search' => 'missing']))->assertSee('No matching certificate types');
});

test('certificate type pagination preserves the search', function () {
    for ($index = 1; $index <= 16; $index++) {
        CertificateType::create(certificateTypeInput(['name' => 'Safety Type '.$index]));
    }
    $this->actingAs($this->admin)->get(route('admin.certificate-types.index', ['search' => 'Safety']))
        ->assertViewHas('certificateTypes', fn ($rows) => $rows->total() === 16 && $rows->count() === 15 && str_contains($rows->nextPageUrl(), 'search=Safety'));
    $this->get(route('admin.certificate-types.index', ['search' => 'Safety', 'page' => 2]))
        ->assertViewHas('certificateTypes', fn ($rows) => $rows->count() === 1);
});

test('administrator can view details and an empty listing without a deletion endpoint', function () {
    $this->actingAs($this->admin)->get(route('admin.certificate-types.index'))->assertOk()->assertSee('No certificate types registered yet');
    $type = CertificateType::create(certificateTypeInput(['description' => '<script>alert(1)</script>']));
    $this->get(route('admin.certificate-types.show', $type))->assertOk()->assertSee($type->name)->assertSee('12 months')
        ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    $this->get('/admin/certificate-types/9999')->assertNotFound();
    $this->delete(route('admin.certificate-types.show', $type))->assertStatus(405);
    $this->assertDatabaseCount('certificate_types', 1);
});

test('all certificate type endpoints require an active administrator', function (string $actor) {
    $type = CertificateType::create(certificateTypeInput());
    if ($actor !== 'guest') {
        $this->actingAs(User::factory()->create(['role' => $actor === 'client' ? 'client' : 'admin', 'is_active' => $actor !== 'inactive']));
    }
    foreach ([['GET', '/admin/certificate-types'], ['GET', '/admin/certificate-types/create'], ['POST', '/admin/certificate-types'], ['GET', '/admin/certificate-types/'.$type->id], ['GET', '/admin/certificate-types/'.$type->id.'/edit'], ['PUT', '/admin/certificate-types/'.$type->id], ['PATCH', '/admin/certificate-types/'.$type->id]] as [$method, $uri]) {
        $response = $this->call($method, $uri, certificateTypeInput(['name' => 'Unauthorized change']));
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
    }
    $this->assertDatabaseCount('certificate_types', 1);
    expect($type->fresh()->name)->toBe('Safety Equipment Certificate');
})->with(['guest', 'client', 'inactive']);

test('database uniqueness conflicts return a safe certificate name error', function (string $operation) {
    $type = CertificateType::create(certificateTypeInput());
    CertificateType::saving(function () {
        throw new UniqueConstraintViolationException('sqlite', 'test insert', [], new PDOException('duplicate'));
    });
    $this->actingAs($this->admin);
    $response = $operation === 'store'
        ? $this->post(route('admin.certificate-types.store'), certificateTypeInput(['name' => 'New Type']))
        : $this->put(route('admin.certificate-types.update', $type), certificateTypeInput(['name' => 'New Type']));
    $response->assertSessionHasErrors(['name' => 'A certificate type with this name already exists.']);
    $this->assertDatabaseCount('certificate_types', 1);
})->with(['store', 'update']);
