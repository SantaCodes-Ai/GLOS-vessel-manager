<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\CertificateDashboard;
use App\Support\ClientRecords;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function __construct(private ClientRecords $records) {}

    public function dashboard(Request $request, CertificateDashboard $dashboard): View
    {
        $certificates = $this->records->certificates($request->user());

        return view('client.dashboard', array_merge($dashboard->summary($certificates), [
            'totalVessels' => $this->records->vessels($request->user())->count(),
            'totalCertificates' => (clone $certificates)->count(),
            'vessels' => $this->records->vessels($request->user())->withCount('certificates')
                ->orderBy('vessel_name')->limit(5)->get(),
        ]));
    }

    public function vessels(Request $request): View
    {
        $search = $this->search($request);
        $vessels = $this->records->vessels($request->user())->withCount('certificates')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('vessel_name', '%'.$search.'%')->orWhereLike('imo_number', '%'.$search.'%')))
            ->orderBy('vessel_name')->orderBy('id')->paginate(15)->withQueryString();

        return view('client.vessels', compact('vessels', 'search'));
    }

    public function vessel(Request $request, string $vessel): View
    {
        $vessel = $this->records->vessels($request->user())->whereKey($vessel)->firstOrFail();
        $certificates = $this->records->certificates($request->user())->where('vessel_id', $vessel->id)
            ->with(['vessel:id,vessel_name', 'certificateType:id,name', 'currentVersion'])
            ->latest()->orderByDesc('id')->paginate(15);

        return view('client.vessel', compact('vessel', 'certificates'));
    }

    public function certificates(Request $request): View
    {
        $search = $this->search($request);
        $certificates = $this->records->certificates($request->user())
            ->with(['vessel:id,vessel_name', 'certificateType:id,name', 'currentVersion'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereHas('vessel', fn ($query) => $query->whereLike('vessel_name', '%'.$search.'%'))
                ->orWhereHas('certificateType', fn ($query) => $query->whereLike('name', '%'.$search.'%'))))
            ->latest()->orderByDesc('id')->paginate(15)->withQueryString();

        return view('client.certificates', compact('certificates', 'search'));
    }

    public function certificate(Request $request, string $certificate): View
    {
        $certificate = $this->records->certificates($request->user())->whereKey($certificate)
            ->with(['vessel:id,vessel_name', 'certificateType:id,name', 'currentVersion'])->firstOrFail();
        $version = $certificate->currentVersion;
        $versions = $certificate->versions()->orderByDesc('version_number')->paginate(15);

        return view('client.certificate', compact('certificate', 'version', 'versions'));
    }

    private function search(Request $request): string
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']]);

        return $data['search'] ?? '';
    }
}
