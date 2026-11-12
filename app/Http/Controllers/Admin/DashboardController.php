<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\Vessel;
use App\Support\CertificateDashboard;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(CertificateDashboard $dashboard): View
    {
        return view('admin.dashboard', array_merge($dashboard->summary(), [
            'totalVessels' => Vessel::count(),
            'totalCertificates' => Certificate::count(),
            'totalCertificateTypes' => CertificateType::count(),
            'recentVessels' => Vessel::query()
                ->latest()->orderByDesc('id')->limit(5)
                ->get(['id', 'vessel_name', 'imo_number', 'vessel_type', 'flag_state']),
            'recentCertificates' => Certificate::query()
                ->with(['vessel:id,vessel_name', 'certificateType:id,name', 'currentVersion'])
                ->latest()->orderByDesc('id')->limit(5)
                ->get(['id', 'vessel_id', 'certificate_type_id', 'certificate_number', 'created_at']),
        ]));
    }
}
