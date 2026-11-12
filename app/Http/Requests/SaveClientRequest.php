<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Departments;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');

        return $this->user()?->isAdmin() && $this->user()->is_active
            && (! $client || ($client instanceof User && $client->isManagedClient()));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $client = $this->route('client');

        return array_merge(Departments::rules(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($client instanceof User ? $client->id : null)],
            'is_active' => ['required', 'boolean'],
            'password' => $client ? ['prohibited'] : PasswordPolicy::creationRules(),
            'password_confirmation' => $client ? ['prohibited'] : ['required', 'string'],
            'vessel_ids' => ['sometimes', 'array', 'max:1000'],
            'vessel_ids.*' => ['required', 'integer', 'distinct', 'exists:vessels,id'],
            'role' => ['prohibited'],
            'remember_token' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
            'requested_role' => ['prohibited'], 'registration_status' => ['prohibited'],
        ]);
    }

    public function attributes(): array
    {
        return ['is_active' => 'account status', 'vessel_ids' => 'vessel assignments', 'vessel_ids.*' => 'selected vessel'];
    }
}
