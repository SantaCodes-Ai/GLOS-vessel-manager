<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Administrator dashboard') | Global Link Ocean Streams</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @include('admin.styles')
</head>
<body class="glos-admin">
    <a class="skip-link" href="#main-content">Skip to content</a>
    <aside class="sidebar" aria-label="Administration">
        <a class="brand" href="{{ route('admin.dashboard') }}" aria-label="Global Link Ocean Streams dashboard">
            @include('shared.company-logo')
        </a>
        <details class="admin-navigation" open>
            <summary>Menu</summary>
            <nav aria-label="Main navigation">
                <a href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>Dashboard</a>
                <a href="{{ route('admin.vessels.index') }}" @if(request()->routeIs('admin.vessels.*')) aria-current="page" @endif>Vessels</a>
                <details class="nav-group" @if(request()->routeIs('admin.certificates.*', 'admin.certificate-types.*')) open @endif>
                    <summary>Certificate Management</summary>
                    <a href="{{ route('admin.certificates.index') }}" @if(request()->routeIs('admin.certificates.*')) aria-current="page" @endif>Certificates</a>
                    <a href="{{ route('admin.certificate-types.index') }}" @if(request()->routeIs('admin.certificate-types.*')) aria-current="page" @endif>Certificate Types</a>
                </details>
                <details class="nav-group" @if(request()->routeIs('admin.users.*', 'admin.clients.*', 'super-admin.*')) open @endif>
                    <summary>User Management</summary>
                    <a href="{{ route('admin.users.index') }}" @if(request()->routeIs('admin.users.*', 'super-admin.users.*')) aria-current="page" @endif>All Users</a>
                    <a href="{{ route('admin.clients.index') }}" @if(request()->routeIs('admin.clients.*')) aria-current="page" @endif>Staff / Assignments</a>
                    <a href="{{ route('super-admin.registrations.index') }}" @if(request()->routeIs('super-admin.registrations.*')) aria-current="page" @endif>Registration Approvals</a>
                    <a href="{{ route('super-admin.administrators.index') }}" @if(request()->routeIs('super-admin.administrators.*')) aria-current="page" @endif>Administrators</a>
                </details>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="nav-logout">Logout</button></form>
            </nav>
        </details>
        <div class="sidebar-footer">Vessel Certificate<br>Management System</div>
    </aside>
    <div class="workspace">
        <header class="topbar">
            <span class="workspace-label">Administration workspace</span>
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
