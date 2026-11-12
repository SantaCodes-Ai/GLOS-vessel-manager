@component('layouts.auth')
<section class="auth-form">
    <h1>Request an account</h1><p>Verify your email, then await Administrator approval. No access is granted before approval.</p>
    @if($errors->any())<div class="auth-errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('register.store') }}">
        @csrf
        @foreach(['name' => ['Full name', 'text', 'name'], 'email' => ['Email address', 'email', 'email']] as $field => [$label, $type, $autocomplete])
            <label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" maxlength="255" required value="{{ is_scalar(old($field)) ? old($field) : '' }}" @error($field) aria-invalid="true" @enderror>
        @endforeach
        <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" required aria-describedby="password-help">
        <small id="password-help">At least 12 characters with upper/lowercase letters, a number and a symbol. Maximum 72 bytes; non-ASCII characters may use multiple bytes.</small>
        <label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        <label for="requested_role">Requested access</label><select id="requested_role" name="requested_role" required><option value="">Select requested access</option><option value="client" @selected(old('requested_role') === 'client')>Staff</option><option value="admin" @selected(old('requested_role') === 'admin')>Administrator</option></select>
        @include('shared.department-fields', ['departmentRequired' => true])
        <div hidden aria-hidden="true"><label for="website">Leave blank</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <button class="login-submit" type="submit">Create Account</button>
    </form>
    <p><a href="{{ route('login') }}">Back to Login</a></p>
</section>
@endcomponent
