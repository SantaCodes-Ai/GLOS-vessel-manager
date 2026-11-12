@extends('layouts.admin')
@section('title', 'Certificate details')
@section('content')
    <p class="eyebrow">Certificate register</p>
    <h1>{{ $certificate->certificateType?->name ?? 'Certificate details' }}</h1>
    <div class="vessel-toolbar">@include('admin.certificates.expiry') <a class="vessel-button" href="{{ route('admin.certificates.renew', $certificate) }}">Renew Certificate</a></div>
    <p class="welcome"><a class="vessel-link" href="{{ route('admin.certificates.index') }}">Back to certificates</a></p>
    @if (session('success'))<p class="vessel-success" role="status">{{ session('success') }}</p>@endif
    <section class="panel vessel-form" aria-label="Certificate details">
        <dl class="vessel-form-grid vessel-details">
            <div><dt>Vessel</dt><dd>{{ $certificate->vessel?->vessel_name ?? 'Not recorded' }}</dd></div>
            <div><dt>Certificate type</dt><dd>{{ $certificate->certificateType?->name ?? 'Not recorded' }}</dd></div>
            <div><dt>Current certificate number</dt><dd>{{ $version?->certificate_number ?? 'Not recorded' }}</dd></div>
            <div><dt>Remarks</dt><dd class="certificate-description-text">{{ $certificate->remarks ?? 'Not recorded' }}</dd></div>
            @if ($version)
                <div><dt>Version</dt><dd>{{ $version->version_number }}</dd></div>
                <div><dt>Issuance date</dt><dd>{{ $version->getRawOriginal('issuance_date') ?? 'Not recorded' }}</dd></div>
                <div><dt>Expiry type</dt><dd>{{ ucfirst($version->expiry_type) }}</dd></div>
                <div><dt>Expiry date</dt><dd>{{ $version->expiry_type === 'permanent' ? 'Not applicable' : ($version->getRawOriginal('expiry_date') ?? 'Not recorded') }}</dd></div>
                <div><dt>Original filename</dt><dd>{{ $version->original_filename }}</dd></div>
                <div><dt>Uploaded</dt><dd>{{ $version->uploaded_at?->format('d M Y H:i') ?? 'Not recorded' }}</dd></div>
            @endif
        </dl>
        @if ($version)
            <div class="vessel-toolbar"><a class="vessel-button" href="{{ route('admin.certificates.preview', $certificate) }}" target="_blank" rel="noopener noreferrer">Preview PDF (new tab)</a><a class="vessel-button" href="{{ route('admin.certificates.download', $certificate) }}">Download PDF</a></div>
        @else
            <p class="vessel-error" role="status">No initial document version is available for this certificate.</p>
        @endif
    </section>
    <section class="panel" aria-labelledby="history-heading">
        <div class="panel-heading"><h2 id="history-heading">Version history</h2><p>Previous documents are preserved unchanged.</p></div>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Certificate version history">
            <table><caption>Certificate version history</caption>
                <thead><tr><th scope="col">Version</th><th scope="col">Certificate number</th><th scope="col">Issued</th><th scope="col">Expires</th><th scope="col">Expiry type</th><th scope="col">Uploaded</th><th scope="col">PDF</th></tr></thead>
                <tbody>@forelse ($versions as $historical)
                    <tr>
                        <td>{{ $historical->version_number }} · {{ $version?->id === $historical->id ? 'Current' : 'Historical' }}</td>
                        <td>{{ $historical->certificate_number ?? 'Not recorded' }}</td>
                        <td>{{ $historical->getRawOriginal('issuance_date') ?? 'Not recorded' }}</td>
                        <td>{{ $historical->expiry_type === 'permanent' ? 'Not applicable' : ($historical->getRawOriginal('expiry_date') ?? 'Not recorded') }}</td>
                        <td>{{ ucfirst($historical->expiry_type) }}</td>
                        <td>{{ $historical->uploaded_at?->format('d M Y H:i') ?? 'Not recorded' }}</td>
                        <td><a class="vessel-link" href="{{ route('admin.certificates.versions.preview', [$certificate, $historical->version_number]) }}" target="_blank" rel="noopener noreferrer">Preview v{{ $historical->version_number }}</a><br><a class="vessel-link" href="{{ route('admin.certificates.versions.download', [$certificate, $historical->version_number]) }}">Download v{{ $historical->version_number }}</a></td>
                    </tr>
                @empty<tr><td colspan="7">No version history available.</td></tr>@endforelse</tbody>
            </table>
        </div>
        <div class="vessel-pagination">
            <p>Page {{ $versions->currentPage() }} of {{ $versions->lastPage() }}</p>
            <nav aria-label="Version history pagination">
                @if($versions->previousPageUrl())<a href="{{ $versions->previousPageUrl() }}">Previous</a>@endif
                @if($versions->nextPageUrl())<a href="{{ $versions->nextPageUrl() }}">Next</a>@endif
            </nav>
        </div>
    </section>
@endsection
