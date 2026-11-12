            @foreach (['certificate_number' => ['Certificate number (optional)', 'text'], 'issuance_date' => ['Issuance date (optional)', 'date']] as $field => [$label, $type])
                <div><label for="{{ $field }}">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" value="{{ old($field) }}" @if($type === 'text') maxlength="255" @endif @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                    @error($field)<p class="vessel-error" id="{{ $field }}-error">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <div><label for="expiry_type">Expiry type *</label>
                <select id="expiry_type" name="expiry_type" required @error('expiry_type') aria-invalid="true" aria-describedby="expiry_type-error" @enderror>
                    <option value="fixed" @selected(old('expiry_type', 'fixed') === 'fixed')>Fixed expiry</option>
                    <option value="permanent" @selected(old('expiry_type') === 'permanent')>Permanent</option>
                </select>
                @error('expiry_type')<p class="vessel-error" id="expiry_type-error">{{ $message }}</p>@enderror
            </div>
            <div><label for="expiry_date">Expiry date (required for fixed expiry)</label>
                <input id="expiry_date" name="expiry_date" type="date" value="{{ old('expiry_date') }}" aria-describedby="expiry-help @error('expiry_date') expiry_date-error @enderror" @error('expiry_date') aria-invalid="true" @enderror>
                <small id="expiry-help">Leave blank for permanent certificates. Any supplied date is discarded for permanent certificates.</small>
                @error('expiry_date')<p class="vessel-error" id="expiry_date-error">{{ $message }}</p>@enderror
            </div>
            <div><label for="document">PDF document *</label>
                <input id="document" name="document" type="file" accept="application/pdf,.pdf" required aria-describedby="document-help @error('document') document-error @enderror" @error('document') aria-invalid="true" @enderror>
                <small id="document-help">PDF only, maximum 10 MB. Stored privately.</small>
                @error('document')<p class="vessel-error" id="document-error">{{ $message }}</p>@enderror
            </div>
