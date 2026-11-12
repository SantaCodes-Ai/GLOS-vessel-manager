@extends('layouts.admin')
@section('title', 'Register certificate')
@section('content')
    <p class="eyebrow">Certificate register</p>
    <h1>Register certificate</h1>
    <p class="welcome">Fields marked * are required. The uploaded PDF is stored privately as version 1.</p>
    @if ($vessels->isEmpty() || $certificateTypes->isEmpty())
        <div class="vessel-error-summary" role="status">Register at least one <a class="vessel-link" href="{{ route('admin.vessels.index') }}">vessel</a> and one <a class="vessel-link" href="{{ route('admin.certificate-types.index') }}">certificate type</a> before registering a certificate.</div>
    @endif
    @if ($errors->any())<div class="vessel-error-summary" role="alert"><strong>Please correct the following:</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul><p>Please select the PDF again before submitting.</p></div>@endif
    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.certificates.store') }}" class="panel vessel-form">
        @csrf
        <div class="vessel-form-grid">
            <div><label for="vessel_id">Vessel *</label>
                <select id="vessel_id" name="vessel_id" required @error('vessel_id') aria-invalid="true" aria-describedby="vessel_id-error" @enderror>
                    <option value="">Select a vessel</option>
                    @foreach ($vessels as $vessel)<option value="{{ $vessel->id }}" @selected((string) old('vessel_id') === (string) $vessel->id)>{{ $vessel->vessel_name }}{{ $vessel->imo_number ? ' · IMO '.$vessel->imo_number : '' }}</option>@endforeach
                </select>
                @error('vessel_id')<p class="vessel-error" id="vessel_id-error">{{ $message }}</p>@enderror
            </div>
            <div><label for="certificate_type_id">Certificate type *</label>
                <select id="certificate_type_id" name="certificate_type_id" required @error('certificate_type_id') aria-invalid="true" aria-describedby="certificate_type_id-error" @enderror>
                    <option value="">Select a certificate type</option>
                    @foreach ($certificateTypes as $type)<option value="{{ $type->id }}" @selected((string) old('certificate_type_id') === (string) $type->id)>{{ $type->name }}</option>@endforeach
                </select>
                @error('certificate_type_id')<p class="vessel-error" id="certificate_type_id-error">{{ $message }}</p>@enderror
            </div>
            @include('admin.certificates.version-fields')
            <div><label for="remarks">Remarks (optional)</label>
                <textarea id="remarks" class="certificate-description" name="remarks" maxlength="10000" rows="5" @error('remarks') aria-invalid="true" aria-describedby="remarks-error" @enderror>{{ old('remarks') }}</textarea>
                @error('remarks')<p class="vessel-error" id="remarks-error">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="vessel-toolbar"><button type="submit" class="vessel-button" @disabled($vessels->isEmpty() || $certificateTypes->isEmpty())>Register Certificate</button><a class="vessel-link" href="{{ route('admin.certificates.index') }}">Cancel</a></div>
    </form>
@endsection
