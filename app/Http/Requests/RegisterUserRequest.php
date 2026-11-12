<?php

namespace App\Http\Requests;

use App\Enums\RequestedAccessRole;
use App\Support\Departments;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return array_merge(Departments::rules(true), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => PasswordPolicy::creationRules(),
            'requested_role' => ['required', Rule::enum(RequestedAccessRole::class)],
            'role' => ['prohibited'], 'is_active' => ['prohibited'],
            'email_verified_at' => ['prohibited'], 'registration_status' => ['prohibited'],
            'website' => ['prohibited'],
        ]);
    }
}
