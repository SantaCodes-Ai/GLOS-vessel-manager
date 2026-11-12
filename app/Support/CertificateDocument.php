<?php

namespace App\Support;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateDocument
{
    // Callers must first resolve a certificate authorised for the current user.
    public function respond(Certificate $certificate, string $disposition, ?int $versionNumber = null): StreamedResponse
    {
        $version = $versionNumber === null
            ? $certificate->currentVersion()->firstOrFail()
            : $certificate->versions()->where('version_number', $versionNumber)->firstOrFail();
        // Only application-generated PDF paths for this vessel are eligible.
        abort_unless(preg_match('#\Avessels/'.(int) $certificate->vessel_id.'/[a-f0-9-]{36}\.pdf\z#', $version->file_path), 404);
        $disk = Storage::disk('certificates');
        abort_unless($disk->exists($version->file_path), 404, 'Certificate document not found.');

        return $disk->response($version->file_path, 'certificate-'.$certificate->id.'-v'.$version->version_number.'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Referrer-Policy' => 'no-referrer',
        ], $disposition);
    }
}
