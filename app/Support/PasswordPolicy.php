<?php

namespace App\Support;

use App\Rules\PasswordBytes;
use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    public static function creationRules(): array
    {
        return ['bail', 'required', 'string', 'confirmed', new PasswordBytes,
            Password::min(12)->mixedCase()->numbers()->symbols(), Password::defaults()];
    }
}
