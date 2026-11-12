@extends('layouts.admin')

@section('content')
    <p class="eyebrow">Fleet overview</p>
    @include('shared.dashboard-greeting')
    <p class="welcome">Here is an overview of your vessel certificate records.</p>

    <dl class="summary-grid" aria-label="Record totals">
        <div class="summary-card summary-card-interactive"><dt><a class="summary-card-link" href="{{ route('admin.vessels.index') }}" aria-label="View all vessels">Total vessels</a></dt><dd>{{ number_format($totalVessels) }}</dd></div>
        <div class="summary-card summary-card-interactive"><dt><a class="summary-card-link" href="{{ route('admin.certificates.index') }}" aria-label="View all certificates">Total certificates</a></dt><dd>{{ number_format($totalCertificates) }}</dd></div>
        <div class="summary-card"><dt>Total certificate types</dt><dd>{{ number_format($totalCertificateTypes) }}</dd></div>
    </dl>

    <h2>Certificate expiry overview</h2>
    <dl class="summary-grid">
        @foreach ($statusCounts as $status => $count)
            <div class="summary-card summary-card-interactive"><dt><a class="summary-card-link" href="{{ route('admin.certificates.index', ['status' => $status]) }}" aria-label="View {{ (new \App\Support\CertificateExpiry($status))->label() }} certificates"><span class="expiry-badge expiry-{{ $status }}">{{ $status === 'expiring-soon' ? 'Expiring Soon' : ucfirst($status) }}</span> certificates</a></dt><dd>{{ number_format($count) }}</dd></div>
        @endforeach
    </dl>
    <section class="panel" aria-labelledby="attention-heading">
        <div class="panel-heading"><h2 id="attention-heading">Certificates Requiring Attention</h2><p>Up to ten current certificates, earliest expiry first.</p></div>
        @if ($attentionCertificates->isEmpty())
            <div class="empty-state"><strong>No certificates requiring attention</strong><p>No current certificates are expired or due within 30 days. Unknown records are counted separately above.</p></div>
        @else
            <div class="table-scroll" tabindex="0" role="region" aria-label="Certificates requiring attention">
                <table><caption>Certificates requiring attention</caption><thead><tr><th scope="col">Certificate</th><th scope="col">Vessel</th><th scope="col">Expiry</th></tr></thead>
                    <tbody>@foreach ($attentionCertificates as $certificate)<tr>
                        <td><a class="vessel-link" href="{{ route('admin.certificates.show', $certificate) }}">{{ $certificate->certificateType?->name ?? 'Certificate' }} · {{ $certificate->currentVersion?->certificate_number ?? 'No reference' }}</a></td>
                        <td>{{ $certificate->vessel?->vessel_name ?? 'Not recorded' }}</td>
                        <td>@include('admin.certificates.expiry')</td>
                    </tr>@endforeach</tbody>
                </table>
            </div>
        @endif
    </section>

    <section id="recent-vessels" class="panel" aria-labelledby="vessels-heading">
        <div class="panel-heading">
            <h2 id="vessels-heading">Recent vessels</h2>
            <p>The five most recently added vessels.</p>
        </div>
        @if ($recentVessels->isEmpty())
            <div class="empty-state"><strong>No vessels yet</strong><p>Vessels will appear here once they have been added.</p></div>
        @else
            <div class="table-scroll" tabindex="0" role="region" aria-label="Recent vessels table">
                <table>
                    <caption>Five most recently added vessels</caption>
                    <thead><tr><th scope="col">Vessel</th><th scope="col">IMO number</th><th scope="col">Type</th><th scope="col">Flag state</th></tr></thead>
                    <tbody>
                        @foreach ($recentVessels as $vessel)
                            <tr><td>{{ $vessel->vessel_name }}</td><td>{{ $vessel->imo_number ?? 'Not recorded' }}</td><td>{{ $vessel->vessel_type }}</td><td>{{ $vessel->flag_state }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section id="recent-certificates" class="panel" aria-labelledby="certificates-heading">
        <div class="panel-heading">
            <h2 id="certificates-heading">Recent certificates</h2>
            <p>The five most recently added certificate records.</p>
        </div>
        @if ($recentCertificates->isEmpty())
            <div class="empty-state"><strong>No certificates yet</strong><p>Certificate records will appear here once they have been added.</p></div>
        @else
            <div class="table-scroll" tabindex="0" role="region" aria-label="Recent certificates table">
                <table>
                    <caption>Five most recently added certificates</caption>
                    <thead><tr><th scope="col">Certificate type</th><th scope="col">Vessel</th><th scope="col">Reference</th><th scope="col">Added</th></tr></thead>
                    <tbody>
                        @foreach ($recentCertificates as $certificate)
                            <tr>
                                <td>{{ $certificate->certificateType?->name ?? 'Not recorded' }}</td>
                                <td>{{ $certificate->vessel?->vessel_name ?? 'Not recorded' }}</td>
                                <td>{{ $certificate->currentVersion?->certificate_number ?? $certificate->certificate_number ?? 'Not recorded' }}</td>
                                <td>{{ $certificate->created_at?->format('d M Y') ?? 'Not recorded' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
