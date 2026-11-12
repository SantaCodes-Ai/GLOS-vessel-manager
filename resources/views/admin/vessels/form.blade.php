@extends('layouts.admin')
@section('title', $vessel->exists ? 'Edit vessel' : 'Register vessel')
@section('content')
    <p class="eyebrow">Fleet management</p>
    <h1>{{ $vessel->exists ? 'Edit vessel' : 'Register vessel' }}</h1>
    <p class="welcome">Fields marked * are required.</p>
    @if ($errors->any())<div class="vessel-error-summary" role="alert"><strong>Please correct the following:</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ $vessel->exists ? route('admin.vessels.update', $vessel) : route('admin.vessels.store') }}" class="panel vessel-form">
        @csrf
        @if ($vessel->exists) @method('PUT') @endif
        <div class="vessel-form-grid">
            @foreach (['vessel_name' => 'Vessel name', 'vessel_type' => 'Vessel type', 'flag_state' => 'Flag state', 'imo_number' => 'IMO number', 'official_number' => 'Official number'] as $field => $label)
                <div>
                    <label for="{{ $field }}">{{ $label }}{{ in_array($field, ['vessel_name', 'vessel_type', 'flag_state']) ? ' *' : ' (optional)' }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="text" value="{{ is_scalar(old($field, $vessel->$field)) ? old($field, $vessel->$field) : '' }}"
                        maxlength="{{ $field === 'imo_number' ? 7 : 255 }}"
                        @required(in_array($field, ['vessel_name', 'vessel_type', 'flag_state']))
                        @if($field === 'imo_number') inputmode="numeric" pattern="[0-9]{7}" @endif
                        @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                    @if ($field === 'imo_number')<small>Seven digits, including the check digit. Leave blank if unavailable.</small>@endif
                    @error($field)<p id="{{ $field }}-error" class="vessel-error">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <div><label for="vessel_status">Vessel status *</label>
                <select id="vessel_status" name="vessel_status" required @error('vessel_status') aria-invalid="true" aria-describedby="vessel_status-error" @enderror>
                    <option value="active" @selected(old('vessel_status', $vessel->vessel_status) === 'active')>Active</option>
                    <option value="inactive" @selected(old('vessel_status', $vessel->vessel_status) === 'inactive')>Inactive</option>
                </select>
                @error('vessel_status')<p id="vessel_status-error" class="vessel-error">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="vessel-toolbar"><button type="submit" class="vessel-button">{{ $vessel->exists ? 'Save changes' : 'Register vessel' }}</button><a class="vessel-link" href="{{ $vessel->exists ? route('admin.vessels.show', $vessel) : route('admin.vessels.index') }}">Cancel</a></div>
    </form>
@endsection
