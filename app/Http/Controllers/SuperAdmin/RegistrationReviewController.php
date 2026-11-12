<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DecideRegistration;
use App\Support\RegistrationNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegistrationReviewController extends Controller
{
    public function index(): View
    {
        return view('super-admin.registrations', [
            'applicants' => User::whereNotNull('registration_status')->orderByRaw("CASE WHEN registration_status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('id')
                ->paginate(15, ['id', 'name', 'email', 'department', 'requested_role', 'registration_status', 'email_verified_at', 'created_at']),
        ]);
    }

    public function show(User $applicant): View
    {
        abort_if($applicant->registration_status === null, 404);

        return view('super-admin.registration', ['applicant' => $applicant, 'decision' => DB::table('registration_decisions')->where('user_id', $applicant->id)->first()]);
    }

    public function decide(Request $request, User $applicant, DecideRegistration $decisions, RegistrationNotifications $notifications): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'role' => ['prohibited'], 'requested_role' => ['prohibited'], 'is_active' => ['prohibited'],
        ]);
        $user = $decisions->decide($request->user()->id, $applicant->id, $data['decision']);
        $notifications->decided($user);

        return to_route('super-admin.registrations.show', $user)->with('success', 'The registration decision was saved and audited. Notification delivery is processed separately.');
    }
}
