@extends('layouts.admin')
@section('title', 'Review registration')
@section('content')
    <p class="eyebrow">Administrator review</p><h1>{{ $applicant->name }}</h1><p class="welcome">{{ $applicant->email }} · Requested @include('shared.role-label', ['role' => $applicant->requested_role]) · {{ ucfirst($applicant->registration_status) }} · {{ $applicant->hasVerifiedEmail() ? 'Email verified' : 'Email unverified' }}</p>
    <p>Department: {{ $applicant->department ?? 'Not recorded' }}</p>
    @if(session('success'))<p class="vessel-success" role="status">{{ session('success') }}</p>@endif
    <div class="vessel-toolbar">@include('super-admin.delete-user-link', ['deletionTarget' => $applicant])</div>
    @if($errors->any())<div class="vessel-error-summary" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if($decision)
        <section class="panel vessel-form"><h2>Recorded decision</h2><p>{{ ucfirst($decision->decision) }} by user {{ $decision->actor_user_id }} at {{ $decision->created_at }}</p><p>{{ $decision->reason }}</p></section>
    @elseif($applicant->registration_status === 'pending')
        @if($applicant->hasVerifiedEmail())
            <form method="POST" action="{{ route('super-admin.registrations.decide', $applicant) }}" class="panel vessel-form">@csrf
                <input type="hidden" name="decision" value="approved"><button class="vessel-button" type="submit">Approve</button>
            </form>
        @else<p>Email verification is required before approval.</p>@endif
        <button class="vessel-button delete-user-button" type="button" onclick="document.getElementById('reject-registration').showModal()">Reject</button>
        <dialog id="reject-registration" class="confirmation-dialog" aria-labelledby="reject-title"><h2 id="reject-title">Reject this registration?</h2><p>{{ $applicant->name }} · {{ $applicant->email }}</p>
            <form method="dialog"><button class="logout-button">Cancel</button></form>
            <form method="POST" action="{{ route('super-admin.registrations.decide', $applicant) }}">@csrf<input type="hidden" name="decision" value="rejected"><button class="vessel-button delete-user-button" type="submit">Confirm Rejection</button></form>
        </dialog>
    @endif
    <p><a class="vessel-link" href="{{ route('super-admin.registrations.index') }}">Back to registrations</a></p>
@endsection
