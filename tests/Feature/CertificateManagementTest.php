<?php

use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\CertificateVersion;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\ValidPdf;

function certificatePdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('safety-certificate.pdf', ValidPdf::contents());
}

beforeEach(function () {
    Storage::fake('certificates');
    Storage::fake('public');
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->vessel = Vessel::create(['vessel_name' => 'Ocean Pioneer', 'vessel_type' => 'Cargo', 'flag_state' => 'United Kingdom']);
    $this->type = CertificateType::create(['name' => 'Safety Equipment', 'category' => 'Statutory']);
    $this->input = [
        'vessel_id' => $this->vessel->id,
        'certificate_type_id' => $this->type->id,
        'certificate_number' => 'GLOS-001',
        'issuance_date' => '2026-01-01',
        'expiry_type' => 'fixed',
        'expiry_date' => '2027-01-01',
        'remarks' => 'Test certificate',
    ];
});

test('administrator registers a private PDF and exactly one initial version', function () {
    $this->actingAs($this->admin)->get(route('admin.certificates.create'))->assertOk()->assertSee('multipart/form-data', false);
    $this->post(route('admin.certificates.store'), array_merge($this->input, [
        'document' => certificatePdf(), 'created_by' => 999, 'uploaded_by' => 999, 'version_number' => 99, 'file_path' => '../public/evil.php',
    ]))->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect(route('admin.certificates.show', Certificate::sole()));

    $certificate = Certificate::sole();
    $version = CertificateVersion::sole();
    expect($certificate->vessel_id)->toBe($this->vessel->id)
        ->and($certificate->certificate_type_id)->toBe($this->type->id)
        ->and($certificate->created_by)->toBe($this->admin->id)
        ->and($version->certificate_id)->toBe($certificate->id)
        ->and($version->version_number)->toBe(1)
        ->and($version->uploaded_by)->toBe($this->admin->id)
        ->and($version->original_filename)->toBe('safety-certificate.pdf')
        ->and($version->uploaded_at)->not->toBeNull()
        ->and($version->file_path)->toMatch('#^vessels/'.$this->vessel->id.'/[a-f0-9-]{36}\.pdf$#')
        ->and($version->issuance_date->format('Y-m-d'))->toBe('2026-01-01')
        ->and($version->expiry_date->format('Y-m-d'))->toBe('2027-01-01');
    Storage::disk('certificates')->assertExists($version->file_path);
    expect(Storage::disk('certificates')->getVisibility($version->file_path))->toBe('private');
    expect(Storage::disk('public')->allFiles())->toBe([]);
    $this->get(route('admin.certificates.show', $certificate))->assertOk()->assertSee('GLOS-001')->assertSee('Preview PDF')->assertSee('Download PDF')->assertDontSee($version->file_path);
    $this->get(route('admin.dashboard'))->assertViewHas('totalCertificates', 1);
});

test('certificate registration validates required associations and dates', function (array $overrides, string $field) {
    $this->actingAs($this->admin)->post(route('admin.certificates.store'), array_replace($this->input, ['document' => certificatePdf()], $overrides))
        ->assertSessionHasErrors($field);
    $this->assertDatabaseCount('certificates', 0);
    $this->assertDatabaseCount('certificate_versions', 0);
    expect(Storage::disk('certificates')->allFiles())->toBe([]);
})->with([
    'missing vessel' => [['vessel_id' => null], 'vessel_id'],
    'invalid vessel' => [['vessel_id' => 9999], 'vessel_id'],
    'missing type' => [['certificate_type_id' => null], 'certificate_type_id'],
    'invalid type' => [['certificate_type_id' => 9999], 'certificate_type_id'],
    'missing expiry type' => [['expiry_type' => null], 'expiry_type'],
    'invalid expiry type' => [['expiry_type' => 'never'], 'expiry_type'],
    'missing fixed date' => [['expiry_date' => null], 'expiry_date'],
    'invalid issue date' => [['issuance_date' => '2026-02-30'], 'issuance_date'],
    'invalid expiry date' => [['expiry_date' => 'not-a-date'], 'expiry_date'],
    'expiry before issue' => [['expiry_date' => '2025-12-31'], 'expiry_date'],
    'missing document' => [['document' => null], 'document'],
    'long reference' => [['certificate_number' => str_repeat('x', 256)], 'certificate_number'],
    'invalid remarks' => [['remarks' => ['invalid']], 'remarks'],
]);

test('permanent certificates discard expiry dates and optional metadata may be blank', function () {
    $this->actingAs($this->admin)->post(route('admin.certificates.store'), array_replace($this->input, [
        'document' => certificatePdf(), 'expiry_type' => 'permanent', 'expiry_date' => 'invalid-discarded-value',
        'issuance_date' => '', 'certificate_number' => '', 'remarks' => '',
    ]))->assertSessionHasNoErrors();
    expect(CertificateVersion::sole()->expiry_date)->toBeNull()
        ->and(CertificateVersion::sole()->issuance_date)->toBeNull()
        ->and(CertificateVersion::sole()->expiry_type)->toBe('permanent')
        ->and(Certificate::sole()->certificate_number)->toBeNull();
});

test('fixed expiry can equal issuance or be supplied without issuance', function (mixed $issuance) {
    $this->actingAs($this->admin)->post(route('admin.certificates.store'), array_replace($this->input, [
        'document' => certificatePdf(), 'issuance_date' => $issuance, 'expiry_date' => '2027-01-01',
    ]))->assertSessionHasNoErrors();
})->with([null, '2027-01-01']);

test('non-PDF disguised and oversized uploads are rejected', function (string $kind) {
    $pdf = certificatePdf();
    $document = match ($kind) {
        'text' => UploadedFile::fake()->createWithContent('notes.txt', 'not a pdf'),
        'disguised' => UploadedFile::fake()->createWithContent('fake.pdf', '<?php echo "not PDF";'),
        'wrong extension' => UploadedFile::fake()->createWithContent('unsafe.php', file_get_contents($pdf->getRealPath())),
        'oversized' => UploadedFile::fake()->createWithContent('large.pdf', file_get_contents($pdf->getRealPath()).str_repeat(' ', 10240 * 1024)),
    };
    $this->actingAs($this->admin)->post(route('admin.certificates.store'), array_merge($this->input, ['document' => $document]))->assertSessionHasErrors('document');
    $this->assertDatabaseCount('certificates', 0);
    expect(Storage::disk('certificates')->allFiles())->toBe([]);
})->with(['text', 'disguised', 'wrong extension', 'oversized']);

test('administrator can preview and download PDF bytes with safe headers', function () {
    $pdf = certificatePdf();
    $bytes = file_get_contents($pdf->getRealPath());
    $this->actingAs($this->admin)->post(route('admin.certificates.store'), array_merge($this->input, ['document' => $pdf]))->assertSessionHasNoErrors();
    $certificate = Certificate::sole();
    $preview = $this->get(route('admin.certificates.preview', $certificate))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
    expect($preview->headers->get('Content-Disposition'))->toStartWith('inline;');
    expect($preview->headers->get('Cache-Control'))->toContain('no-store');
    expect($preview->streamedContent())->toBe($bytes);
    $this->get(route('admin.certificates.download', $certificate))->assertOk()->assertDownload('certificate-'.$certificate->id.'-v1.pdf');
    $this->get('/storage/'.CertificateVersion::sole()->file_path)->assertForbidden();
});

test('missing files versions and unsafe stored paths return not found', function () {
    $this->actingAs($this->admin)->post(route('admin.certificates.store'), array_merge($this->input, ['document' => certificatePdf()]));
    $certificate = Certificate::sole();
    $version = CertificateVersion::sole();
    Storage::disk('certificates')->delete($version->file_path);
    $this->get(route('admin.certificates.preview', $certificate))->assertNotFound();
    $this->get(route('admin.certificates.download', $certificate))->assertNotFound();
    $version->update(['file_path' => '../outside.pdf']);
    $this->get(route('admin.certificates.preview', $certificate))->assertNotFound();
    $version->delete();
    $this->get(route('admin.certificates.preview', $certificate))->assertNotFound();
    $this->get(route('admin.certificates.show', $certificate))->assertOk()->assertSee('No initial document version');
});

test('certificate pages and documents require an active administrator', function (string $actor) {
    $certificate = Certificate::create(['vessel_id' => $this->vessel->id, 'certificate_type_id' => $this->type->id]);
    if ($actor !== 'guest') {
        $this->actingAs(User::factory()->create(['role' => $actor === 'client' ? 'client' : 'admin', 'is_active' => $actor !== 'inactive']));
    }
    foreach ([['GET', '/admin/certificates'], ['GET', '/admin/certificates/create'], ['POST', '/admin/certificates'], ['GET', '/admin/certificates/'.$certificate->id], ['GET', '/admin/certificates/'.$certificate->id.'/preview'], ['GET', '/admin/certificates/'.$certificate->id.'/download']] as [$method, $uri]) {
        $response = $this->call($method, $uri);
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
    }
    $this->assertDatabaseCount('certificates', 1);
    $this->assertDatabaseCount('certificate_versions', 0);
})->with(['guest', 'client', 'inactive']);

test('certificates have empty states searchable relationships and pagination', function () {
    $this->actingAs($this->admin)->get(route('admin.certificates.index'))->assertOk()->assertSee('No certificates registered yet');
    for ($index = 0; $index < 16; $index++) {
        Certificate::create(['vessel_id' => $this->vessel->id, 'certificate_type_id' => $this->type->id]);
    }
    $this->get(route('admin.certificates.index', ['search' => 'Pioneer']))->assertOk()->assertSee('Safety Equipment')
        ->assertViewHas('certificates', fn ($rows) => $rows->total() === 16 && $rows->count() === 15 && str_contains($rows->nextPageUrl(), 'search=Pioneer'));
    $this->get(route('admin.certificates.index', ['search' => 'Equipment', 'page' => 2]))
        ->assertViewHas('certificates', fn ($rows) => $rows->total() === 16 && $rows->count() === 1);
    $this->get(route('admin.certificates.index', ['search' => 'missing']))->assertSee('No matching certificates');
});

test('failed database writes roll back records and remove the stored PDF', function (string $model) {
    $model::creating(function () {
        throw new RuntimeException('Simulated database failure');
    });
    $this->actingAs($this->admin)->from(route('admin.certificates.create'))
        ->post(route('admin.certificates.store'), array_merge($this->input, ['document' => certificatePdf()]))
        ->assertRedirect(route('admin.certificates.create'))->assertSessionHasErrors('document');
    $this->assertDatabaseCount('certificates', 0);
    $this->assertDatabaseCount('certificate_versions', 0);
    expect(Storage::disk('certificates')->allFiles())->toBe([]);
})->with([Certificate::class, CertificateVersion::class]);

test('storage failure cleans partial files and creates no records', function () {
    $disk = Storage::disk('certificates');
    $failingDisk = Mockery::mock(FilesystemAdapter::class);
    $failingDisk->shouldReceive('putFileAs')->once()->andReturnUsing(function ($directory, $document, $filename) use ($disk) {
        $disk->put($directory.'/'.$filename, 'partial');
        throw new RuntimeException('Simulated storage failure');
    });
    $failingDisk->shouldReceive('delete')->once()->andReturnUsing(fn ($path) => $disk->delete($path));
    Storage::shouldReceive('disk')->with('certificates')->andReturn($failingDisk);
    $this->actingAs($this->admin)->post(route('admin.certificates.store'), array_merge($this->input, ['document' => certificatePdf()]))->assertSessionHasErrors('document');
    $this->assertDatabaseCount('certificates', 0);
    $this->assertDatabaseCount('certificate_versions', 0);
    expect($disk->allFiles())->toBe([]);
});
