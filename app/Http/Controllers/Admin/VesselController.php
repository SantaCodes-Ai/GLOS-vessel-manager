<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveVesselRequest;
use App\Models\Vessel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VesselController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? '';

        $vessels = Vessel::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('vessel_name', '%'.$search.'%')
                ->orWhereLike('imo_number', '%'.$search.'%')))
            ->when($status !== '', fn ($query) => $query->where('vessel_status', $status))
            ->latest()->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.vessels.index', compact('vessels', 'search', 'status'));
    }

    public function create(): View
    {
        return view('admin.vessels.form', ['vessel' => new Vessel(['vessel_status' => 'active'])]);
    }

    public function store(SaveVesselRequest $request): RedirectResponse
    {
        try {
            $vessel = Vessel::create($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['imo_number' => 'This IMO number is already registered.']);
        }

        return to_route('admin.vessels.show', $vessel)->with('success', 'Vessel registered successfully.');
    }

    public function show(Vessel $vessel): View
    {
        return view('admin.vessels.show', compact('vessel'));
    }

    public function edit(Vessel $vessel): View
    {
        return view('admin.vessels.form', compact('vessel'));
    }

    public function update(SaveVesselRequest $request, Vessel $vessel): RedirectResponse
    {
        try {
            $vessel->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['imo_number' => 'This IMO number is already registered.']);
        }

        return to_route('admin.vessels.show', $vessel)->with('success', 'Vessel updated successfully.');
    }
}
