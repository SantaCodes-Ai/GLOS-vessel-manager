<?php

namespace App\Rules;

use App\Support\PdfStructure;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ValidPdfStructure implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! app(PdfStructure::class)->valid($value)) {
            $fail('The document must be a readable, structurally valid PDF. Please export it again and retry.');
        }
    }
}
