<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Global Link Ocean Streams</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @livewireStyles
    <style>
        .login-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            margin-top: 1rem;
            padding: .625rem 1.25rem;
            border: 1px solid var(--brand-blue);
            border-radius: .375rem;
            background-color: var(--brand-blue);
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        .login-submit:hover { background-color: var(--brand-blue-hover); }
        .login-submit:focus-visible { outline: 3px solid var(--brand-blue); outline-offset: 3px; }
        .login-submit:disabled { opacity: .65; cursor: wait; }
        .auth-form { background: #fff; color: #172c43; border: 1px solid #c4d2df; border-radius: 12px; padding: 24px; }
        .auth-form h1 { font-size: 28px; font-weight: 700; margin-bottom: 16px; }
        .auth-form label { display: block; margin-top: 16px; font-weight: 600; }
        .auth-form input, .auth-form select { display: block; width: 100%; min-height: 44px; border: 1px solid #8298ac; border-radius: 6px; padding: 10px; background: white; color: #172c43; font: inherit; }
        .auth-form a { color: var(--brand-blue); text-decoration: underline; }
        .auth-form input:focus-visible, .auth-form select:focus-visible, .auth-form a:focus-visible { outline: 3px solid var(--brand-blue); outline-offset: 2px; }
        .auth-form .auth-errors { color: #991b1b; background: #fff1f2; border: 1px solid #fca5a5; padding: 16px; }
    </style>
    @include('shared.brand-styles')
</head>
<body class="glos-auth min-h-screen bg-slate-100 text-slate-900">
    <main class="mx-auto max-w-lg p-8">
        <a class="auth-brand" href="{{ route('login') }}" aria-label="Global Link Ocean Streams login">@include('shared.company-logo')</a>
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
