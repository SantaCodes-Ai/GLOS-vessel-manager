@php
    $options = \App\Support\Departments::options();
    $value = $departmentValue ?? null;
    $choice = $value === null ? '' : (in_array($value, $options, true) ? $value : 'Other');
    $selected = old('department', $choice);
    $custom = old('department_other', $choice === 'Other' ? $value : '');
@endphp
<div class="department-fields">
    <label for="department">Department {{ ($departmentRequired ?? false) ? '*' : '(optional)' }}</label>
    <select id="department" name="department" @required($departmentRequired ?? false) onchange="document.getElementById('department-other-field').hidden = this.value !== 'Other'">
        <option value="">Select department</option>@foreach($options as $option)<option value="{{ $option }}" @selected($selected === $option)>{{ $option }}</option>@endforeach
    </select>
    @error('department')<p class="vessel-error" role="alert">{{ $message }}</p>@enderror
    <div id="department-other-field" @if($selected !== 'Other') hidden @endif><label for="department_other">Custom department name</label><input id="department_other" name="department_other" type="text" maxlength="255" value="{{ is_string($custom) ? $custom : '' }}"></div>
    @error('department_other')<p class="vessel-error" role="alert">{{ $message }}</p>@enderror
    <noscript><p>Choose Other and submit to reveal the custom department field.</p></noscript>
</div>
