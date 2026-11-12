@extends('layouts.admin')
@section('title', $client->exists ? 'Edit staff member' : 'Create staff member')
@section('content')
    <p class="eyebrow">Staff management</p>
    <h1>{{ $client->exists ? 'Edit staff member' : 'Create staff member' }}</h1>
    <p class="welcome">Fields marked * are required. Accounts created here always have Staff access.</p>
    @if($errors->any())<div class="vessel-error-summary" role="alert"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ $client->exists ? route('admin.clients.update', $client) : route('admin.clients.store') }}" class="panel vessel-form">
        @csrf
        @if($client->exists) @method('PUT') @endif
        <input type="hidden" name="_client_form" value="1">
        <div class="vessel-form-grid">
            @foreach(['name' => ['Name', 'text'], 'email' => ['Email address', 'email']] as $field => [$label, $type])
                <div><label for="{{ $field }}">{{ $label }} *</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" value="{{ is_scalar(old($field, $client->$field)) ? old($field, $client->$field) : '' }}" maxlength="255" required @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                    @error($field)<p id="{{ $field }}-error" class="vessel-error">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <div><label for="is_active">Account status *</label>
                @php
                    $activeValue = old('is_active', $client->exists ? (int) $client->is_active : 1);
                @endphp
                <select id="is_active" name="is_active" required @error('is_active') aria-invalid="true" aria-describedby="is_active-error" @enderror>
                    <option value="1" @selected(is_scalar($activeValue) && (string) $activeValue === '1')>Active</option>
                    <option value="0" @selected(is_scalar($activeValue) && (string) $activeValue === '0')>Inactive</option>
                </select>
                @error('is_active')<p id="is_active-error" class="vessel-error">{{ $message }}</p>@enderror
            </div>
            @unless($client->exists)
                @foreach(['password' => 'Password', 'password_confirmation' => 'Confirm password'] as $field => $label)
                    <div><label for="{{ $field }}">{{ $label }} *</label>
                        <input id="{{ $field }}" name="{{ $field }}" type="password" autocomplete="new-password" required @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                        @error($field)<p id="{{ $field }}-error" class="vessel-error">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            @endunless
        </div>
        @include('shared.department-fields', ['departmentValue' => $client->department])
        <fieldset class="client-vessel-assignments">
            <legend>Assigned vessels</legend>
            <p>Select all vessels this staff member should be assigned to. Clearing every checkbox removes all assignments.</p>
            @php
                $selections = session()->hasOldInput('_client_form') ? old('vessel_ids', []) : $assignedIds;
                $selectedIds = is_array($selections) ? array_map('strval', array_filter($selections, 'is_scalar')) : [];
            @endphp
            @if($vessels->isEmpty())<p>No vessels available. You can save the staff member and assign vessels later.</p>@endif
            <div class="client-vessel-options">
                @foreach($vessels as $vessel)
                    <label class="client-vessel-option"><input type="checkbox" name="vessel_ids[]" value="{{ $vessel->id }}" @checked(in_array((string) $vessel->id, $selectedIds, true))>
                        <span>{{ $vessel->vessel_name }}{{ $vessel->imo_number ? ' · IMO '.$vessel->imo_number : '' }} · {{ ucfirst($vessel->vessel_status) }}</span>
                    </label>
                @endforeach
            </div>
            @foreach($errors->get('vessel_ids*') as $messages) @foreach($messages as $message)<p class="vessel-error">{{ $message }}</p>@endforeach @endforeach
        </fieldset>
        <div class="vessel-toolbar"><button class="vessel-button" type="submit">{{ $client->exists ? 'Save changes' : 'Create staff member' }}</button><a class="vessel-link" href="{{ $client->exists ? route('admin.clients.show', $client) : route('admin.clients.index') }}">Cancel</a></div>
    </form>
@endsection
