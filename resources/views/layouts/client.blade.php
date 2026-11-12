<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Staff Dashboard') | Global Link Ocean Streams</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @include('admin.styles')
</head>
<body class="glos-admin">
    <a class="skip-link" href="#main-content">Skip to content</a>
    <aside class="sidebar" aria-label="Staff workspace">
        <a class="brand" href="{{ route('client.dashboard') }}" aria-label="Global Link Ocean Streams dashboard">
            @include('shared.company-logo')
        </a>
        <p class="nav-label">Workspace</p>
        <nav aria-label="Main navigation">
            <a href="{{ route('client.dashboard') }}" @if(request()->routeIs('client.dashboard')) aria-current="page" @endif>Overview</a>
            <a href="{{ route('client.vessels.index') }}" @if(request()->routeIs('client.vessels.*')) aria-current="page" @endif>My Vessels</a>
            <a href="{{ route('client.certificates.index') }}" @if(request()->routeIs('client.certificates.*')) aria-current="page" @endif>My Certificates</a>
        </nav>
        <div class="sidebar-footer">Vessel Certificate<br>Management System</div>
    </aside>
    <div class="workspace">
        <header class="topbar">
            <span class="workspace-label">Staff workspace</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="logout-button">Sign out</button>
            </form>
        </header>
        <main id="main-content" tabindex="-1">
            @yield('content')
        </main>
        <footer class="page-footer">Global Link Ocean Streams · Vessel Certificate Management</footer>
    </div>
</body>
</html>
