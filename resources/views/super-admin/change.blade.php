@extends('layouts.admin')
@section('title', 'Confirm privilege change')
@section('content')
    <p class="eyebrow">Privileged account management</p><h1>Confirm privilege change</h1>
    <p class="welcome">Target: {{ $administrator->name }} (account {{ $administrator->id }}) · {{ $administrator->email }}<br>Current role: {{ $administrator->isAdmin() ? 'Administrator' : 'Staff' }} · {{ $administrator->is_active ? 'Active' : 'Inactive' }}</p>
    <div class="vessel-toolbar">@include('super-admin.delete-user-link', ['deletionTarget' => $administrator])</div>
    @if($errors->any())<div class="vessel-error-summary" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('super-admin.administrators.update', $administrator) }}" class="panel vessel-form">
        @csrf
        <div class="vessel-form-grid">
            <div><label for="action">Change *</label><select id="action" name="action" required><option value="">Choose a change</option>
                @if($administrator->isAdmin())<option value="demote">Change to Staff</option>@elseif($administrator->is_active)<option value="promote">Change to administrator</option>@endif
                <option value="{{ $administrator->is_active ? 'deactivate' : 'activate' }}">{{ $administrator->is_active ? 'Deactivate account' : 'Activate account' }}</option>
            </select></div>
            <div><label for="target_email">Type the target account's email to confirm *</label><input id="target_email" name="target_email" type="email" required autocomplete="off"></div>
            <div><label for="password">Your current password *</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
            <div><label for="reason">Reason for the change *</label><textarea class="certificate-description" id="reason" name="reason" minlength="5" maxlength="1000" required></textarea></div>
        </div>
        <label class="client-vessel-option"><input type="checkbox" name="confirm" value="1" required><span>I explicitly authorise this privilege/status change for the identified account.</span></label>
        <div class="vessel-toolbar"><button class="vessel-button" type="submit">Confirm and save audited change</button><a class="vessel-link" href="{{ route('super-admin.administrators.index') }}">Cancel</a></div>
    </form>
@endsection
