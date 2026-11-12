<?php

use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\CertificateVersion;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\ValidPdf;

function renewalPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('renewal.pdf', ValidPdf::contents());
}

beforeEach(function () {
    Storage::fake('certificates');
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $vessel = Vessel::create(['vessel_name' => 'Ocean Pioneer', 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
    $type = CertificateType::create(['name' => 'Safety', 'category' => 'Statutory']);
    $this->certificate = Certificate::create(['vessel_id' => $vessel->id, 'certificate_type_id' => $type->id, 'certificate_number' => 'OLD']);
    $path = 'vessels/'.$vessel->id.'/11111111-1111-4111-8111-111111111111.pdf';
    Storage::disk('certificates')->put($path, 'original PDF bytes');
    $this->original = $this->certificate->versions()->create([
        'version_number' => 1, 'certificate_number' => 'OLD', 'expiry_type' => 'fixed',
        'issuance_date' => '2025-01-01', 'expiry_date' => '2025-12-31',
        'file_path' => $path, 'original_filename' => 'original.pdf', 'uploaded_by' => $this->admin->id, 'uploaded_at' => now(),
    ])->refresh();
    $this->input = ['certificate_number' => 'NEW', 'issuance_date' => '2026-01-01', 'expiry_type' => 'fixed', 'expiry_date' => '2027-12-31'];
});

test('renewals append versions without changing associations history or original PDFs', function () {
    $before = $this->original->getAttributes();
    $this->actingAs($this->admin)->get(route('admin.certificates.renew', $this->certificate))->assertOk()->assertSee('Renew Certificate');
    for ($number = 2; $number <= 3; $number++) {
        $this->post(route('admin.certificates.renew.store', $this->certificate), array_merge($this->input, [
            'document' => renewalPdf(), 'vessel_id' => 999, 'certificate_type_id' => 999, 'version_number' => 999, 'uploaded_by' => 999,
        ]))->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect(route('admin.certificates.show', $this->certificate));
        expect($this->certificate->fresh()->currentVersion->version_number)->toBe($number);
    }
    expect($this->original->fresh()->getAttributes())->toBe($before)
        ->and($this->certificate->fresh()->vessel_id)->toBe($this->certificate->vessel_id)
        ->and($this->certificate->fresh()->certificate_type_id)->toBe($this->certificate->certificate_type_id)
        ->and($this->certificate->fresh()->currentVersion->uploaded_by)->toBe($this->admin->id)
        ->and($this->certificate->fresh()->currentVersion->certificate_number)->toBe('NEW');
    expect(Storage::disk('certificates')->get($this->original->file_path))->toBe('original PDF bytes');
    expect(Storage::disk('certificates')->allFiles())->toHaveCount(3);
    $this->get(route('admin.certificates.show', $this->certificate))->assertOk()->assertSee('Version history')->assertSee('Historical')->assertSee('Current')->assertSee('NEW')->assertDontSee($this->original->file_path);
});

test('current version and renewal numbering use highest version number rather than creation time', function () {
    $attributes = $this->original->getAttributes();
    unset($attributes['id']);
    $attributes['version_number'] = 7;
    $attributes['created_at'] = '2020-01-01';
    $this->certificate->versions()->create($attributes);
    $attributes['version_number'] = 2;
    $attributes['created_at'] = '2026-01-01';
    $this->certificate->versions()->create($attributes);
    expect($this->certificate->fresh()->currentVersion->version_number)->toBe(7);
    $this->actingAs($this->admin)->post(route('admin.certificates.renew.store', $this->certificate), array_merge($this->input, ['document' => renewalPdf()]))->assertSessionHasNoErrors();
    expect($this->certificate->fresh()->currentVersion->version_number)->toBe(8);
});

test('renewal locks the parent then reads the latest version under lock inside a transaction', function () {
    $connection = DB::connection();
    $previousGrammar = $connection->getQueryGrammar();
    $grammar = new class($connection) extends SQLiteGrammar
    {
        public array $lockedTables = [];

        public function compileSelect(Builder $query)
        {
            if ($query->lock === true) {
                $this->lockedTables[] = $query->from;
            }

            return parent::compileSelect($query);
        }
    };
    $connection->setQueryGrammar($grammar);
    $baseline = $connection->transactionLevel();
    CertificateVersion::creating(function () use ($baseline) {
        expect(DB::transactionLevel())->toBeGreaterThan($baseline);
    });
    try {
        $this->actingAs($this->admin)->post(route('admin.certificates.renew.store', $this->certificate), array_merge($this->input, ['document' => renewalPdf()]))->assertSessionHasNoErrors();
        expect($grammar->lockedTables)->toBe(['certificates', 'certificate_versions']);
    } finally {
        $connection->setQueryGrammar($previousGrammar);
    }
});

test('the database uniqueness constraint rejects duplicate version allocation', function () {
    $attributes = $this->original->getAttributes();
    unset($attributes['id']);
    expect(fn () => $this->certificate->versions()->create($attributes))->toThrow(UniqueConstraintViolationException::class);
    $this->assertDatabaseCount('certificate_versions', 1);
});

test('renewal validation reuses date and PDF rules', function (array $overrides, string $field) {
    $this->actingAs($this->admin)->post(route('admin.certificates.renew.store', $this->certificate), array_replace($this->input, ['document' => renewalPdf()], $overrides))->assertSessionHasErrors($field);
    $this->assertDatabaseCount('certificate_versions', 1);
    expect(Storage::disk('certificates')->allFiles())->toHaveCount(1);
})->with([
    'fixed date required' => [['expiry_date' => null], 'expiry_date'],
    'invalid type' => [['expiry_type' => 'unknown'], 'expiry_type'],
    'invalid issuance' => [['issuance_date' => '2026-02-30'], 'issuance_date'],
    'invalid expiry' => [['expiry_date' => 'invalid'], 'expiry_date'],
    'expiry before issuance' => [['expiry_date' => '2025-01-01'], 'expiry_date'],
    'missing PDF' => [['document' => null], 'document'],
]);

test('renewal rejects disguised and oversized PDFs', function (string $kind) {
    $document = $kind === 'disguised'
        ? UploadedFile::fake()->createWithContent('fake.pdf', '<?php not pdf')
        : UploadedFile::fake()->createWithContent('large.pdf', '%PDF-1.4'.str_repeat(' ', 10240 * 1024));
    $this->actingAs($this->admin)->post(route('admin.certificates.renew.store', $this->certificate), array_merge($this->input, ['document' => $document]))->assertSessionHasErrors('document');
    $this->assertDatabaseCount('certificate_versions', 1);
})->with(['disguised', 'oversized']);

test('permanent renewals clear expiry and allow optional numbers and issuance', function () {
    $this->actingAs($this->admin)->post(route('admin.certificates.renew.store', $this->certificate), [
        'expiry_type' => 'permanent', 'expiry_date' => '2020-01-01', 'document' => renewalPdf(),
    ])->assertSessionHasNoErrors();
    $version = $this->certificate->fresh()->currentVersion;
    expect($version->expiry_date)->toBeNull()->and($version->issuance_date)->toBeNull()->and($version->certificate_number)->toBeNull();
});

test('failed renewal transactions remove only the new file and preserve prior records', function (string $stage) {
    $before = $this->original->getAttributes();
    $model = $stage === 'version' ? CertificateVersion::class : Certificate::class;
    $event = $stage === 'version' ? 'creating' : 'updating';
    $model::$event(function () {
        throw new RuntimeException('Simulated database write failure');
    });
    $this->actingAs($this->admin)->post(route('admin.certificates.renew.store', $this->certificate), array_merge($this->input, ['document' => renewalPdf()]))->assertSessionHasErrors('document');
    $this->assertDatabaseCount('certificate_versions', 1);
    expect($this->original->fresh()->getAttributes())->toBe($before)
        ->and($this->certificate->fresh()->certificate_number)->toBe('OLD')
        ->and(Storage::disk('certificates')->allFiles())->toHaveCount(1);
})->with(['version', 'parent']);

test('historical documents remain accessible while default document routes serve the current PDF', function () {
    $this->actingAs($this->admin)->post(route('admin.certificates.renew.store', $this->certificate), array_merge($this->input, ['document' => renewalPdf()]))->assertSessionHasNoErrors();
    $this->get(route('admin.certificates.versions.preview', [$this->certificate, 1]))->assertOk()->assertStreamedContent('original PDF bytes');
    $this->get(route('admin.certificates.versions.download', [$this->certificate, 1]))->assertOk()->assertDownload('certificate-'.$this->certificate->id.'-v1.pdf');
    $current = $this->get(route('admin.certificates.download', $this->certificate))->assertOk()->assertDownload('certificate-'.$this->certificate->id.'-v2.pdf');
    expect($current->streamedContent())->toStartWith('%PDF-');
    $this->get(route('admin.certificates.versions.preview', [$this->certificate, 99]))->assertNotFound();
    $other = Certificate::create(['vessel_id' => $this->certificate->vessel_id, 'certificate_type_id' => $this->certificate->certificate_type_id]);
    $this->get(route('admin.certificates.versions.preview', [$other, 1]))->assertNotFound();
});

test('renewal and historical PDF routes reject guests clients and inactive administrators', function (string $actor) {
    if ($actor !== 'guest') {
        $this->actingAs(User::factory()->create(['role' => $actor === 'client' ? 'client' : 'admin', 'is_active' => $actor !== 'inactive']));
    }
    foreach ([['GET', route('admin.certificates.renew', $this->certificate)], ['POST', route('admin.certificates.renew.store', $this->certificate)], ['GET', route('admin.certificates.versions.preview', [$this->certificate, 1])], ['GET', route('admin.certificates.versions.download', [$this->certificate, 1])]] as [$method, $uri]) {
        $response = $this->call($method, $uri);
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
    }
    $this->assertDatabaseCount('certificate_versions', 1);
})->with(['guest', 'client', 'inactive']);
