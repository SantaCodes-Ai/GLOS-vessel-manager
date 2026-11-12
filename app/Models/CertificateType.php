<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificateType extends Model
{
    protected $fillable = [
        'name',
        'category',
        'description',
        'default_validity_months',
    ];

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }
}