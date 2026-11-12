@extends('layouts.admin')
@section('title', 'Administrator management')
@section('content')
    <p class="eyebrow">Privileged account management</p><h1>Administrator accounts</h1>
    <p class="welcome">Privilege changes require your current password and explicit confirmation. The last active administrator is protected.</p>
    @if(session('success'))<p class="vessel-success" role="status">{{ session('success') }}</p>@endif
    <section class="panel" aria-label="Administrator accounts"><div class="table-scroll" tabindex="0" role="region" aria-label="Administrator accounts">
        <table><caption>Administrator accounts</caption><thead><tr><th scope="col">Account</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
            <tbody>@foreach($administrators as $administrator)<tr>
                <td>{{ $administrator->name }}<br>{{ $administrator->email }}<br>Department: {{ $administrator->department ?? 'Not recorded' }}</td><td>Administrator</td><td>{{ $administrator->is_active ? 'Active' : 'Inactive' }}</td>
                <td><a class="vessel-link" href="{{ route('super-admin.administrators.edit', $administrator) }}">Review privilege change</a><br>@include('super-admin.delete-user-link', ['deletionTarget' => $administrator])</td>
            </tr>@endforeach</tbody>
        </table>
    </div></section>
    <div class="vessel-pagination"><p>Page {{ $administrators->currentPage() }} of {{ $administrators->lastPage() }}</p><nav aria-label="Administrator pagination">
        @if($administrators->previousPageUrl())<a href="{{ $administrators->previousPageUrl() }}">Previous</a>@endif
        @if($administrators->nextPageUrl())<a href="{{ $administrators->nextPageUrl() }}">Next</a>@endif
    </nav></div>
    <section class="panel vessel-form"><h2>Registration requests</h2><p><a class="vessel-link" href="{{ route('super-admin.registrations.index') }}">Review registrations and prior decisions</a></p></section>
    <section class="panel" aria-label="Privilege audit"><div class="panel-heading"><h2>Recent privileged changes</h2><p>The 25 latest committed changes. Account IDs are retained as historical references.</p></div>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Privilege audit"><table><caption>Recent privileged changes</caption>
            <thead><tr><th scope="col">Time</th><th scope="col">Actor</th><th scope="col">Target ID</th><th scope="col">Change</th><th scope="col">Reason</th></tr></thead>
            <tbody>@forelse($audits as $audit)<tr><td>{{ $audit->created_at }}</td><td>{{ $audit->actor_user_id ? 'User '.$audit->actor_user_id : 'Console: '.$audit->operator_identifier }}</td><td>{{ $audit->target_user_id }}</td><td>{{ $audit->action }}: {{ $audit->previous_role }} → {{ $audit->new_role }}<br>{{ $audit->previous_is_active ? 'Active' : 'Inactive' }} → {{ $audit->new_is_active ? 'Active' : 'Inactive' }}</td><td>{{ $audit->reason }}</td></tr>@empty<tr><td colspan="5">No privileged changes recorded.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
@endsection
