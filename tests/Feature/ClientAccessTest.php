<?php

use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\CertificateVersion;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CertificateDashboard;
use App\Support\ClientRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function clientAccessVersion(Certificate $certificate, int $number, array $overrides = []): CertificateVersion
{
    $path = 'vessels/'.$certificate->vessel_id.'/'.Str::uuid().'.pdf';
    Storage::disk('certificates')->put($path, '%PDF-1.4 certificate '.$certificate->id.' version '.$number);

    return $certificate->versions()->create(array_replace([
        'version_number' => $number, 'certificate_number' => 'REF-'.$certificate->id.'-'.$number,
        'issuance_date' => '2025-01-01', 'expiry_type' => 'fixed', 'expiry_date' => '2026-10-29',
        'file_path' => $path, 'original_filename' => 'private-original.pdf', 'uploaded_at' => now(),
    ], $overrides));
}

beforeEach(function () {
    Storage::fake('certificates');
    config(['app.timezone' => 'Europe/London']);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00', 'Europe/London'));
    $this->client = User::factory()->create(['name' => 'Ocean Client', 'role' => 'client', 'is_active' => true]);
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->assigned = Vessel::create(['vessel_name' => 'Assigned Pioneer', 'imo_number' => '9074729', 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
    $this->otherAssigned = Vessel::create(['vessel_name' => 'Assigned Explorer', 'vessel_type' => 'Tanker', 'flag_state' => 'UK']);
    $this->unassigned = Vessel::create(['vessel_name' => 'Secret Vessel', 'imo_number' => '9652806', 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
    $this->type = CertificateType::create(['name' => 'Safety Equipment', 'category' => 'Statutory']);
    $secretType = CertificateType::create(['name' => 'Secret Type', 'category' => 'Internal']);
    $this->certificate = Certificate::create([
        'vessel_id' => $this->assigned->id, 'certificate_type_id' => $this->type->id, 'remarks' => 'Internal administrator remarks', 'created_by' => $this->admin->id,
    ]);
    $this->secretCertificate = Certificate::create(['vessel_id' => $this->unassigned->id, 'certificate_type_id' => $secretType->id]);
    $this->historical = clientAccessVersion($this->certificate, 1, ['expiry_date' => '2020-01-01']);
    $this->current = clientAccessVersion($this->certificate, 2, ['expiry_date' => '2027-12-31']);
    $this->secretVersion = clientAccessVersion($this->secretCertificate, 9);
    $this->client->vessels()->attach([$this->assigned->id, $this->otherAssigned->id]);
});

test('client dashboard contains only assigned current-version totals and no administrator controls', function () {
    $this->actingAs($this->client)->get(route('client.dashboard'))->assertOk()->assertSee('Welcome back, Ocean!')
        ->assertViewHas('totalVessels', 2)->assertViewHas('totalCertificates', 1)
        ->assertViewHas('statusCounts', ['permanent' => 0, 'valid' => 1, 'expiring-soon' => 0, 'critical' => 0, 'expired' => 0, 'unknown' => 0])
        ->assertViewHas('attentionCertificates', fn ($rows) => $rows->isEmpty())
        ->assertSee('Assigned Pioneer')->assertSee('Assigned Explorer')->assertDontSee('Secret Vessel')->assertDontSee('Secret Type')
        ->assertDontSee('/admin/', false)->assertDontSee('Register Certificate')->assertDontSee('Renew Certificate')
        ->assertSee('Sign out');
});

test('clients with zero or one vessel assignments have correct empty states and totals', function (int $count) {
    $this->client->vessels()->sync($count === 0 ? [] : [$this->assigned->id]);
    $response = $this->actingAs($this->client)->get(route('client.dashboard'))->assertOk()
        ->assertViewHas('totalVessels', $count)->assertViewHas('totalCertificates', $count);
    if ($count === 0) {
        $response->assertSee('No assigned vessels found');
        $this->get(route('client.vessels.index'))->assertSee('No assigned vessels found');
        $this->get(route('client.certificates.index'))->assertSee('No certificates found');
    }
})->with([0, 1]);

test('vessel listings searches and details exclude unassigned records', function () {
    $this->actingAs($this->client)->get(route('client.vessels.index'))->assertOk()->assertSee('Assigned Pioneer')->assertDontSee('Secret Vessel')
        ->assertViewHas('vessels', fn ($rows) => $rows->total() === 2);
    $this->get(route('client.vessels.index', ['search' => 'Pioneer']))->assertViewHas('vessels', fn ($rows) => $rows->total() === 1);
    $this->get(route('client.vessels.index', ['search' => '9074729']))->assertViewHas('vessels', fn ($rows) => $rows->total() === 1);
    $this->get(route('client.vessels.index', ['search' => '9652806']))->assertViewHas('vessels', fn ($rows) => $rows->isEmpty());
    $this->get(route('client.vessels.show', $this->assigned))->assertOk()->assertSee('Safety Equipment')->assertDontSee('Secret Type');
    $this->get(route('client.vessels.show', $this->unassigned))->assertNotFound();
    $this->get(route('client.vessels.show', $this->otherAssigned))->assertOk()->assertSee('No certificates found');
});

test('certificate listing search and details enforce assignments and show current and historical metadata', function () {
    $this->actingAs($this->client)->get(route('client.certificates.index'))->assertOk()->assertSee('Safety Equipment')->assertSee('Version 2')->assertSee('Valid')
        ->assertDontSee('Secret Type')->assertViewHas('certificates', fn ($rows) => $rows->total() === 1);
    foreach (['Assigned', 'Equipment'] as $search) {
        $this->get(route('client.certificates.index', ['search' => $search]))->assertViewHas('certificates', fn ($rows) => $rows->total() === 1);
    }
    $this->get(route('client.certificates.index', ['search' => 'Secret']))->assertViewHas('certificates', fn ($rows) => $rows->isEmpty());
    $this->get(route('client.certificates.show', $this->certificate))->assertOk()->assertSee('Current')->assertSee('Historical')->assertSee('Valid')
        ->assertSee($this->current->certificate_number)->assertSee($this->historical->certificate_number)
        ->assertDontSee('Internal administrator remarks')->assertDontSee($this->current->file_path)->assertDontSee($this->historical->file_path)
        ->assertDontSee('Renew Certificate')->assertDontSee('type="file"', false)->assertDontSee('/admin/', false);
    $this->get(route('client.certificates.show', $this->secretCertificate))->assertNotFound();
});

test('client dashboard counts all statuses using current versions only and scopes its attention list', function () {
    foreach (['expired' => '2026-10-28', 'critical' => '2026-10-29', 'expiring-soon' => '2026-11-28', 'permanent' => null] as $status => $date) {
        $certificate = Certificate::create(['vessel_id' => $this->otherAssigned->id, 'certificate_type_id' => $this->type->id]);
        clientAccessVersion($certificate, 1, ['expiry_date' => $date, 'expiry_type' => $status === 'permanent' ? 'permanent' : 'fixed']);
    }
    $this->actingAs($this->client)->get(route('client.dashboard'))->assertOk()->assertViewHas('totalCertificates', 5)
        ->assertViewHas('statusCounts', ['permanent' => 1, 'valid' => 1, 'expiring-soon' => 1, 'critical' => 1, 'expired' => 1, 'unknown' => 0])
        ->assertViewHas('attentionCertificates', fn ($rows) => $rows->count() === 3 && ! $rows->contains('id', $this->secretCertificate->id) && ! $rows->contains('id', $this->certificate->id));
});

test('clients can preview and download authorised current and historical PDFs with security headers', function (string $action, bool $historical) {
    $version = $historical ? $this->historical : $this->current;
    $url = $historical ? route('client.certificates.versions.'.$action, [$this->certificate, $version->version_number]) : route('client.certificates.'.$action, $this->certificate);
    $response = $this->actingAs($this->client)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    if ($action === 'download') {
        $response->assertDownload('certificate-'.$this->certificate->id.'-v'.$version->version_number.'.pdf');
    } else {
        expect($response->headers->get('Content-Disposition'))->toStartWith('inline;');
    }
    expect($response->streamedContent())->toBe(Storage::disk('certificates')->get($version->file_path));
})->with([['preview', false], ['download', false], ['preview', true], ['download', true]]);

test('unassigned PDFs and versions from another certificate are never accessible', function (string $action) {
    $this->actingAs($this->client)->get(route('client.certificates.'.$action, $this->secretCertificate))->assertNotFound();
    $this->get(route('client.certificates.versions.'.$action, [$this->secretCertificate, 9]))->assertNotFound();
    $this->get(route('client.certificates.versions.'.$action, [$this->certificate, 9]))->assertNotFound();
    // Query-string paths cannot replace the authorised path resolved from the version record.
    $response = $this->get(route('client.certificates.'.$action, [$this->certificate, 'path' => $this->secretVersion->file_path]))->assertOk();
    expect($response->streamedContent())->toBe(Storage::disk('certificates')->get($this->current->file_path));
})->with(['preview', 'download']);

test('missing and unsafe document paths return not found', function () {
    Storage::disk('certificates')->delete($this->current->file_path);
    $this->actingAs($this->client)->get(route('client.certificates.preview', $this->certificate))->assertNotFound();
    $this->get(route('client.certificates.download', $this->certificate))->assertNotFound();
    $this->historical->update(['file_path' => '../outside.pdf']);
    $this->get(route('client.certificates.versions.preview', [$this->certificate, 1]))->assertNotFound();
});

test('all client routes require an authenticated active client', function (string $actor) {
    if ($actor !== 'guest') {
        $user = $actor === 'admin' ? $this->admin : User::factory()->create(['role' => 'client', 'is_active' => false]);
        $this->actingAs($user);
    }
    foreach ([
        route('client.dashboard'), route('client.vessels.index'), route('client.vessels.show', $this->assigned),
        route('client.certificates.index'), route('client.certificates.show', $this->certificate),
        route('client.certificates.preview', $this->certificate), route('client.certificates.download', $this->certificate),
        route('client.certificates.versions.preview', [$this->certificate, 1]), route('client.certificates.versions.download', [$this->certificate, 1]),
    ] as $url) {
        $response = $this->get($url);
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
    }
})->with(['guest', 'admin', 'inactive']);

test('assignment removal revokes subsequent metadata and document access despite a cached relationship', function () {
    $this->client->load('vessels');
    $this->actingAs($this->client)->get(route('client.certificates.preview', $this->certificate))->assertOk();
    $this->actingAs($this->admin)->put(route('admin.clients.update', $this->client), [
        'name' => $this->client->name, 'email' => $this->client->email, 'is_active' => '1', 'vessel_ids' => [$this->otherAssigned->id],
    ])->assertSessionHasNoErrors();
    expect($this->client->vessels)->toHaveCount(2); // Deliberately stale: authorisation must query the pivot again.
    $this->actingAs($this->client);
    foreach ([
        route('client.vessels.show', $this->assigned), route('client.certificates.show', $this->certificate),
        route('client.certificates.preview', $this->certificate), route('client.certificates.download', $this->certificate),
        route('client.certificates.versions.preview', [$this->certificate, 1]), route('client.certificates.versions.download', [$this->certificate, 1]),
    ] as $url) {
        $this->get($url)->assertNotFound();
    }
    $this->get(route('client.vessels.index'))->assertDontSee('Assigned Pioneer');
    $this->get(route('client.certificates.index'))->assertViewHas('certificates', fn ($rows) => $rows->total() === 0);
    $this->get(route('client.dashboard'))->assertViewHas('totalVessels', 1)->assertViewHas('totalCertificates', 0);
    $this->get(route('client.vessels.show', $this->otherAssigned))->assertOk();
});

test('clients cannot use administrator management or write through client routes', function () {
    $this->actingAs($this->client);
    foreach ([
        ['GET', '/admin/dashboard'], ['GET', '/admin/vessels'], ['POST', '/admin/vessels'], ['PUT', '/admin/vessels/'.$this->assigned->id],
        ['POST', '/admin/certificates'], ['POST', '/admin/certificates/'.$this->certificate->id.'/renew'],
        ['POST', '/admin/certificate-types'], ['POST', '/admin/clients'], ['PUT', '/admin/clients/'.$this->client->id],
    ] as [$method, $url]) {
        $this->call($method, $url)->assertForbidden();
    }
    foreach ([['POST', '/client/vessels'], ['PUT', '/client/vessels/'.$this->assigned->id], ['POST', '/client/certificates'], ['PATCH', '/client/certificates/'.$this->certificate->id]] as [$method, $url]) {
        $this->call($method, $url)->assertStatus(405);
    }
    $this->post('/client/certificates/'.$this->certificate->id.'/renew')->assertNotFound();
    $this->get('/client/vessels/'.$this->assigned->id.'/edit')->assertNotFound();
    $this->assertDatabaseCount('certificates', 2);
    $this->assertDatabaseCount('certificate_versions', 3);
    $this->assertDatabaseCount('vessel_user_access', 2);
});

test('client listings and histories paginate without revealing other records', function () {
    for ($number = 3; $number <= 17; $number++) {
        $vessel = Vessel::create(['vessel_name' => 'Assigned '.$number, 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
        $this->client->vessels()->attach($vessel);
        $certificate = Certificate::create(['vessel_id' => $vessel->id, 'certificate_type_id' => $this->type->id]);
        clientAccessVersion($this->certificate, $number);
    }
    $this->actingAs($this->client)->get(route('client.vessels.index', ['search' => 'Assigned']))
        ->assertViewHas('vessels', fn ($rows) => $rows->total() === 17 && $rows->count() === 15 && str_contains($rows->nextPageUrl(), 'search=Assigned'));
    $this->get(route('client.vessels.index', ['page' => 2]))->assertViewHas('vessels', fn ($rows) => $rows->count() === 2);
    $this->get(route('client.certificates.index', ['search' => 'Safety']))
        ->assertViewHas('certificates', fn ($rows) => $rows->total() === 16 && $rows->count() === 15 && str_contains($rows->nextPageUrl(), 'search=Safety'));
    $this->get(route('client.certificates.index', ['page' => 2]))->assertViewHas('certificates', fn ($rows) => $rows->count() === 1);
    $this->get(route('client.certificates.show', [$this->certificate, 'page' => 2]))
        ->assertViewHas('versions', fn ($rows) => $rows->total() === 17 && $rows->count() === 2);
});

test('scoped dashboard eagerly loads data without per-certificate queries', function () {
    for ($number = 0; $number < 12; $number++) {
        $certificate = Certificate::create(['vessel_id' => $this->assigned->id, 'certificate_type_id' => $this->type->id]);
        clientAccessVersion($certificate, 1);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $summary = app(CertificateDashboard::class)->summary(app(ClientRecords::class)->certificates($this->client));
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($count)->toBeLessThanOrEqual(6)->and($summary['statusCounts']['critical'])->toBe(12)
        ->and($summary['attentionCertificates'])->toHaveCount(10);
});

test('clients can securely log out of the portal', function () {
    $this->actingAs($this->client)->withSession(['private_marker' => 'client data']);
    $token = session()->token();
    $this->post(route('logout'))->assertRedirect(route('login'))->assertSessionMissing('private_marker');
    $this->assertGuest();
    expect(session()->token())->not->toBe($token);
    $this->get(route('client.dashboard'))->assertRedirect(route('login'));
});
