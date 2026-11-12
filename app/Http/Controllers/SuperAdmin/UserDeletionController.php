<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DeleteUser;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserDeletionController extends Controller
{
    public function confirm(Request $request, User $user): View
    {
        abort_unless(in_array($user->role, ['admin', 'client', 'super_admin'], true) && $request->user()->id !== $user->id, 403);

        $request->session()->put('user_deletion.'.$user->id, ['actor_id' => $request->user()->id, 'fingerprint' => DeleteUser::fingerprint($user), 'expires_at' => now()->addMinutes(10)->timestamp]);

        return view('super-admin.delete-user', ['target' => $user]);
    }

    public function destroy(Request $request, User $user, DeleteUser $deletion): RedirectResponse
    {
        abort_unless(in_array($user->role, ['admin', 'client', 'super_admin'], true) && $request->user()->id !== $user->id, 403);
        $request->validate(['confirmation' => ['required', 'accepted']]);
        $intent = $request->session()->get('user_deletion.'.$user->id);
        if (! is_array($intent) || ($intent['actor_id'] ?? null) !== $request->user()->id || ($intent['expires_at'] ?? 0) < now()->timestamp || ! is_string($intent['fingerprint'] ?? null)) {
            throw ValidationException::withMessages(['confirmation' => 'Review the account and confirm deletion again.']);
        }
        try {
            $deletion->delete($request->user()->id, $user->id, $intent['fingerprint']);
            $request->session()->forget('user_deletion.'.$user->id);
        } catch (QueryException $exception) {
            Log::error('Permanent user deletion failed.', ['actor_user_id' => $request->user()->id, 'target_user_id' => $user->id, 'exception_type' => $exception::class]);

            return back()->withErrors(['confirmation' => 'Deletion could not be completed. Review the account and dependent records before retrying.']);
        }

        return to_route('super-admin.administrators.index')->with('success', 'User permanently deleted. The email address is available for registration again. Security audit history was retained.');
    }
}
