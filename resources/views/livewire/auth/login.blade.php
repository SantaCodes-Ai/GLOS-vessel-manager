<section>
    <h1 class="mb-6 text-2xl font-semibold">Sign in</h1>
    <form wire:submit="login" class="space-y-4">
        <div>
            <label for="email" class="block">Email address</label>
            <input id="email" type="email" wire:model="email" autocomplete="username" required autofocus
                class="w-full rounded border bg-white p-2" aria-describedby="email-error">
            @error('email') <p id="email-error" role="alert" class="text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password" class="block">Password</label>
            <input id="password" type="password" wire:model="password" autocomplete="current-password" required
                class="w-full rounded border bg-white p-2" aria-describedby="password-error">
            @error('password') <p id="password-error" role="alert" class="text-red-700">{{ $message }}</p> @enderror
        </div>
        <button type="submit" wire:loading.attr="disabled" wire:target="login" class="login-submit">
            <span wire:loading.remove wire:target="login">Log In</span>
            <span wire:loading wire:target="login" role="status">Logging in…</span>
        </button>
    </form>
    <p><a href="{{ route('register') }}">Request an account</a></p>
</section>
