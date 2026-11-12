@extends('layouts.admin')
@section('title', 'Registration approvals')
@section('content')
    <p class="eyebrow">Administrator review</p><h1>Registration approvals</h1><p class="welcome">Pending requests appear first. Only verified applicants can be approved.</p>
    <section class="panel"><div class="table-scroll" tabindex="0" role="region" aria-label="Registration requests"><table><caption>Registrations and decisions</caption><thead><tr><th scope="col">Applicant</th><th scope="col">Requested access</th><th scope="col">Email verified</th><th scope="col">Registered</th><th scope="col">Status</th><th scope="col">Review</th></tr></thead>
        <tbody>@forelse($applicants as $applicant)<tr><td>{{ $applicant->name }}<br>{{ $applicant->email }}<br>Department: {{ $applicant->department ?? 'Not recorded' }}</td><td>@include('shared.role-label', ['role' => $applicant->requested_role])</td><td>{{ $applicant->hasVerifiedEmail() ? 'Verified' : 'Unverified' }}</td><td>{{ $applicant->created_at?->format('d M Y H:i') }}</td><td>{{ ucfirst($applicant->registration_status) }}</td><td><a class="vessel-link" href="{{ route('super-admin.registrations.show', $applicant) }}">Review request</a></td></tr>@empty<tr><td colspan="6">No registration requests yet.</td></tr>@endforelse</tbody>
    </table></div></section>
    <div class="vessel-pagination"><p>Page {{ $applicants->currentPage() }} of {{ $applicants->lastPage() }}</p><nav aria-label="Registration pagination">@if($applicants->previousPageUrl())<a href="{{ $applicants->previousPageUrl() }}">Previous</a>@endif @if($applicants->nextPageUrl())<a href="{{ $applicants->nextPageUrl() }}">Next</a>@endif</nav></div>
@endsection
