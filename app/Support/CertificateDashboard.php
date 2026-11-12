<?php

namespace App\Support;

use App\Models\Certificate;
use Illuminate\Database\Eloquent\Builder;

class CertificateDashboard
{
    public const STATUSES = ['permanent', 'valid', 'expiring-soon', 'critical', 'expired', 'unknown'];

    public function filterByStatus(Builder $query, string $status): Builder
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unsupported certificate status.');
        }

        $ids = [];
        $this->scanCurrentVersions($query, function ($certificates) use ($status, &$ids) {
            foreach ($certificates as $certificate) {
                if ($certificate->expiry()->status === $status) {
                    $ids[] = $certificate->id;
                }
            }
        });

        // IDs come only from database records. Keep pagination/sorting in the existing list.
        return $query->whereIntegerInRaw('certificates.id', $ids);
    }

    private function scanCurrentVersions(Builder $query, callable $callback): void
    {
        (clone $query)->select('certificates.id')->with('currentVersion:id,certificate_id,version_number,expiry_type,expiry_date,issuance_date')
            ->chunkById(500, $callback);
    }

    public function summary(?Builder $query = null): array
    {
        $query ??= Certificate::query();
        $counts = array_fill_keys(self::STATUSES, 0);
        $attention = collect();
        // Bounded memory and two queries per batch, rather than a query per certificate.
        $this->scanCurrentVersions($query, function ($certificates) use (&$counts, &$attention) {
            foreach ($certificates as $certificate) {
                $expiry = $certificate->expiry();
                $counts[$expiry->status]++;
                if (in_array($expiry->status, ['expired', 'critical', 'expiring-soon'], true)) {
                    $attention->push(['id' => $certificate->id, 'date' => $expiry->date]);
                }
            }
            $attention = $attention->sortBy([['date', 'asc'], ['id', 'asc']])->take(10)->values();
        });
        $ids = $attention->pluck('id');
        $records = $ids->isEmpty() ? collect() : (clone $query)->whereIn('id', $ids)
            ->with(['currentVersion', 'vessel:id,vessel_name', 'certificateType:id,name'])->get()->keyBy('id');

        return ['statusCounts' => $counts, 'attentionCertificates' => $ids->map(fn ($id) => $records->get($id))->filter()];
    }
}
