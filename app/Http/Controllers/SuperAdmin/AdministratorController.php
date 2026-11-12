<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ManagePrivileges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdministratorController extends Controller
{
    public function index(): View
    {
        return view('super-admin.administrators', [
            'administrators' => User::administrators()->orderBy('name')->paginate(15, ['id', 'name', 'email', 'role', 'department', 'is_active']),
            'audits' => DB::table('privilege_audits')->orderByDesc('id')->limit(25)->get(),
        ]);
    }

    public function edit(User $administrator): View
    {
        abort_unless(in_array($administrator->role, ['admin', 'client', 'super_admin'], true) && $administrator->hasApprovedIdentity(), 404);

        return view('super-admin.change', compact('administrator'));
    }

    public function update(Request $request, User $administrator, ManagePrivileges $privileges): RedirectResponse
    {
        abort_unless(in_array($administrator->role, ['admin', 'client', 'super_admin'], true) && $administrator->hasApprovedIdentity(), 404);
        $data = $request->validate([
            'action' => ['required', Rule::in(['promote', 'demote', 'activate', 'deactivate'])],
            'password' => ['required', 'string', 'current_password:web'],
            'confirm' => ['required', 'accepted'],
            'target_email' => ['required', 'string', Rule::in([$administrator->email])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'role' => ['prohibited'], 'is_active' => ['prohibited'],
        ]);
        $privileges->change($request->user()->id, $administrator->id, $data['action'], $data['password'], $data['reason'], $data['target_email']);
        $actor = $request->user()->fresh();
        if (! $actor->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login');
        }

        return to_route($actor->isAdmin() ? 'super-admin.administrators.index' : 'client.dashboard')
            ->with('success', 'The privileged change was saved and audited.');
    }
}
