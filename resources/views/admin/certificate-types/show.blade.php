@extends('layouts.admin')
@section('title', 'Certificate type details')
@section('content')
    <p class="eyebrow">Certificate catalogue</p>
    <div class="vessel-toolbar"><h1>{{ $certificateType->name }}</h1><a class="vessel-button" href="{{ route('admin.certificate-types.edit', $certificateType) }}">Edit certificate type</a></div>
    <p class="welcome"><a class="vessel-link" href="{{ route('admin.certificate-types.index') }}">Back to certificate types</a></p>
    @if (session('success'))<p class="vessel-success" role="status">{{ session('success') }}</p>@endif
    <section class="panel vessel-form" aria-label="Certificate type details">
        <dl class="vessel-form-grid vessel-details">
            <div><dt>Certificate name</dt><dd>{{ $certificateType->name }}</dd></div>
            <div><dt>Category</dt><dd>{{ $certificateType->category }}</dd></div>
            <div><dt>Default validity</dt><dd>{{ $certificateType->default_validity_months === null ? 'Not specified' : $certificateType->default_validity_months.' months' }}</dd></div>
            <div><dt>Description</dt><dd class="certificate-description-text">{{ $certificateType->description ?? 'Not specified' }}</dd></div>
        </dl>
    </section>
@endsection
