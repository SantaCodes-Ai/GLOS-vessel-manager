<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.auth')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(): void
    {
        $this->email = mb_strtolower(trim($this->email));
        $key = 'login:'.hash('sha256', $this->email.'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->reset('password');

            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password, 'is_active' => true])) {
            RateLimiter::hit($key, 60);
            $this->reset('password');

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        session()->regenerate();
        $this->reset('password');

        $user = Auth::user();

        if (! $user->canAccessApplication()) {
            RateLimiter::hit($key, 60);
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->isAdmin() && ! $user->isClient()) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
            abort(403);
        }

        RateLimiter::clear($key);
        $this->redirectRoute($user->isAdmin() ? 'admin.dashboard' : 'client.dashboard');
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
