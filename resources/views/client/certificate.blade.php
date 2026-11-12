@extends('layouts.client')
@section('title', 'Certificate details')
@section('content')
    <p class="eyebrow">Your certificate register</p><h1>{{ $certificate->certificateType?->name ?? 'Certificate details' }}</h1>
    <p class="welcome"><a class="vessel-link" href="{{ route('client.certificates.index') }}">Back to My Certificates</a></p>
    <section class="panel vessel-form" aria-label="Certificate details">
        @include('admin.certificates.expiry')
        <dl class="vessel-form-grid vessel-details">
            <div><dt>Vessel</dt><dd>{{ $certificate->vessel?->vessel_name ?? 'Not recorded' }}</dd></div>
            <div><dt>Current version</dt><dd>{{ $version?->version_number ?? 'Not recorded' }}</dd></div>
            <div><dt>Certificate number</dt><dd>{{ $version?->certificate_number ?? 'Not recorded' }}</dd></div>
            <div><dt>Issuance date</dt><dd>{{ $version?->getRawOriginal('issuance_date') ?? 'Not recorded' }}</dd></div>
        </dl>
        @if($version)
            <div class="vessel-toolbar"><a class="vessel-button" href="{{ route('client.certificates.preview', $certificate) }}" target="_blank" rel="noopener noreferrer">Preview PDF (new tab)</a><a class="vessel-button" href="{{ route('client.certificates.download', $certificate) }}">Download PDF</a></div>
        @else<p class="vessel-error" role="status">No document version is available.</p>@endif
    </section>
    <section class="panel" aria-labelledby="history-heading"><div class="panel-heading"><h2 id="history-heading">Version history</h2></div>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Certificate version history"><table><caption>Certificate version history</caption>
            <thead><tr><th scope="col">Version</th><th scope="col">Reference</th><th scope="col">Issued</th><th scope="col">Expires</th><th scope="col">Expiry type</th><th scope="col">Uploaded</th><th scope="col">PDF</th></tr></thead>
            <tbody>@forelse($versions as $historical)<tr>
                <td>{{ $historical->version_number }} · {{ $version?->id === $historical->id ? 'Current' : 'Historical' }}</td><td>{{ $historical->certificate_number ?? 'Not recorded' }}</td>
                <td>{{ $historical->getRawOriginal('issuance_date') ?? 'Not recorded' }}</td><td>{{ $historical->expiry_type === 'permanent' ? 'Not applicable' : ($historical->getRawOriginal('expiry_date') ?? 'Not recorded') }}</td>
                <td>{{ ucfirst($historical->expiry_type) }}</td><td>{{ $historical->uploaded_at?->format('d M Y') ?? 'Not recorded' }}</td>
                <td><a class="vessel-link" href="{{ route('client.certificates.versions.preview', [$certificate, $historical->version_number]) }}" target="_blank" rel="noopener noreferrer">Preview v{{ $historical->version_number }}</a><br><a class="vessel-link" href="{{ route('client.certificates.versions.download', [$certificate, $historical->version_number]) }}">Download v{{ $historical->version_number }}</a></td>
            </tr>@empty<tr><td colspan="7">No version history available.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
    @include('client.pagination', ['records' => $versions])
@endsection
