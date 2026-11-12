<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Departments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = $data['search'] ?? '';
        $users = User::query()->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%')))
            ->orderBy('name')->orderBy('id')->paginate(15, ['id', 'name', 'email', 'role', 'department', 'registration_status', 'email_verified_at', 'is_active'])->withQueryString();

        return view('admin.users.index', compact('users', 'search'));
    }

    public function edit(User $user): View
    {
        return view('admin.users.department', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(array_merge(Departments::rules(), ['department' => ['present', 'nullable', 'string', Rule::in(Departments::options())], 'role' => ['prohibited'], 'is_active' => ['prohibited']]));
        DB::transaction(function () use ($request, $user, $data) {
            abort_unless(DB::table('privilege_management_locks')->where('id', 1)->lockForUpdate()->first(), 503);
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isAdmin() && $actor->canAccessApplication(), 403);
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $target->department = Departments::storedValue($data);
            $target->save();
        }, 3);

        return to_route('admin.users.index')->with('success', 'Department updated.');
    }
}
