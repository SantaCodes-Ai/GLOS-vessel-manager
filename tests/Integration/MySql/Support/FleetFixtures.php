<?php

namespace Tests\Integration\MySql\Support;

use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\CertificateVersion;
use App\Models\Vessel;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\ValidPdf;

trait FleetFixtures
{
    use AccountFixtures;

    protected function freezeCalendar(): void
    {
        config(['app.timezone' => 'Europe/London']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00:00', 'Europe/London'));
    }

    protected function type(string $name = 'MySQL Marine Safety'): CertificateType
    {
        return CertificateType::create(['name' => $name, 'category' => 'Statutory'])->refresh();
    }

    protected function pdf(string $name = 'certificate.pdf', ?string $bytes = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes ?? ValidPdf::contents());
    }

    protected function uploadInput(Vessel $vessel, CertificateType $type, array $overrides = []): array
    {
        return array_replace(['vessel_id' => $vessel->id, 'certificate_type_id' => $type->id,
            'certificate_number' => 'MYSQL-NEW', 'issuance_date' => '2026-01-01', 'expiry_type' => 'fixed',
            'expiry_date' => '2027-01-01', 'remarks' => 'Fictional integration certificate', 'document' => $this->pdf()], $overrides);
    }

    protected function record(Vessel $vessel, CertificateType $type, ?array $version = null): Certificate
    {
        $certificate = Certificate::create(['vessel_id' => $vessel->id, 'certificate_type_id' => $type->id,
            'certificate_number' => 'ORIGINAL', 'remarks' => 'Preserve metadata'])->refresh();
        if ($version !== null) {
            $this->version($certificate, 1, $version);
        }

        return $certificate;
    }

    protected function version(Certificate $certificate, int $number, array $overrides = []): CertificateVersion
    {
        $path = 'vessels/'.$certificate->vessel_id.'/'.Str::uuid().'.pdf';
        Storage::disk('certificates')->put($path, ValidPdf::contents());

        return $certificate->versions()->create(array_replace([
            'version_number' => $number, 'certificate_number' => 'REF-'.$number, 'issuance_date' => '2019-01-01',
            'expiry_type' => 'permanent', 'expiry_date' => null, 'file_path' => $path,
            'original_filename' => 'fictional-certificate.pdf', 'uploaded_at' => now(),
        ], $overrides))->refresh();
    }

    protected function privateFiles(): array
    {
        $files = Storage::disk('certificates')->allFiles();
        sort($files);

        return $files;
    }

    protected function cardLinks(string $html): array
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $links = [];
        foreach ((new DOMXPath($document))->query('//a[@class="summary-card-link"]') as $link) {
            $links[$link->getAttribute('aria-label')] = $link->getAttribute('href');
        }

        return $links;
    }
}
