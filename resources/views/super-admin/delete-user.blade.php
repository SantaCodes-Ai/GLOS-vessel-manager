@extends('layouts.admin')
@section('title', 'Permanently delete user')
@section('content')
    <p class="eyebrow">Administrator account management</p><h1>Permanently delete user</h1>
    <section class="panel vessel-form" aria-label="Selected account"><dl class="vessel-form-grid vessel-details">
        <div><dt>Name</dt><dd>{{ $target->name }}</dd></div><div><dt>Email</dt><dd>{{ $target->email }}</dd></div>
        <div><dt>Account ID</dt><dd>{{ $target->id }}</dd></div><div><dt>Role</dt><dd>@include('shared.role-label', ['role' => $target->role])</dd></div>
        <div><dt>Registration status</dt><dd>{{ $target->registration_status ?? 'Administrator-created account' }}</dd></div><div><dt>Account status</dt><dd>{{ $target->is_active ? 'Active' : 'Inactive' }}</dd></div>
    </dl></section>
    <div class="vessel-error-summary" role="alert"><strong>Deletion is permanent and cannot be undone.</strong><p>The account and vessel assignments will be removed, and the email can be registered again. Vessels, certificates, certificate versions, PDFs and security audit history will be preserved. Certificate creator/uploader references will become unassigned.</p></div>
    @if($errors->any())<div class="vessel-error-summary" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <button class="vessel-button delete-user-button" type="button" onclick="document.getElementById('delete-user-confirmation').showModal()">Delete User</button>
    <dialog id="delete-user-confirmation" class="confirmation-dialog" aria-labelledby="delete-title">
        <h2 id="delete-title">Confirm permanent deletion</h2>
        <p>Are you sure you want to permanently delete this user? This action cannot be undone.</p><p>{{ $target->name }} · {{ $target->email }}</p>
        <form method="dialog"><button class="logout-button">Cancel</button></form>
        <form method="POST" action="{{ route('super-admin.users.destroy', $target) }}">@csrf @method('DELETE')
            <input type="hidden" name="confirmation" value="1"><button class="vessel-button delete-user-button" type="submit">Confirm Delete</button>
        </form>
    </dialog>
@endsection
