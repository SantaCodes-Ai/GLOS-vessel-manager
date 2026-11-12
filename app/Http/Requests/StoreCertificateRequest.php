<?php

namespace App\Http\Requests;

use App\Rules\ValidPdfStructure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() && $this->user()->is_active;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('expiry_type') === 'permanent') {
            $this->merge(['expiry_date' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'vessel_id' => ['bail', 'required', 'integer', 'exists:vessels,id'],
            'certificate_type_id' => ['bail', 'required', 'integer', 'exists:certificate_types,id'],
            'certificate_number' => ['bail', 'nullable', 'string', 'max:255'],
            'issuance_date' => ['bail', 'nullable', 'string', 'date_format:Y-m-d'],
            'expiry_type' => ['bail', 'required', 'string', Rule::in(['fixed', 'permanent'])],
            'expiry_date' => [
                'bail', 'required_if:expiry_type,fixed', 'nullable', 'string', 'date_format:Y-m-d',
                Rule::when(is_string($this->input('issuance_date')) && $this->filled('issuance_date'), ['after_or_equal:issuance_date']),
            ],
            'remarks' => ['bail', 'nullable', 'string', 'max:10000'],
            'document' => [
                'bail', 'required', 'file', 'max:10240', 'mimes:pdf', 'mimetypes:application/pdf', 'extensions:pdf',
                new ValidPdfStructure,
            ],
        ];
    }

    public function attributes(): array
    {
        return ['vessel_id' => 'vessel', 'certificate_type_id' => 'certificate type', 'document' => 'PDF document'];
    }
}
