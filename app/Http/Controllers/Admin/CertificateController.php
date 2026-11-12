<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCertificateRequest;
use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\Vessel;
use App\Support\CertificateDashboard;
use App\Support\CertificateExpiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class CertificateController extends Controller
{
    public function index(Request $request, CertificateDashboard $dashboard): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(CertificateDashboard::STATUSES)],
        ]);
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? '';
        $query = Certificate::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereHas('vessel', fn ($query) => $query->whereLike('vessel_name', '%'.$search.'%'))
                ->orWhereHas('certificateType', fn ($query) => $query->whereLike('name', '%'.$search.'%'))));
        if ($status !== '') {
            $dashboard->filterByStatus($query, $status);
        }
        $certificates = $query->with(['vessel:id,vessel_name', 'certificateType:id,name', 'currentVersion'])
            ->latest()->orderByDesc('id')->paginate(15)->withQueryString();
        $statusOptions = array_combine(CertificateDashboard::STATUSES, array_map(
            fn ($value) => (new CertificateExpiry($value))->label(), CertificateDashboard::STATUSES
        ));

        return view('admin.certificates.index', compact('certificates', 'search', 'status', 'statusOptions'));
    }

    public function create(): View
    {
        return view('admin.certificates.create', [
            'vessels' => Vessel::orderBy('vessel_name')->get(['id', 'vessel_name', 'imo_number']),
            'certificateTypes' => CertificateType::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreCertificateRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $document = $request->file('document');
        $directory = 'vessels/'.$data['vessel_id'];
        $filename = Str::uuid().'.pdf';
        $path = $directory.'/'.$filename;
        $disk = Storage::disk('certificates');

        try {
            if ($disk->putFileAs($directory, $document, $filename, ['visibility' => 'private']) === false) {
                throw new RuntimeException('Private document storage failed.');
            }

            $certificate = DB::transaction(function () use ($data, $document, $path, $request) {
                $certificate = Certificate::create([
                    'vessel_id' => $data['vessel_id'],
                    'certificate_type_id' => $data['certificate_type_id'],
                    'certificate_number' => $data['certificate_number'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
                $certificate->versions()->create([
                    'version_number' => 1,
                    'certificate_number' => $data['certificate_number'] ?? null,
                    'issuance_date' => $data['issuance_date'] ?? null,
                    'expiry_type' => $data['expiry_type'],
                    'expiry_date' => $data['expiry_type'] === 'fixed' ? $data['expiry_date'] : null,
                    'file_path' => $path,
                    'original_filename' => mb_substr(basename(str_replace('\\', '/', $document->getClientOriginalName())), 0, 255),
                    'uploaded_by' => $request->user()->id,
                    'uploaded_at' => now(),
                ]);

                return $certificate;
            });
        } catch (Throwable $exception) {
            // Filesystem writes do not participate in the database transaction.
            try {
                if (! $disk->delete($path)) {
                    throw new RuntimeException('Document cleanup failed.');
                }
            } catch (Throwable $cleanupException) {
                Log::error('Certificate document cleanup failed.', ['exception_type' => $cleanupException::class]);
            }
            Log::error('Certificate registration failed.', ['exception_type' => $exception::class]);

            return back()->withInput($request->except(['document', '_token']))
                ->withErrors(['document' => 'The certificate could not be saved. Please try again and select the PDF again.']);
        }

        return to_route('admin.certificates.show', $certificate)->with('success', 'Certificate registered successfully.');
    }

    public function show(Certificate $certificate): View
    {
        $certificate->load(['vessel:id,vessel_name', 'certificateType:id,name', 'currentVersion']);
        $version = $certificate->currentVersion;
        $versions = $certificate->versions()->orderByDesc('version_number')->paginate(15);

        return view('admin.certificates.show', compact('certificate', 'version', 'versions'));
    }
}
