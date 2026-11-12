<?php

use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\CertificateVersion;
use App\Models\User;
use App\Models\Vessel;

test('administrators see an empty dashboard and can log out', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Global Link Ocean Streams')
        ->assertSee('Welcome back, '.explode(' ', $admin->name)[0].'!')
        ->assertSee('No vessels yet')
        ->assertSee('No certificates yet')
        ->assertSee('action="'.route('logout').'"', false)
        ->assertViewHas('totalVessels', 0)
        ->assertViewHas('totalCertificates', 0)
        ->assertViewHas('totalCertificateTypes', 0);

    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('dashboard counts all records but shows only the five newest records and no document details', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $type = CertificateType::create(['name' => 'Safety Equipment', 'category' => 'Statutory']);
    CertificateType::create(['name' => 'Load Line', 'category' => 'Statutory']);

    for ($index = 1; $index <= 6; $index++) {
        $vessel = Vessel::create([
            'vessel_name' => 'Ocean Vessel '.$index,
            'vessel_type' => 'Cargo',
            'flag_state' => 'United Kingdom',
        ]);
        $certificate = Certificate::create([
            'vessel_id' => $vessel->id,
            'certificate_type_id' => $type->id,
            'certificate_number' => 'GLOS-00'.$index,
            'created_by' => $admin->id,
            'remarks' => 'Confidential internal remarks',
        ]);
    }

    CertificateVersion::create([
        'certificate_id' => $certificate->id,
        'version_number' => 1,
        'file_path' => 'private/certificates/secret.pdf',
        'original_filename' => 'sensitive-document.pdf',
    ]);

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertViewHas('totalVessels', 6)
        ->assertViewHas('totalCertificates', 6)
        ->assertViewHas('totalCertificateTypes', 2)
        ->assertViewHas('recentVessels', fn ($records) => $records->count() === 5 && $records->first()->id === $vessel->id)
        ->assertViewHas('recentCertificates', fn ($records) => $records->count() === 5 && $records->first()->id === $certificate->id)
        ->assertSeeInOrder(['Ocean Vessel 6', 'Ocean Vessel 5', 'Ocean Vessel 4'])
        ->assertSee('Safety Equipment')
        ->assertSee('GLOS-006')
        ->assertDontSee('Ocean Vessel 1')
        ->assertDontSee('GLOS-001')
        ->assertDontSee('Confidential internal remarks')
        ->assertDontSee('private/certificates/secret.pdf')
        ->assertDontSee('sensitive-document.pdf');
});

test('clients cannot access the administrator dashboard', function () {
    $client = User::factory()->create(['role' => 'client', 'is_active' => true]);

    $this->actingAs($client)->get(route('admin.dashboard'))->assertForbidden();
});

test('guests are redirected away from the administrator dashboard', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});
