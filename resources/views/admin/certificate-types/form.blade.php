@extends('layouts.admin')
@section('title', $certificateType->exists ? 'Edit certificate type' : 'Register certificate type')
@section('content')
    <p class="eyebrow">Certificate catalogue</p>
    <h1>{{ $certificateType->exists ? 'Edit certificate type' : 'Register certificate type' }}</h1>
    <p class="welcome">Fields marked * are required.</p>
    @if ($errors->any())<div class="vessel-error-summary" role="alert"><strong>Please correct the following:</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ $certificateType->exists ? route('admin.certificate-types.update', $certificateType) : route('admin.certificate-types.store') }}" class="panel vessel-form">
        @csrf
        @if ($certificateType->exists) @method('PUT') @endif
        <div class="vessel-form-grid">
            @foreach (['name' => 'Certificate name', 'category' => 'Category'] as $field => $label)
                <div>
                    <label for="{{ $field }}">{{ $label }} *</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="text" value="{{ is_scalar(old($field, $certificateType->$field)) ? old($field, $certificateType->$field) : '' }}" maxlength="255" required
                        @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                    @error($field)<p id="{{ $field }}-error" class="vessel-error">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <div>
                <label for="default_validity_months">Default validity months (optional)</label>
                <input id="default_validity_months" name="default_validity_months" type="number" min="1" max="4294967295" step="1" value="{{ is_scalar(old('default_validity_months', $certificateType->default_validity_months)) ? old('default_validity_months', $certificateType->default_validity_months) : '' }}"
                    aria-describedby="validity-help @error('default_validity_months') validity-error @enderror" @error('default_validity_months') aria-invalid="true" @enderror>
                <small id="validity-help">Enter a positive whole number of months, or leave blank if unspecified.</small>
                @error('default_validity_months')<p id="validity-error" class="vessel-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="description">Description (optional)</label>
                <textarea id="description" class="certificate-description" name="description" maxlength="10000" rows="5"
                    @error('description') aria-invalid="true" aria-describedby="description-error" @enderror>{{ is_scalar(old('description', $certificateType->description)) ? old('description', $certificateType->description) : '' }}</textarea>
                @error('description')<p id="description-error" class="vessel-error">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="vessel-toolbar"><button type="submit" class="vessel-button">{{ $certificateType->exists ? 'Save changes' : 'Register certificate type' }}</button><a class="vessel-link" href="{{ $certificateType->exists ? route('admin.certificate-types.show', $certificateType) : route('admin.certificate-types.index') }}">Cancel</a></div>
    </form>
@endsection
