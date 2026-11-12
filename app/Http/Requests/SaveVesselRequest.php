<?php

namespace App\Http\Requests;

use App\Models\Vessel;
use App\Rules\ImoNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVesselRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() && $this->user()->is_active;
    }

    public function rules(): array
    {
        $vessel = $this->route('vessel');

        return [
            'vessel_name' => ['bail', 'required', 'string', 'max:255'],
            'vessel_type' => ['bail', 'required', 'string', 'max:255'],
            'flag_state' => ['bail', 'required', 'string', 'max:255'],
            'imo_number' => ['bail', 'nullable', 'string', new ImoNumber, Rule::unique('vessels', 'imo_number')->ignore($vessel instanceof Vessel ? $vessel->id : null)],
            'official_number' => ['bail', 'nullable', 'string', 'max:255'],
            'vessel_status' => ['bail', 'required', 'string', Rule::in(['active', 'inactive'])],
        ];
    }

    public function attributes(): array
    {
        return ['vessel_name' => 'vessel name', 'vessel_type' => 'vessel type', 'flag_state' => 'flag state', 'imo_number' => 'IMO number', 'vessel_status' => 'vessel status'];
    }
}
