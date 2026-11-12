<?php

namespace App\Support;

use App\Models\CertificateVersion;
use Carbon\CarbonImmutable;
use Throwable;

final readonly class CertificateExpiry
{
    public function __construct(public string $status, public ?int $days = null, public ?string $date = null) {}

    public static function forVersion(?CertificateVersion $version): self
    {
        if (! $version) {
            return new self('unknown');
        }
        if ($version->expiry_type === 'permanent') {
            return new self('permanent');
        }
        if ($version->expiry_type !== 'fixed') {
            return new self('unknown');
        }

        try {
            $raw = $version->getAttributes()['expiry_date'] ?? null;
            $expiry = self::date($raw);
            $issuance = $version->getAttributes()['issuance_date'] ?? null;
            if (! $expiry || ($issuance !== null && (! self::date($issuance) || self::date($issuance)->greaterThan($expiry)))) {
                return new self('unknown');
            }
            $days = (int) CarbonImmutable::today(config('app.timezone'))->diffInDays($expiry, false);
        } catch (Throwable) {
            return new self('unknown');
        }

        return new self(match (true) {
            $days < 0 => 'expired',
            $days <= 14 => 'critical',
            $days <= 30 => 'expiring-soon',
            default => 'valid',
        }, $days, $expiry->format('Y-m-d'));
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        // Eloquent can serialize a date cast at midnight; MySQL DATE returns date-only.
        if (! is_string($value) || ! preg_match('/\A(\d{4}-\d{2}-\d{2})(?: 00:00:00)?\z/', $value, $matches)) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $matches[1], config('app.timezone'));

        return $date && $date->format('Y-m-d') === $matches[1] ? $date : null;
    }

    public function label(): string
    {
        return match ($this->status) {
            'expiring-soon' => 'Expiring Soon',
            default => ucfirst($this->status),
        };
    }
}
