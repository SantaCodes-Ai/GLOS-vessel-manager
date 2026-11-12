<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class Departments
{
    public static function options(): array
    {
        return ['Information Technology', 'Human Resources', 'Operations', 'Marine Operations', 'Health, Safety & Environment', 'Security', 'Finance / Accounts', 'Business Development', 'Other'];
    }

    public static function rules(bool $required = false): array
    {
        return [
            'department' => [$required ? 'required' : 'sometimes', 'nullable', 'string', Rule::in(self::options())],
            'department_other' => ['exclude_unless:department,Other', 'required_if:department,Other', 'string', 'max:255'],
        ];
    }

    public static function storedValue(array $data): ?string
    {
        return ($data['department'] ?? null) === 'Other' ? trim($data['department_other']) : ($data['department'] ?? null);
    }
}
