<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveClientRequest;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Departments;
use App\Support\RegistrationNotifications;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = $filters['search'] ?? '';
        $clients = User::where('role', 'client')->select(['id', 'name', 'email', 'department', 'is_active', 'created_at'])
            ->where(fn ($query) => $query->whereNull('registration_status')->orWhere('registration_status', 'approved'))
            ->withCount('vessels')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%')))
            ->latest()->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.clients.index', compact('clients', 'search'));
    }

    public function create(): View
    {
        return $this->form(new User(['name' => '', 'email' => '']));
    }

    public function store(SaveClientRequest $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function show(User $client): View
    {
        abort_unless($client->isManagedClient(), 404);
        $vessels = $client->vessels()->orderBy('vessel_name')->paginate(15);

        return view('admin.clients.show', compact('client', 'vessels'));
    }

    public function edit(User $client): View
    {
        abort_unless($client->isManagedClient(), 404);

        return $this->form($client);
    }

    public function update(SaveClientRequest $request, User $client): RedirectResponse
    {
        return $this->save($request, $client);
    }

    private function form(User $client): View
    {
        return view('admin.clients.form', [
            'client' => $client,
            'vessels' => Vessel::orderBy('vessel_name')->get(['id', 'vessel_name', 'imo_number', 'vessel_status']),
            'assignedIds' => $client->exists ? $client->vessels()->pluck('vessels.id')->all() : [],
        ]);
    }

    private function save(SaveClientRequest $request, ?User $client = null): RedirectResponse
    {
        $data = $request->validated();
        try {
            $saved = DB::transaction(function () use ($request, $data, $client) {
                $user = $client
                    ? User::where('role', 'client')->whereKey($client->id)
                        ->where(fn ($query) => $query->whereNull('registration_status')->orWhere('registration_status', 'approved'))
                        ->lockForUpdate()->firstOrFail()
                    : new User;
                $user->name = $data['name'];
                $user->email = $data['email'];
                if (array_key_exists('department', $data)) {
                    $user->department = Departments::storedValue($data);
                }
                $user->is_active = $request->boolean('is_active');
                if (! $client) {
                    $user->role = 'client';
                    $user->password = Hash::make($data['password']);
                }
                $user->save();
                $user->vessels()->sync($data['vessel_ids'] ?? []);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'An account with this email address already exists.']);
        } catch (Throwable $exception) {
            Log::error('Client account save failed.', ['exception_type' => $exception::class]);

            return back()->withInput($request->except(['password', 'password_confirmation', '_token']))
                ->withErrors(['name' => 'The staff account could not be saved. Please try again.']);
        }

        if ($client && $client->email !== $saved->email && $saved->registration_status === 'approved') {
            app(RegistrationNotifications::class)->verification($saved);
        }

        return to_route('admin.clients.show', $saved)->with('success', $client ? 'Staff account updated successfully.' : 'Staff account created successfully.');
    }
}
