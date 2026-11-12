@extends('layouts.admin')
@section('title', 'Vessels')
@section('content')
    <p class="eyebrow">Fleet management</p>
    <div class="vessel-toolbar"><h1>Vessels</h1><a class="vessel-button" href="{{ route('admin.vessels.create') }}">Add Vessel</a></div>
    <p class="welcome">Register and maintain your fleet information.</p>
    <form method="GET" action="{{ route('admin.vessels.index') }}" class="panel vessel-filters">
        <div><label for="search">Vessel name or IMO number</label><input id="search" name="search" value="{{ $search }}" maxlength="255" placeholder="Search vessels"></div>
        <div><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option><option value="active" @selected($status === 'active')>Active</option><option value="inactive" @selected($status === 'inactive')>Inactive</option></select></div>
        <button class="vessel-button" type="submit">Apply filters</button>
        <a href="{{ route('admin.vessels.index') }}" class="vessel-link">Reset</a>
        @foreach ($errors->all() as $error)<p role="alert" class="vessel-error">{{ $error }}</p>@endforeach
    </form>
    <section class="panel" aria-label="Registered vessels">
        @if ($vessels->isEmpty())
            <div class="empty-state"><strong>{{ $search !== '' || $status !== '' ? 'No matching vessels' : 'No vessels registered yet' }}</strong><p>{{ $search !== '' || $status !== '' ? 'Try a different search or reset the filters.' : 'Use Add Vessel to register your first vessel.' }}</p></div>
        @else
            <div class="table-scroll" tabindex="0" role="region" aria-label="Vessel listing">
                <table><caption>Registered vessels</caption>
                    <thead><tr><th scope="col">Vessel</th><th scope="col">IMO number</th><th scope="col">Type / Flag</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                    <tbody>@foreach ($vessels as $vessel)
                        <tr><td><a class="vessel-link" href="{{ route('admin.vessels.show', $vessel) }}">{{ $vessel->vessel_name }}</a></td><td>{{ $vessel->imo_number ?? 'Not recorded' }}</td><td>{{ $vessel->vessel_type }} / {{ $vessel->flag_state }}</td><td>{{ ucfirst($vessel->vessel_status) }}</td><td><a class="vessel-link" href="{{ route('admin.vessels.edit', $vessel) }}" aria-label="Edit {{ $vessel->vessel_name }}">Edit</a></td></tr>
                    @endforeach</tbody>
                </table>
            </div>
        @endif
    </section>
    <div class="vessel-pagination">
        <p>{{ $vessels->total() }} vessels · Page {{ $vessels->currentPage() }} of {{ $vessels->lastPage() }}</p>
        <nav aria-label="Vessel pagination">
            @if ($vessels->previousPageUrl())<a class="logout-button" href="{{ $vessels->previousPageUrl() }}" rel="prev">Previous</a>@endif
            @if ($vessels->nextPageUrl())<a class="logout-button" href="{{ $vessels->nextPageUrl() }}" rel="next">Next</a>@endif
        </nav>
    </div>
@endsection
