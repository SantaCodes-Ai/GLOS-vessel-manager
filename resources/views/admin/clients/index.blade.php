@extends('layouts.admin')
@section('title', 'Staff Members')
@section('content')
    <p class="eyebrow">Staff management</p>
    <div class="vessel-toolbar"><h1>Staff Members</h1><a class="vessel-button" href="{{ route('admin.clients.create') }}">Add Staff Member</a></div>
    <p class="welcome">Manage staff accounts and their assigned vessels.</p>
    <form method="GET" action="{{ route('admin.clients.index') }}" class="panel vessel-filters">
        <div><label for="search">Staff name or email</label><input id="search" name="search" value="{{ $search }}" maxlength="255" placeholder="Search staff members"></div>
        <button class="vessel-button" type="submit">Search</button><a class="vessel-link" href="{{ route('admin.clients.index') }}">Reset</a>
        @foreach ($errors->all() as $error)<p role="alert" class="vessel-error">{{ $error }}</p>@endforeach
    </form>
    <section class="panel" aria-label="Staff accounts">
        @if ($clients->isEmpty())
            <div class="empty-state"><strong>{{ $search !== '' ? 'No matching staff members' : 'No staff accounts yet' }}</strong><p>{{ $search !== '' ? 'Try a different search or reset the search.' : 'Use Add Staff Member to create an account.' }}</p></div>
        @else
            <div class="table-scroll" tabindex="0" role="region" aria-label="Staff listing">
                <table><caption>Staff accounts</caption><thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Status</th><th scope="col">Assigned vessels</th><th scope="col">Actions</th></tr></thead>
                    <tbody>@foreach ($clients as $client)<tr>
                        <td><a class="vessel-link" href="{{ route('admin.clients.show', $client) }}">{{ $client->name }}</a></td>
                        <td>{{ $client->email }}</td><td>{{ $client->is_active ? 'Active' : 'Inactive' }}</td><td>{{ $client->vessels_count }}</td>
                        <td><a class="vessel-link" href="{{ route('admin.clients.edit', $client) }}" aria-label="Edit {{ $client->name }}">Edit</a></td>
                    </tr>@endforeach</tbody>
                </table>
            </div>
        @endif
    </section>
    <div class="vessel-pagination"><p>{{ $clients->total() }} staff members · Page {{ $clients->currentPage() }} of {{ $clients->lastPage() }}</p>
        <nav aria-label="Staff pagination">
            @if($clients->previousPageUrl())<a class="logout-button" href="{{ $clients->previousPageUrl() }}" rel="prev">Previous</a>@endif
            @if($clients->nextPageUrl())<a class="logout-button" href="{{ $clients->nextPageUrl() }}" rel="next">Next</a>@endif
        </nav>
    </div>
@endsection
