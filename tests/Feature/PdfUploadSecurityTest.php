<?php

use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\CertificateVersion;
use App\Models\User;
use App\Models\Vessel;
use App\Support\PdfStructure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Fixtures\ValidPdf;

beforeEach(function () {
    Storage::fake('certificates');
    Storage::fake('public');
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->vessel = Vessel::create(['vessel_name' => 'Security test vessel', 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
    $this->type = CertificateType::create(['name' => 'Security test certificate', 'category' => 'Statutory']);
    $this->certificate = Certificate::create(['vessel_id' => $this->vessel->id, 'certificate_type_id' => $this->type->id]);
    $this->oldPath = 'vessels/'.$this->vessel->id.'/11111111-1111-4111-8111-111111111111.pdf';
    Storage::disk('certificates')->put($this->oldPath, ValidPdf::contents());
    $this->oldVersion = $this->certificate->versions()->create([
        'version_number' => 1, 'expiry_type' => 'permanent', 'file_path' => $this->oldPath, 'original_filename' => 'original.pdf',
    ]);
    $this->input = ['vessel_id' => $this->vessel->id, 'certificate_type_id' => $this->type->id, 'expiry_type' => 'permanent'];
});

test('real structured PDFs pass registration and renewal without rewriting bytes or history', function (bool $renewing) {
    $bytes = ValidPdf::contents();
    $url = $renewing ? route('admin.certificates.renew.store', $this->certificate) : route('admin.certificates.store');
    $this->actingAs($this->admin)->post($url, array_merge($this->input, ['document' => UploadedFile::fake()->createWithContent('certificate.PDF', $bytes)]))
        ->assertSessionHasNoErrors();
    $version = CertificateVersion::latest('id')->first();
    expect($version->version_number)->toBe($renewing ? 2 : 1)
        ->and(Storage::disk('certificates')->get($version->file_path))->toBe($bytes)
        ->and(Storage::disk('certificates')->get($this->oldPath))->toBe($bytes)
        ->and(Storage::disk('certificates')->getVisibility($version->file_path))->toBe('private');
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([false, true]);

test('invalid PDF submissions never create records versions or orphan files', function (string $kind, bool $renewing) {
    $valid = ValidPdf::contents();
    $name = $kind === 'wrong extension' ? 'certificate.php' : 'certificate.pdf';
    $bytes = match ($kind) {
        'non PDF' => '<?php echo "not a PDF";',
        'header only' => "%PDF-1.4\n",
        'fake footer' => "%PDF-1.4\nnot a PDF structure\n%%EOF\n",
        'old synthetic renewal' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
        'truncated' => substr($valid, 0, -70)."\n%%EOF\n",
        'missing EOF' => substr($valid, 0, -6),
        'broken cross reference' => preg_replace('/startxref\n[0-9]+/', "startxref\n1", $valid),
        'broken stream' => preg_replace('/\/Length [0-9]+/', '/Length 999999', $valid),
        'broken page tree' => str_replace('/Pages 2 0 R', '/Pages 99 0 R', $valid),
        'appended executable' => $valid.'<?php echo "trailing data";',
        'oversized' => $valid.str_repeat(' ', 10240 * 1024),
        default => $valid,
    };
    $url = $renewing ? route('admin.certificates.renew.store', $this->certificate) : route('admin.certificates.store');
    $upload = UploadedFile::fake()->createWithContent($name, $bytes);
    $this->actingAs($this->admin)->post($url, array_merge($this->input, ['document' => $upload]))->assertSessionHasErrors('document');
    $this->assertDatabaseCount('certificates', 1);
    $this->assertDatabaseCount('certificate_versions', 1);
    expect(Storage::disk('certificates')->allFiles())->toBe([$this->oldPath])
        ->and(Storage::disk('certificates')->get($this->oldPath))->toBe(ValidPdf::contents())
        ->and(Storage::disk('public')->allFiles())->toBe([]);
    expect(session('errors')->first('document'))->not->toContain($upload->getRealPath(), 'qpdf:');
})->with([
    'non PDF', 'header only', 'fake footer', 'old synthetic renewal', 'truncated', 'missing EOF',
    'broken cross reference', 'broken stream', 'broken page tree', 'appended executable', 'oversized', 'wrong extension',
])->with([false, true]);

test('compressed object streams produced by qpdf remain valid uploads', function () {
    $binary = (new ExecutableFinder)->find('qpdf', null, ['/opt/homebrew/bin']);
    expect($binary)->not->toBeNull();
    $input = tmpfile();
    $output = tmpfile();
    try {
        fwrite($input, ValidPdf::contents());
        $source = stream_get_meta_data($input)['uri'];
        $target = stream_get_meta_data($output)['uri'];
        $process = new Process([$binary, '--object-streams=generate', $source, $target]);
        $process->setTimeout(10)->mustRun();
        $bytes = file_get_contents($target);
        expect($bytes)->toContain('/ObjStm');
        $this->actingAs($this->admin)->post(route('admin.certificates.store'), array_merge($this->input, [
            'document' => UploadedFile::fake()->createWithContent('compressed.pdf', $bytes),
        ]))->assertSessionHasNoErrors();
    } finally {
        fclose($input);
        fclose($output);
    }
});

test('missing PDF validator fails closed before registration or renewal storage writes', function (bool $renewing) {
    config(['pdf.qpdf_binary' => '/nonexistent/qpdf']);
    $url = $renewing ? route('admin.certificates.renew.store', $this->certificate) : route('admin.certificates.store');
    $this->actingAs($this->admin)->post($url, array_merge($this->input, [
        'document' => UploadedFile::fake()->createWithContent('valid.pdf', ValidPdf::contents()),
    ]))->assertSessionHasErrors('document');
    $this->assertDatabaseCount('certificates', 1);
    $this->assertDatabaseCount('certificate_versions', 1);
    expect(Storage::disk('certificates')->allFiles())->toBe([$this->oldPath]);
})->with([false, true]);

test('an explicit absolute validator path works', function () {
    $binary = (new ExecutableFinder)->find('qpdf', null, ['/opt/homebrew/bin']);
    config(['pdf.qpdf_binary' => $binary]);
    expect(app(PdfStructure::class)->valid(UploadedFile::fake()->createWithContent('valid.pdf', ValidPdf::contents())))->toBeTrue();
});

test('temporary upload input remains unchanged and validation creates no sibling files', function () {
    $upload = UploadedFile::fake()->createWithContent('valid.pdf', ValidPdf::contents());
    $path = $upload->getRealPath();
    $before = hash_file('sha256', $path);
    expect(app(PdfStructure::class)->valid($upload))->toBeTrue()
        ->and(hash_file('sha256', $path))->toBe($before);
    expect(is_file($path.'.~qpdf-temp#'))->toBeFalse()->and(is_file($path.'.~qpdf-orig'))->toBeFalse();
});

test('validated uploads are cleaned up when database persistence fails', function (bool $renewing) {
    DB::connection()->beforeExecuting(function ($query) {
        if (str_starts_with(strtolower($query), 'insert into "certificate_versions"')) {
            throw new RuntimeException('Simulated database failure');
        }
    });
    $url = $renewing ? route('admin.certificates.renew.store', $this->certificate) : route('admin.certificates.store');
    $this->actingAs($this->admin)->post($url, array_merge($this->input, [
        'document' => UploadedFile::fake()->createWithContent('valid.pdf', ValidPdf::contents()),
    ]))->assertSessionHasErrors('document');
    $this->assertDatabaseCount('certificates', 1);
    $this->assertDatabaseCount('certificate_versions', 1);
    expect(Storage::disk('certificates')->allFiles())->toBe([$this->oldPath])
        ->and(Storage::disk('certificates')->get($this->oldPath))->toBe(ValidPdf::contents());
})->with([false, true]);

test('PDF validation does not weaken upload permissions', function (string $actor) {
    if ($actor !== 'guest') {
        $this->actingAs(User::factory()->create(['role' => $actor === 'staff' ? 'client' : 'admin', 'is_active' => $actor !== 'inactive']));
    }
    foreach ([route('admin.certificates.store'), route('admin.certificates.renew.store', $this->certificate)] as $url) {
        $response = $this->post($url, array_merge($this->input, ['document' => UploadedFile::fake()->createWithContent('valid.pdf', ValidPdf::contents())]));
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
    }
    $this->assertDatabaseCount('certificates', 1);
    $this->assertDatabaseCount('certificate_versions', 1);
    expect(Storage::disk('certificates')->allFiles())->toBe([$this->oldPath]);
})->with(['guest', 'staff', 'inactive']);

test('PDF validation timeout fails closed and logs no parser paths or contents', function () {
    $script = tempnam(sys_get_temp_dir(), 'glos-validator-');
    try {
        file_put_contents($script, "#!/usr/bin/env php\n<?php usleep(12000000);\n");
        chmod($script, 0700);
        config(['pdf.qpdf_binary' => $script]);
        Log::spy();
        $upload = UploadedFile::fake()->createWithContent('valid.pdf', ValidPdf::contents());
        expect(app(PdfStructure::class)->valid($upload))->toBeFalse();
        Log::shouldHaveReceived('error')->once()->with('PDF structure validation could not complete.', [
            'exception_type' => ProcessTimedOutException::class,
        ]);
    } finally {
        unlink($script);
    }
});

test('malformed vessel and certificate type identifiers fail without database exceptions', function (string $field, mixed $value) {
    $this->actingAs($this->admin)->post(route('admin.certificates.store'), array_replace($this->input, [
        $field => $value, 'document' => UploadedFile::fake()->createWithContent('valid.pdf', ValidPdf::contents()),
    ]))->assertSessionHasErrors($field);
    $this->assertDatabaseCount('certificates', 1);
    $this->assertDatabaseCount('certificate_versions', 1);
    expect(Storage::disk('certificates')->allFiles())->toBe([$this->oldPath]);
})->with([
    ['vessel_id', 999999], ['vessel_id', ['nested' => ['invalid']]],
    ['certificate_type_id', 999999], ['certificate_type_id', ['nested' => ['invalid']]],
]);

test('a zero-page PDF is not accepted as a certificate document', function () {
    $binary = (new ExecutableFinder)->find('qpdf', null, ['/opt/homebrew/bin']);
    $output = tmpfile();
    try {
        $target = stream_get_meta_data($output)['uri'];
        (new Process([$binary, '--empty', $target]))->setTimeout(5)->mustRun();
        expect(app(PdfStructure::class)->valid(UploadedFile::fake()->createWithContent('empty.pdf', file_get_contents($target))))->toBeFalse();
    } finally {
        fclose($output);
    }
});
