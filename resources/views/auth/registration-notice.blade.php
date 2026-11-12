@component('layouts.auth')
<section class="auth-form">
    @php
        $state = ($applicant ?? null)?->registration_status;
        $emailVerified = ($applicant ?? null)?->hasVerifiedEmail() ?? false;
        $showResend = !($verificationError ?? false) && $state !== 'rejected' && !$emailVerified;
    @endphp
    @if($verificationError ?? false)
        <h1>Verification link unavailable</h1><p>This link is invalid or expired. Request another verification email below.</p>
    @elseif($state === 'rejected')
        <h1>Registration Not Approved</h1><p>Your registration was not approved. Contact Global Link Ocean Streams if you need assistance.</p>
    @elseif($state === 'approved')
        <h1>Registration Approved</h1>
        @if(!$emailVerified)<p>Please verify your current email address before signing in.</p>
        @elseif(!$applicant->is_active)<p>Your registration is approved, but your account is currently inactive. Contact Global Link Ocean Streams for assistance.</p>
        @else<p>Your account is approved. You can now log in.</p>@endif
    @elseif($state === 'pending' && $emailVerified)
        <h1>Email Verified Successfully</h1><h2>Awaiting Administrator Approval</h2><p>Your email is verified. Administrator approval is required before you can sign in.</p>
    @else
        <h1>Email Verification Required</h1><p role="status">{{ session('status', 'If your registration is eligible, check your email for a verification link. Email verification and approval are required before sign in.') }}</p>
    @endif
    @if($errors->any())<div class="auth-errors" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if($showResend || ($verificationError ?? false))
    <form method="POST" action="{{ route('verification.send') }}">@csrf
        <label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" required maxlength="255">
        <button class="login-submit" type="submit">Resend verification email</button>
    </form>
    @endif
    <p><a href="{{ route('login') }}">Back to Login</a></p>
</section>
@endcomponent
