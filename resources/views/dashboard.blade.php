@component('layouts.auth')
    <h1 class="mb-4 text-2xl font-semibold">{{ $title }}</h1>
    <p>Welcome, {{ auth()->user()->name }}.</p>
    <p class="my-4">Your vessel certificate dashboard is coming soon.</p>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-white">Sign out</button>
    </form>
@endcomponent
