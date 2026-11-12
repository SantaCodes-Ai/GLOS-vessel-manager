@extends('layouts.admin')
@section('title', 'All users')
@section('content')
    <h1>All Users</h1><p class="welcome">Review accounts, departments and registration status.</p>
    @if(session('success'))<p class="vessel-success" role="status">{{ session('success') }}</p>@endif
    <form method="GET" action="{{ route('admin.users.index') }}" class="panel vessel-filters"><div><label for="search">Name or email</label><input id="search" name="search" maxlength="255" value="{{ $search }}"></div><button class="vessel-button">Search</button></form>
    <section class="panel"><div class="table-scroll" tabindex="0" role="region" aria-label="Users"><table><caption>User accounts</caption><thead><tr><th scope="col">User</th><th scope="col">Role / Status</th><th scope="col">Department</th><th scope="col">Manage</th></tr></thead><tbody>
        @forelse($users as $user)<tr><td>{{ $user->name }}<br>{{ $user->email }}</td><td>{{ $user->isAdmin() ? 'Administrator' : 'Staff' }}<br>{{ $user->registration_status ?? 'Legacy' }} · {{ $user->is_active ? 'Active' : 'Inactive' }}</td><td>{{ $user->department ?? 'Not recorded' }}</td><td>
            <a class="vessel-link" href="{{ route('admin.users.department.edit', $user) }}">Edit department</a><br>
            @if($user->registration_status !== null)<a class="vessel-link" href="{{ route('super-admin.registrations.show', $user) }}">Registration</a><br>@endif
            @if($user->hasApprovedIdentity())<a class="vessel-link" href="{{ route('super-admin.administrators.edit', $user) }}">Role / Status</a><br>@endif
            @if($user->isManagedClient())<a class="vessel-link" href="{{ route('admin.clients.show', $user) }}">Staff / Assignments</a><br>@endif
            @include('super-admin.delete-user-link', ['deletionTarget' => $user])
        </td></tr>@empty<tr><td colspan="4">No users found.</td></tr>@endforelse
    </tbody></table></div></section>
    @include('client.pagination', ['records' => $users])
@endsection
