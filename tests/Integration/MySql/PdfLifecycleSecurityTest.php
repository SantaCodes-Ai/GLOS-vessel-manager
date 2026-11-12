<?php

namespace Tests\Integration\MySql;

use App\Models\Certificate;
use App\Models\CertificateVersion;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\ValidPdf;
use Tests\Integration\MySql\Support\FleetFixtures;
use Tests\MySqlTestCase;

#[Group('mysql-fleet')]
final class PdfLifecycleSecurityTest extends MySqlTestCase
{
    use FleetFixtures;

    #[DataProvider('invalidPdfs')]
    public function test_real_upload_rules_reject_invalid_pdfs_for_registration_and_renewal(string $kind, bool $renewal): void
    {
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $type = $this->type();
        $certificate = $this->record($vessel, $type, []);
        $before = $certificate->getAttributes();
        $versionBefore = $certificate->versions()->sole()->getAttributes();
        $files = $this->privateFiles();
        $bytes = match ($kind) {
            'non-PDF' => '<?php echo "not a document";',
            'header-only' => "%PDF-1.4\n",
            'fake-structure' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n",
            'truncated' => substr(ValidPdf::contents(), 0, -75)."\n%%EOF\n",
            'bad-xref' => preg_replace('/startxref\n[0-9]+/', "startxref\n1", ValidPdf::contents()),
            'oversized' => ValidPdf::contents().str_repeat(' ', 10240 * 1024),
            default => ValidPdf::contents(),
        };
        $name = $kind === 'wrong-extension' ? 'certificate.php' : 'certificate.pdf';
        $url = $renewal ? route('admin.certificates.renew.store', $certificate) : route('admin.certificates.store');
        $upload = $this->pdf($name, $bytes);
        $this->actingAs($admin)->post($url, $this->uploadInput($vessel, $type, ['document' => $upload]))->assertSessionHasErrors('document');
        $this->assertSame($before, $certificate->fresh()->getAttributes());
        $this->assertSame($versionBefore, $certificate->versions()->sole()->getAttributes());
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseCount('certificate_versions', 1);
        $this->assertSame($files, $this->privateFiles());
        $this->assertStringNotContainsString($upload->getRealPath(), session('errors')->first('document'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public static function invalidPdfs(): array
    {
        $cases = [];
        foreach (['non-PDF', 'header-only', 'fake-structure', 'truncated', 'bad-xref', 'oversized', 'wrong-extension'] as $kind) {
            $cases[] = [$kind, false];
            $cases[] = [$kind, true];
        }

        return $cases;
    }

    #[DataProvider('operations')]
    public function test_database_failure_after_version_insert_rolls_back_rows_and_removes_only_the_new_pdf(bool $renewal): void
    {
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $type = $this->type();
        $certificate = $this->record($vessel, $type, []);
        $before = $certificate->getAttributes();
        $versionBefore = $certificate->versions()->sole()->getAttributes();
        $files = $this->privateFiles();
        $driverCode = null;
        $dispatcher = CertificateVersion::getEventDispatcher();
        CertificateVersion::created(function ($version) use (&$driverCode) {
            try {
                // A real duplicate of the just-inserted version fails after persistence.
                DB::table('certificate_versions')->insert([
                    'certificate_id' => $version->certificate_id, 'version_number' => $version->version_number,
                    'expiry_type' => 'permanent', 'file_path' => 'unused-fault.pdf', 'original_filename' => 'fault.pdf',
                ]);
            } catch (QueryException $exception) {
                $driverCode = (int) $exception->errorInfo[1];
                throw $exception;
            }
        });
        try {
            $url = $renewal ? route('admin.certificates.renew.store', $certificate) : route('admin.certificates.store');
            $this->actingAs($admin)->post($url, $this->uploadInput($vessel, $type))->assertSessionHasErrors('document');
        } finally {
            $dispatcher->forget('eloquent.created: '.CertificateVersion::class);
        }
        $this->assertSame(1062, $driverCode);
        $this->assertSame($before, $certificate->fresh()->getAttributes());
        $this->assertSame($versionBefore, $certificate->versions()->sole()->getAttributes());
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseCount('certificate_versions', 1);
        $this->assertSame($files, $this->privateFiles());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function operations(): array
    {
        return [[false], [true]];
    }

    public function test_a_late_renewal_parent_update_failure_restores_number_version_and_files(): void
    {
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $type = $this->type();
        $certificate = $this->record($vessel, $type, []);
        $before = $certificate->getAttributes();
        $old = $certificate->versions()->sole()->getAttributes();
        $files = $this->privateFiles();
        $driverCode = null;
        $dispatcher = Certificate::getEventDispatcher();
        Certificate::updated(function ($updated) use ($certificate, &$driverCode) {
            if ($updated->id !== $certificate->id) {
                return;
            }
            try {
                DB::table('certificates')->where('id', $updated->id)->update(['vessel_id' => 0]);
            } catch (QueryException $exception) {
                $driverCode = (int) $exception->errorInfo[1];
                throw $exception;
            }
        });
        try {
            $this->actingAs($admin)->post(route('admin.certificates.renew.store', $certificate), $this->uploadInput($vessel, $type))->assertSessionHasErrors('document');
        } finally {
            $dispatcher->forget('eloquent.updated: '.Certificate::class);
        }
        $this->assertSame(1452, $driverCode);
        $this->assertSame($before, $certificate->fresh()->getAttributes());
        $this->assertSame($old, $certificate->versions()->sole()->getAttributes());
        $this->assertSame($files, $this->privateFiles());
    }

    #[DataProvider('operations')]
    public function test_partial_storage_failures_are_compensated_without_losing_history(bool $renewal): void
    {
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $type = $this->type();
        $certificate = $this->record($vessel, $type, []);
        $before = $certificate->getAttributes();
        $old = $certificate->versions()->sole()->getAttributes();
        $disk = Storage::disk('certificates');
        $files = $disk->allFiles();
        sort($files);
        $partial = null;
        $failing = Mockery::mock(FilesystemAdapter::class);
        $failing->shouldReceive('putFileAs')->once()->andReturnUsing(function ($directory, $document, $filename) use ($disk, &$partial) {
            $partial = $directory.'/'.$filename;
            $disk->put($partial, 'partial test-owned file');
            throw new \RuntimeException('Simulated isolated storage write failure');
        });
        $failing->shouldReceive('delete')->once()->andReturnUsing(fn ($path) => $disk->delete($path));
        Storage::shouldReceive('disk')->with('certificates')->andReturn($failing);
        $url = $renewal ? route('admin.certificates.renew.store', $certificate) : route('admin.certificates.store');
        $this->actingAs($admin)->post($url, $this->uploadInput($vessel, $type))->assertSessionHasErrors('document');
        $after = $disk->allFiles();
        sort($after);
        $this->assertNotNull($partial);
        $this->assertFalse($disk->exists($partial));
        $this->assertSame($files, $after);
        $this->assertSame($before, $certificate->fresh()->getAttributes());
        $this->assertSame($old, $certificate->versions()->sole()->getAttributes());
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseCount('certificate_versions', 1);
    }

    public function test_validator_unavailability_fails_closed_without_new_versions_or_files(): void
    {
        config(['pdf.qpdf_binary' => '/nonexistent/qpdf']);
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $type = $this->type();
        $certificate = $this->record($vessel, $type, []);
        $files = $this->privateFiles();
        $this->actingAs($admin);
        foreach ([route('admin.certificates.store'), route('admin.certificates.renew.store', $certificate)] as $url) {
            $this->post($url, $this->uploadInput($vessel, $type))->assertSessionHasErrors('document');
        }
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseCount('certificate_versions', 1);
        $this->assertSame($files, $this->privateFiles());
    }
}
