@extends('layouts.admin')
@section('title', 'Vessel details')
@section('content')
    <p class="eyebrow">Fleet management</p>
    <div class="vessel-toolbar"><h1>{{ $vessel->vessel_name }}</h1><a class="vessel-button" href="{{ route('admin.vessels.edit', $vessel) }}">Edit vessel</a></div>
    <p class="welcome"><a class="vessel-link" href="{{ route('admin.vessels.index') }}">Back to vessels</a></p>
    @if (session('success'))<p class="vessel-success" role="status">{{ session('success') }}</p>@endif
    <section class="panel vessel-form" aria-label="Vessel details">
        <dl class="vessel-form-grid vessel-details">
            @foreach (['vessel_name' => 'Vessel name', 'imo_number' => 'IMO number', 'vessel_type' => 'Vessel type', 'flag_state' => 'Flag state', 'official_number' => 'Official number', 'vessel_status' => 'Status'] as $field => $label)
                <div><dt>{{ $label }}</dt><dd>{{ $field === 'vessel_status' ? ucfirst($vessel->$field) : ($vessel->$field ?? 'Not recorded') }}</dd></div>
            @endforeach
        </dl>
    </section>
@endsection
