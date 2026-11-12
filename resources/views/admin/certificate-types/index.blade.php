@extends('layouts.admin')
@section('title', 'Certificate types')
@section('content')
    <p class="eyebrow">Certificate catalogue</p>
    <div class="vessel-toolbar"><h1>Certificate types</h1><a class="vessel-button" href="{{ route('admin.certificate-types.create') }}">Add Certificate Type</a></div>
    <p class="welcome">Maintain the certificate types used across your fleet.</p>
    <form method="GET" action="{{ route('admin.certificate-types.index') }}" class="panel vessel-filters">
        <div><label for="search">Certificate name or category</label><input id="search" name="search" value="{{ $search }}" maxlength="255" placeholder="Search certificate types"></div>
        <button class="vessel-button" type="submit">Search</button>
        <a href="{{ route('admin.certificate-types.index') }}" class="vessel-link">Reset</a>
        @foreach ($errors->all() as $error)<p role="alert" class="vessel-error">{{ $error }}</p>@endforeach
    </form>
    <section class="panel" aria-label="Registered certificate types">
        @if ($certificateTypes->isEmpty())
            <div class="empty-state"><strong>{{ $search !== '' ? 'No matching certificate types' : 'No certificate types registered yet' }}</strong><p>{{ $search !== '' ? 'Try a different search or reset the search.' : 'Use Add Certificate Type to create your first entry.' }}</p></div>
        @else
            <div class="table-scroll" tabindex="0" role="region" aria-label="Certificate type listing">
                <table><caption>Registered certificate types</caption>
                    <thead><tr><th scope="col">Name</th><th scope="col">Category</th><th scope="col">Default validity</th><th scope="col">Actions</th></tr></thead>
                    <tbody>@foreach ($certificateTypes as $certificateType)
                        <tr>
                            <td><a class="vessel-link" href="{{ route('admin.certificate-types.show', $certificateType) }}">{{ $certificateType->name }}</a></td>
                            <td>{{ $certificateType->category }}</td>
                            <td>{{ $certificateType->default_validity_months === null ? 'Not specified' : $certificateType->default_validity_months.' months' }}</td>
                            <td><a class="vessel-link" href="{{ route('admin.certificate-types.edit', $certificateType) }}" aria-label="Edit {{ $certificateType->name }}">Edit</a></td>
                        </tr>
                    @endforeach</tbody>
                </table>
            </div>
        @endif
    </section>
    <div class="vessel-pagination">
        <p>{{ $certificateTypes->total() }} certificate types · Page {{ $certificateTypes->currentPage() }} of {{ $certificateTypes->lastPage() }}</p>
        <nav aria-label="Certificate type pagination">
            @if ($certificateTypes->previousPageUrl())<a class="logout-button" href="{{ $certificateTypes->previousPageUrl() }}" rel="prev">Previous</a>@endif
            @if ($certificateTypes->nextPageUrl())<a class="logout-button" href="{{ $certificateTypes->nextPageUrl() }}" rel="next">Next</a>@endif
        </nav>
    </div>
@endsection
