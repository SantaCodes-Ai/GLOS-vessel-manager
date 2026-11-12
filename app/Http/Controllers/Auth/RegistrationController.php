<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterUserRequest;
use App\Models\User;
use App\Support\Departments;
use App\Support\RegistrationNotifications;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterUserRequest $request, RegistrationNotifications $notifications): RedirectResponse
    {
        $data = $request->validated();
        // Hash both new and duplicate submissions; use the same public response in either case.
        $hash = Hash::make($data['password']);
        $applicant = null;
        try {
            $applicant = DB::transaction(function () use ($data, $hash) {
                if (User::where('email', $data['email'])->exists()) {
                    return null;
                }
                $user = new User;
                $user->name = $data['name'];
                $user->email = $data['email'];
                $user->password = $hash;
                $user->role = 'client';
                $user->is_active = false;
                $user->requested_role = $data['requested_role'];
                $user->registration_status = 'pending';
                $user->department = Departments::storedValue($data);
                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent duplicate email: no account modification and no enumeration error.
        }
        if ($applicant) {
            $notifications->submitted($applicant);
        }

        return to_route('registration.notice')->with('status', 'If this address is eligible, a verification email will be sent. Verification and approval are required before sign in.');
    }

    public function notice(Request $request): View
    {
        $context = $request->session()->get('registration.notice_identity');
        $applicant = null;
        if (is_array($context) && isset($context['id'], $context['email_hash']) && is_string($context['email_hash'])) {
            $candidate = User::whereKey($context['id'])->whereNotNull('registration_status')->first();
            if ($candidate && hash_equals(sha1($candidate->getEmailForVerification()), $context['email_hash'])) {
                $applicant = $candidate;
            }
        } elseif ($request->user()) {
            $candidate = $request->user()->fresh();
            $applicant = $candidate?->registration_status !== null ? $candidate : null;
        }

        return view('auth.registration-notice', compact('applicant'));
    }

    public function verify(Request $request, string $id, string $hash): View
    {
        [$user, $newlyVerified] = DB::transaction(function () use ($id, $hash) {
            // Bind verification to the current email even during concurrent account edits.
            $user = User::whereKey($id)->whereIn('registration_status', ['pending', 'approved', 'rejected'])->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
            $newlyVerified = $user->registration_status !== 'rejected' && ! $user->hasVerifiedEmail();
            if ($newlyVerified) {
                $user->markEmailAsVerified();
            }

            return [$user, $newlyVerified];
        });
        if ($newlyVerified) {
            event(new Verified($user));
        }

        // Only a valid signed mailbox link establishes this identity; never trust notice URL parameters.
        $request->session()->put('registration.notice_identity', ['id' => $user->id, 'email_hash' => sha1($user->getEmailForVerification())]);

        return view('auth.registration-notice', ['applicant' => $user->fresh()]);
    }

    public function resend(Request $request, RegistrationNotifications $notifications): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $user = User::where('email', mb_strtolower(trim($data['email'])))->whereIn('registration_status', ['pending', 'approved'])->whereNull('email_verified_at')->first();
        if ($user) {
            $notifications->verification($user);
        }

        return to_route('registration.notice')->with('status', 'If an eligible unverified registration exists, a verification email will be sent.');
    }
}
