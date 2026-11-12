<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Support\CertificateDocument;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateDocumentController extends Controller
{
    public function preview(Certificate $certificate): StreamedResponse
    {
        return app(CertificateDocument::class)->respond($certificate, 'inline');
    }

    public function download(Certificate $certificate): StreamedResponse
    {
        return app(CertificateDocument::class)->respond($certificate, 'attachment');
    }

    public function previewVersion(Certificate $certificate, int $versionNumber): StreamedResponse
    {
        return app(CertificateDocument::class)->respond($certificate, 'inline', $versionNumber);
    }

    public function downloadVersion(Certificate $certificate, int $versionNumber): StreamedResponse
    {
        return app(CertificateDocument::class)->respond($certificate, 'attachment', $versionNumber);
    }
}
