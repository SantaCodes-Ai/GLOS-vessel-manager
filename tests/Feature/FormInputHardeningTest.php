<?php

use App\Models\CertificateType;
use App\Models\User;
use App\Models\Vessel;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->vesselInput = ['vessel_name' => 'Safe vessel', 'vessel_type' => 'Cargo', 'flag_state' => 'UK', 'imo_number' => '9074729', 'official_number' => 'UK-1', 'vessel_status' => 'active'];
    $this->typeInput = ['name' => 'Safety certificate', 'category' => 'Statutory', 'description' => 'Description', 'default_validity_months' => 12];
    $this->vessel = Vessel::create($this->vesselInput);
    $this->type = CertificateType::create($this->typeInput);
});

test('malformed vessel fields give validation errors and redisplay create and edit forms safely', function (string $field, mixed $value, bool $editing) {
    $url = $editing ? route('admin.vessels.edit', $this->vessel) : route('admin.vessels.create');
    $this->actingAs($this->admin)->from($url);
    $data = array_replace($this->vesselInput, [$field => $value]);
    $response = $editing ? $this->put(route('admin.vessels.update', $this->vessel), $data) : $this->post(route('admin.vessels.store'), $data);
    $response->assertRedirect($url)->assertSessionHasErrors($field);
    $this->get($url)->assertOk();
    expect($this->vessel->fresh()->only(array_keys($this->vesselInput)))->toBe($this->vesselInput);
    $this->assertDatabaseCount('vessels', 1);
})->with([
    ['vessel_name', ['nested' => ['unexpected']]],
    ['vessel_type', ['Cargo']], ['flag_state', ['UK']], ['imo_number', ['9074729']],
    ['official_number', ['UK-1']], ['vessel_status', ['active']],
    ['vessel_name', ''], ['vessel_type', ''], ['flag_state', ''],
    ['vessel_name', str_repeat('a', 256)], ['vessel_type', str_repeat('a', 256)],
    ['flag_state', str_repeat('a', 256)], ['official_number', str_repeat('a', 256)],
    ['imo_number', 'not-an-imo'], ['imo_number', '9074728'], ['vessel_status', 'deleted'],
])->with([false, true]);

test('malformed certificate type fields give validation errors and safely redisplay forms', function (string $field, mixed $value, bool $editing) {
    $url = $editing ? route('admin.certificate-types.edit', $this->type) : route('admin.certificate-types.create');
    $this->actingAs($this->admin)->from($url);
    $data = array_replace($this->typeInput, [$field => $value]);
    $response = $editing ? $this->put(route('admin.certificate-types.update', $this->type), $data) : $this->post(route('admin.certificate-types.store'), $data);
    $response->assertRedirect($url)->assertSessionHasErrors($field);
    $this->get($url)->assertOk();
    expect($this->type->fresh()->only(array_keys($this->typeInput)))->toBe($this->typeInput);
    $this->assertDatabaseCount('certificate_types', 1);
})->with([
    ['name', ['nested' => ['unexpected']]], ['category', ['Statutory']],
    ['description', ['Description']], ['default_validity_months', [12]],
    ['name', ''], ['category', ''], ['name', str_repeat('a', 256)],
    ['category', str_repeat('a', 256)], ['description', str_repeat('a', 10001)],
    ['default_validity_months', 0], ['default_validity_months', -1],
    ['default_validity_months', 4294967296],
])->with([false, true]);

test('duplicate vessel identifiers and certificate names remain validation errors', function () {
    $this->actingAs($this->admin)->post(route('admin.vessels.store'), $this->vesselInput)->assertSessionHasErrors('imo_number');
    $this->post(route('admin.certificate-types.store'), $this->typeInput)->assertSessionHasErrors('name');
    $this->assertDatabaseCount('vessels', 1);
    $this->assertDatabaseCount('certificate_types', 1);
});

test('invalid vessel identifiers do not select an arbitrary update target', function () {
    $this->actingAs($this->admin)->put('/admin/vessels/999999', $this->vesselInput)->assertNotFound();
    $this->get('/admin/vessels/not-an-id/edit')->assertNotFound();
    $this->assertDatabaseCount('vessels', 1);
});
