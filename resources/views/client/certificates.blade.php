@extends('layouts.client')
@section('title', 'My Certificates')
@section('content')
    <p class="eyebrow">Your certificate register</p><h1>My Certificates</h1><p class="welcome">Read-only certificate records for your assigned vessels.</p>
    <form method="GET" action="{{ route('client.certificates.index') }}" class="panel vessel-filters">
        <div><label for="search">Vessel or certificate type</label><input id="search" name="search" value="{{ $search }}" maxlength="255"></div>
        <button class="vessel-button" type="submit">Search</button><a class="vessel-link" href="{{ route('client.certificates.index') }}">Reset</a>
        @foreach($errors->all() as $error)<p class="vessel-error" role="alert">{{ $error }}</p>@endforeach
    </form>
    <section class="panel" aria-label="My certificates">@include('client.certificates-table')</section>
    @include('client.pagination', ['records' => $certificates])
@endsection
