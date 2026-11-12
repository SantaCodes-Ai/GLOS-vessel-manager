@extends('layouts.client')
@section('title', 'Assigned vessel details')
@section('content')
    <p class="eyebrow">Your fleet</p><h1>{{ $vessel->vessel_name }}</h1>
    <p class="welcome"><a class="vessel-link" href="{{ route('client.vessels.index') }}">Back to My Vessels</a></p>
    <section class="panel vessel-form" aria-label="Vessel details"><dl class="vessel-form-grid vessel-details">
        @foreach(['imo_number' => 'IMO number', 'vessel_type' => 'Vessel type', 'flag_state' => 'Flag state', 'official_number' => 'Official number', 'vessel_status' => 'Status'] as $field => $label)
            <div><dt>{{ $label }}</dt><dd>{{ $vessel->$field ?? 'Not recorded' }}</dd></div>
        @endforeach
    </dl></section>
    <section class="panel" aria-labelledby="certificates-heading"><div class="panel-heading"><h2 id="certificates-heading">Vessel certificates</h2></div>@include('client.certificates-table')</section>
    @include('client.pagination', ['records' => $certificates])
@endsection
