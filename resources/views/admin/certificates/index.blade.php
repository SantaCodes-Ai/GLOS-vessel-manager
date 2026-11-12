@extends('layouts.admin')
@section('title', 'Certificates')
@section('content')
    <p class="eyebrow">Certificate register</p>
    <div class="vessel-toolbar"><h1>Certificates</h1><a class="vessel-button" href="{{ route('admin.certificates.create') }}">Register Certificate</a></div>
    <p class="welcome">Vessel certificate records and private documents.</p>
    <form method="GET" action="{{ route('admin.certificates.index') }}" class="panel vessel-filters">
        <div><label for="search">Vessel or certificate type</label><input id="search" name="search" value="{{ $search }}" maxlength="255" placeholder="Search certificates"></div>
        <div><label for="status">Expiry status</label><select id="status" name="status">
            <option value="">All statuses</option>
            @foreach($statusOptions as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach
        </select></div>
        <button class="vessel-button" type="submit">Search</button>
        <a href="{{ route('admin.certificates.index') }}" class="vessel-link">Reset</a>
        @foreach ($errors->all() as $error)<p role="alert" class="vessel-error">{{ $error }}</p>@endforeach
    </form>
    @if($status !== '')
        <p class="welcome" role="status">Expiry filter: <strong>{{ $statusOptions[$status] }}</strong>.
            <a class="vessel-link" href="{{ route('admin.certificates.index', $search !== '' ? ['search' => $search] : []) }}">Clear expiry filter</a>
        </p>
    @endif
    <section class="panel" aria-label="Registered certificates">
        @if ($certificates->isEmpty())
            <div class="empty-state"><strong>{{ $search !== '' || $status !== '' ? 'No matching certificates' : 'No certificates registered yet' }}</strong><p>{{ $search !== '' || $status !== '' ? 'Try a different search or expiry status, or use Reset to show all certificates.' : 'Use Register Certificate to add a certificate and its PDF.' }}</p></div>
        @else
            <div class="table-scroll" tabindex="0" role="region" aria-label="Certificate listing">
                <table><caption>Registered certificates</caption>
                    <thead><tr><th scope="col">Certificate type</th><th scope="col">Vessel</th><th scope="col">Current version / Reference</th><th scope="col">Expiry</th><th scope="col">Actions</th></tr></thead>
                    <tbody>@foreach ($certificates as $certificate)
                        <tr>
                            <td>{{ $certificate->certificateType?->name ?? 'Not recorded' }}</td>
                            <td>{{ $certificate->vessel?->vessel_name ?? 'Not recorded' }}</td>
                            <td>{{ $certificate->currentVersion ? 'Version '.$certificate->currentVersion->version_number : 'No version' }}<br>{{ $certificate->currentVersion?->certificate_number ?? 'Not recorded' }}</td>
                            <td>@include('admin.certificates.expiry')</td>
                            <td><a class="vessel-link" href="{{ route('admin.certificates.show', $certificate) }}" aria-label="View certificate {{ $certificate->id }}">View details</a></td>
                        </tr>
                    @endforeach</tbody>
                </table>
            </div>
        @endif
    </section>
    <div class="vessel-pagination">
        <p>{{ $certificates->total() }} certificates · Page {{ $certificates->currentPage() }} of {{ $certificates->lastPage() }}</p>
        <nav aria-label="Certificate pagination">
            @if ($certificates->previousPageUrl())<a class="logout-button" href="{{ $certificates->previousPageUrl() }}" rel="prev">Previous</a>@endif
            @if ($certificates->nextPageUrl())<a class="logout-button" href="{{ $certificates->nextPageUrl() }}" rel="next">Next</a>@endif
        </nav>
    </div>
@endsection
