<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCertificateTypeRequest;
use App\Models\CertificateType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CertificateTypeController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $search = $filters['search'] ?? '';

        $certificateTypes = CertificateType::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('name', '%'.$search.'%')
                ->orWhereLike('category', '%'.$search.'%')))
            ->latest()->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.certificate-types.index', compact('certificateTypes', 'search'));
    }

    public function create(): View
    {
        return view('admin.certificate-types.form', ['certificateType' => new CertificateType]);
    }

    public function store(SaveCertificateTypeRequest $request): RedirectResponse
    {
        try {
            $certificateType = CertificateType::create($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'A certificate type with this name already exists.']);
        }

        return to_route('admin.certificate-types.show', $certificateType)->with('success', 'Certificate type registered successfully.');
    }

    public function show(CertificateType $certificateType): View
    {
        return view('admin.certificate-types.show', compact('certificateType'));
    }

    public function edit(CertificateType $certificateType): View
    {
        return view('admin.certificate-types.form', compact('certificateType'));
    }

    public function update(SaveCertificateTypeRequest $request, CertificateType $certificateType): RedirectResponse
    {
        try {
            $certificateType->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'A certificate type with this name already exists.']);
        }

        return to_route('admin.certificate-types.show', $certificateType)->with('success', 'Certificate type updated successfully.');
    }
}
