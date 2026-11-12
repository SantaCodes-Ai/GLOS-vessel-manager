<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\CertificateDocument;
use App\Support\ClientRecords;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private ClientRecords $records, private CertificateDocument $documents) {}

    public function preview(Request $request, string $certificate): StreamedResponse
    {
        return $this->respond($request, $certificate, 'inline');
    }

    public function download(Request $request, string $certificate): StreamedResponse
    {
        return $this->respond($request, $certificate, 'attachment');
    }

    public function previewVersion(Request $request, string $certificate, int $versionNumber): StreamedResponse
    {
        return $this->respond($request, $certificate, 'inline', $versionNumber);
    }

    public function downloadVersion(Request $request, string $certificate, int $versionNumber): StreamedResponse
    {
        return $this->respond($request, $certificate, 'attachment', $versionNumber);
    }

    private function respond(Request $request, string $id, string $disposition, ?int $versionNumber = null): StreamedResponse
    {
        // Fresh assignment query on every request, including direct and historical PDF URLs.
        $certificate = $this->records->certificates($request->user())->whereKey($id)->firstOrFail();

        return $this->documents->respond($certificate, $disposition, $versionNumber);
    }
}
