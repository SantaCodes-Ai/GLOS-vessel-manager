<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateVersion extends Model
{
    protected $fillable = [
        'certificate_id',
        'version_number',
        'certificate_number',
        'issuance_date',
        'expiry_date',
        'expiry_type',
        'file_path',
        'original_filename',
        'uploaded_by',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'issuance_date' => 'date',
            'expiry_date' => 'date',
            'uploaded_at' => 'datetime',
        ];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}