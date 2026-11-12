<?php

namespace App\Models;

use App\Support\CertificateExpiry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Certificate extends Model
{
    protected $fillable = [
        'vessel_id',
        'certificate_type_id',
        'certificate_number',
        'remarks',
        'created_by',
    ];

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    public function certificateType(): BelongsTo
    {
        return $this->belongsTo(CertificateType::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CertificateVersion::class);
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(CertificateVersion::class)->ofMany('version_number', 'max');
    }

    public function expiry(): CertificateExpiry
    {
        return CertificateExpiry::forVersion($this->currentVersion);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
