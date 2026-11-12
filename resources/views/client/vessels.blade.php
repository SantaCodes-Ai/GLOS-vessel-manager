@extends('layouts.client')
@section('title', 'My Vessels')
@section('content')
    <p class="eyebrow">Your fleet</p><h1>My Vessels</h1><p class="welcome">Vessels assigned to your account.</p>
    <form method="GET" action="{{ route('client.vessels.index') }}" class="panel vessel-filters">
        <div><label for="search">Vessel name or IMO number</label><input id="search" name="search" value="{{ $search }}" maxlength="255"></div>
        <button class="vessel-button" type="submit">Search</button><a class="vessel-link" href="{{ route('client.vessels.index') }}">Reset</a>
        @foreach($errors->all() as $error)<p class="vessel-error" role="alert">{{ $error }}</p>@endforeach
    </form>
    <section class="panel" aria-label="My assigned vessels">@include('client.vessels-table')</section>
    @include('client.pagination', ['records' => $vessels])
@endsection
