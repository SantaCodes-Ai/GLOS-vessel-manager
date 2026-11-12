@extends('layouts.admin')
@section('title', 'Staff details')
@section('content')
    <p class="eyebrow">Staff management</p>
    <div class="vessel-toolbar"><h1>{{ $client->name }}</h1><a class="vessel-button" href="{{ route('admin.clients.edit', $client) }}">Edit staff member and assignments</a></div>
    <p class="welcome"><a class="vessel-link" href="{{ route('admin.clients.index') }}">Back to staff members</a></p>
    <div class="vessel-toolbar">@include('super-admin.delete-user-link', ['deletionTarget' => $client])</div>
    @if(session('success'))<p class="vessel-success" role="status">{{ session('success') }}</p>@endif
    <section class="panel vessel-form" aria-label="Staff details"><dl class="vessel-form-grid vessel-details">
        <div><dt>Name</dt><dd>{{ $client->name }}</dd></div><div><dt>Email</dt><dd>{{ $client->email }}</dd></div><div><dt>Account status</dt><dd>{{ $client->is_active ? 'Active' : 'Inactive' }}</dd></div>
        <div><dt>Department</dt><dd>{{ $client->department ?? 'Not recorded' }}</dd></div>
    </dl></section>
    <section class="panel" aria-labelledby="assigned-vessels-heading">
        <div class="panel-heading"><h2 id="assigned-vessels-heading">Assigned vessels</h2><p>{{ $vessels->total() }} assigned vessels</p></div>
        @if($vessels->isEmpty())<div class="empty-state"><strong>No vessels assigned</strong><p>Edit this staff member to assign vessels.</p></div>
        @else
            <div class="table-scroll" tabindex="0" role="region" aria-label="Assigned vessels"><table><caption>Assigned vessels</caption>
                <thead><tr><th scope="col">Vessel</th><th scope="col">IMO number</th><th scope="col">Status</th></tr></thead>
                <tbody>@foreach($vessels as $vessel)<tr><td><a class="vessel-link" href="{{ route('admin.vessels.show', $vessel) }}">{{ $vessel->vessel_name }}</a></td><td>{{ $vessel->imo_number ?? 'Not recorded' }}</td><td>{{ ucfirst($vessel->vessel_status) }}</td></tr>@endforeach</tbody>
            </table></div>
        @endif
    </section>
    <div class="vessel-pagination"><p>Page {{ $vessels->currentPage() }} of {{ $vessels->lastPage() }}</p><nav aria-label="Assigned vessel pagination">
        @if($vessels->previousPageUrl())<a href="{{ $vessels->previousPageUrl() }}">Previous</a>@endif
        @if($vessels->nextPageUrl())<a href="{{ $vessels->nextPageUrl() }}">Next</a>@endif
    </nav></div>
@endsection
