@extends('layouts.admin')
@section('title', 'Renew certificate')
@section('content')
    <p class="eyebrow">Certificate renewal</p>
    <h1>Renew Certificate</h1>
    <p class="welcome">{{ $certificate->certificateType?->name }} · {{ $certificate->vessel?->vessel_name }}<br>This creates a new version. All historical records and PDFs are preserved.</p>
    @if ($errors->any())<div class="vessel-error-summary" role="alert"><strong>Please correct the following:</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul><p>Please select the PDF again before submitting.</p></div>@endif
    @if (! $certificate->currentVersion)
        <p class="vessel-error" role="status">This certificate has no existing version and cannot be renewed.</p>
    @else
        <form method="POST" enctype="multipart/form-data" action="{{ route('admin.certificates.renew.store', $certificate) }}" class="panel vessel-form">
            @csrf
            <div class="vessel-form-grid">@include('admin.certificates.version-fields')</div>
            <div class="vessel-toolbar"><button type="submit" class="vessel-button">Submit renewal</button><a class="vessel-link" href="{{ route('admin.certificates.show', $certificate) }}">Cancel</a></div>
        </form>
    @endif
@endsection
