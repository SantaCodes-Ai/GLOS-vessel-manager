@php
    $nameParts = preg_split('/\s+/u', trim((string) auth()->user()?->name), 2);
    $firstName = ($nameParts[0] ?? '') !== '' ? $nameParts[0] : 'there';
@endphp
<h1>Welcome back, {{ $firstName }}!</h1>
