<?php

namespace App\Http\Requests;

use App\Models\CertificateType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCertificateTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() && $this->user()->is_active;
    }

    public function rules(): array
    {
        $certificateType = $this->route('certificateType');

        return [
            'name' => ['bail', 'required', 'string', 'max:255', Rule::unique('certificate_types', 'name')->ignore($certificateType instanceof CertificateType ? $certificateType->id : null)],
            'category' => ['bail', 'required', 'string', 'max:255'],
            'description' => ['bail', 'nullable', 'string', 'max:10000'],
            'default_validity_months' => ['bail', 'nullable', 'integer', 'min:1', 'max:4294967295'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'certificate name', 'default_validity_months' => 'default validity months'];
    }
}
