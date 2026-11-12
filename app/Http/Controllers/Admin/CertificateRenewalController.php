<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RenewCertificateRequest;
use App\Models\Certificate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class CertificateRenewalController extends Controller
{
    public function create(Certificate $certificate): View
    {
        $certificate->load(['vessel:id,vessel_name', 'certificateType:id,name', 'currentVersion']);

        return view('admin.certificates.renew', compact('certificate'));
    }

    public function store(RenewCertificateRequest $request, Certificate $certificate): RedirectResponse
    {
        $data = $request->validated();
        $document = $request->file('document');
        $directory = 'vessels/'.$certificate->vessel_id;
        $filename = Str::uuid().'.pdf';
        $path = $directory.'/'.$filename;
        $disk = Storage::disk('certificates');

        try {
            if ($disk->putFileAs($directory, $document, $filename, ['visibility' => 'private']) === false) {
                throw new RuntimeException('Private document storage failed.');
            }

            DB::transaction(function () use ($certificate, $request, $data, $document, $path) {
                // All renewals lock the same parent first, including certificates with no versions.
                $locked = Certificate::whereKey($certificate->id)->lockForUpdate()->firstOrFail();
                // A locking read avoids a stale MySQL repeatable-read snapshot after waiting.
                $previous = $locked->versions()->orderByDesc('version_number')->lockForUpdate()->first();
                if (! $previous || $previous->version_number >= 4294967295) {
                    throw new RuntimeException('The certificate cannot be renewed.');
                }
                $locked->versions()->create([
                    'version_number' => $previous->version_number + 1,
                    'certificate_number' => $data['certificate_number'] ?? null,
                    'issuance_date' => $data['issuance_date'] ?? null,
                    'expiry_type' => $data['expiry_type'],
                    'expiry_date' => $data['expiry_type'] === 'fixed' ? $data['expiry_date'] : null,
                    'file_path' => $path,
                    'original_filename' => mb_substr(basename(str_replace('\\', '/', $document->getClientOriginalName())), 0, 255),
                    'uploaded_by' => $request->user()->id,
                    'uploaded_at' => now(),
                ]);
                $locked->update(['certificate_number' => $data['certificate_number'] ?? null]);
            }, 3);
        } catch (Throwable $exception) {
            try {
                if (! $disk->delete($path)) {
                    throw new RuntimeException('Document cleanup failed.');
                }
            } catch (Throwable $cleanupException) {
                Log::error('Certificate renewal cleanup failed.', ['exception_type' => $cleanupException::class]);
            }
            Log::error('Certificate renewal failed.', ['exception_type' => $exception::class]);

            return back()->withInput($request->except(['document', '_token']))
                ->withErrors(['document' => 'The renewal could not be saved. Please try again and select the PDF again.']);
        }

        return to_route('admin.certificates.show', $certificate)->with('success', 'Certificate renewed successfully.');
    }
}
