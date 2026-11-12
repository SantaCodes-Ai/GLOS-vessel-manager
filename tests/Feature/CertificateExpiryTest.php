<?php

use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\CertificateVersion;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CertificateDashboard;
use App\Support\CertificateExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['app.timezone' => 'Europe/London']);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 18:45:00', 'Europe/London'));
});

test('expiry uses inclusive calendar-day thresholds', function (int $days, string $expected) {
    $version = new CertificateVersion([
        'expiry_type' => 'fixed', 'issuance_date' => '2025-01-01',
        'expiry_date' => CarbonImmutable::today('Europe/London')->addDays($days)->format('Y-m-d'),
    ]);
    $version->syncOriginal();
    $expiry = CertificateExpiry::forVersion($version);
    expect($expiry->status)->toBe($expected)->and($expiry->days)->toBe($days);
})->with([
    'yesterday' => [-1, 'expired'],
    'today' => [0, 'critical'],
    '14 days' => [14, 'critical'],
    '15 days' => [15, 'expiring-soon'],
    '30 days' => [30, 'expiring-soon'],
    '31 days' => [31, 'valid'],
]);

test('permanent certificates are never expired even with stale expiry metadata', function () {
    $version = new CertificateVersion(['expiry_type' => 'permanent', 'expiry_date' => '2020-01-01']);
    $version->syncOriginal();
    $expiry = CertificateExpiry::forVersion($version);
    expect($expiry->status)->toBe('permanent')->and($expiry->days)->toBeNull()->and($expiry->date)->toBeNull();
});

test('missing or inconsistent expiry data is unknown', function (?array $attributes) {
    $version = $attributes === null ? null : (new CertificateVersion)->setRawAttributes($attributes, true);
    $expiry = CertificateExpiry::forVersion($version);
    expect($expiry->status)->toBe('unknown')->and($expiry->days)->toBeNull();
})->with([
    'no version' => [null],
    'missing fixed date' => [['expiry_type' => 'fixed', 'expiry_date' => null]],
    'unsupported type' => [['expiry_type' => 'other', 'expiry_date' => '2027-01-01']],
    'invalid date string' => [['expiry_type' => 'fixed', 'expiry_date' => 'invalid']],
    'impossible date' => [['expiry_type' => 'fixed', 'expiry_date' => '2027-02-30']],
    'expiry before issuance' => [['expiry_type' => 'fixed', 'expiry_date' => '2027-01-01', 'issuance_date' => '2027-02-01']],
    'invalid issuance' => [['expiry_type' => 'fixed', 'expiry_date' => '2027-01-01', 'issuance_date' => 'invalid']],
]);

test('expiry respects application timezone rather than UTC date or elapsed hours', function () {
    config(['app.timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 23:30:00', 'UTC'));
    $version = (new CertificateVersion(['expiry_type' => 'fixed', 'expiry_date' => '2026-10-30']))->syncOriginal();
    expect(CertificateExpiry::forVersion($version)->status)->toBe('critical')
        ->and(CertificateExpiry::forVersion($version)->days)->toBe(0);

    config(['app.timezone' => 'Europe/London']);
    $this->travelTo(CarbonImmutable::parse('2026-10-24 23:59:00', 'Europe/London'));
    $version = (new CertificateVersion(['expiry_type' => 'fixed', 'expiry_date' => '2026-11-08']))->syncOriginal();
    expect(CertificateExpiry::forVersion($version)->status)->toBe('expiring-soon')
        ->and(CertificateExpiry::forVersion($version)->days)->toBe(15);
});

test('dashboard counts current certificate records and excludes expired historical versions from attention', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $vessel = Vessel::create(['vessel_name' => 'Ocean Pioneer', 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
    $type = CertificateType::create(['name' => 'Safety', 'category' => 'Statutory']);
    $certificates = [];
    foreach (['expired' => -1, 'critical' => 0, 'expiring-soon' => 30, 'valid' => 31, 'permanent' => null, 'unknown' => null] as $status => $days) {
        $certificate = Certificate::create(['vessel_id' => $vessel->id, 'certificate_type_id' => $type->id]);
        $certificates[$status] = $certificate;
        if ($status !== 'unknown') {
            $certificate->versions()->create([
                'version_number' => 1, 'certificate_number' => $status,
                'expiry_type' => $status === 'permanent' ? 'permanent' : 'fixed',
                'expiry_date' => $days === null ? null : CarbonImmutable::today('Europe/London')->addDays($days)->format('Y-m-d'),
                'file_path' => 'unused.pdf', 'original_filename' => 'unused.pdf',
            ]);
        }
    }
    // This formerly expired certificate is now valid. Its oldest version is not counted.
    $renewed = $certificates['valid'];
    $renewed->versions()->first()->update(['expiry_date' => '2020-01-01']);
    $renewed->versions()->create([
        'version_number' => 2, 'certificate_number' => 'Renewed Valid', 'expiry_type' => 'fixed', 'expiry_date' => '2027-12-31',
        'file_path' => 'unused-renewal.pdf', 'original_filename' => 'unused.pdf',
    ]);
    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
        ->assertViewHas('totalCertificates', 6)
        ->assertViewHas('statusCounts', ['permanent' => 1, 'valid' => 1, 'expiring-soon' => 1, 'critical' => 1, 'expired' => 1, 'unknown' => 1])
        ->assertViewHas('attentionCertificates', fn ($rows) => $rows->pluck('id')->all() === [$certificates['expired']->id, $certificates['critical']->id, $certificates['expiring-soon']->id]);
    $this->get(route('admin.certificates.index'))->assertOk()->assertSee('Version 2')->assertSee('Renewed Valid')->assertSee('Valid');
    $this->get(route('admin.certificates.show', $renewed))->assertOk()->assertSee('Valid')->assertSee('Historical');
    expect($renewed->fresh()->expiry()->status)->toBe('valid');
});

test('dashboard attention is bounded to ten earliest expiries without per-certificate queries', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $vessel = Vessel::create(['vessel_name' => 'Ocean Pioneer', 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
    $type = CertificateType::create(['name' => 'Safety', 'category' => 'Statutory']);
    for ($index = 0; $index < 12; $index++) {
        $certificate = Certificate::create(['vessel_id' => $vessel->id, 'certificate_type_id' => $type->id]);
        $certificate->versions()->create([
            'version_number' => 1, 'expiry_type' => 'fixed', 'expiry_date' => '2026-10-28',
            'file_path' => 'unused.pdf', 'original_filename' => 'unused.pdf',
        ]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $summary = app(CertificateDashboard::class)->summary();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($summary['statusCounts']['expired'])->toBe(12)
        ->and($summary['attentionCertificates'])->toHaveCount(10)
        ->and($queryCount)->toBeLessThanOrEqual(6);
    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
});

test('malformed legacy dates render safely as unknown on every certificate screen', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $vessel = Vessel::create(['vessel_name' => 'Ocean Pioneer', 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
    $type = CertificateType::create(['name' => 'Safety', 'category' => 'Statutory']);
    $certificate = Certificate::create(['vessel_id' => $vessel->id, 'certificate_type_id' => $type->id]);
    $version = $certificate->versions()->create([
        'version_number' => 1, 'expiry_type' => 'fixed', 'expiry_date' => '2027-01-01',
        'file_path' => 'unused.pdf', 'original_filename' => 'unused.pdf',
    ]);
    // Simulate inconsistent legacy data without passing through Eloquent's date setter.
    DB::table('certificate_versions')->where('id', $version->id)->update(['expiry_date' => 'invalid']);
    $this->actingAs($admin)->get(route('admin.certificates.show', $certificate))->assertOk()->assertSee('Unknown');
    $this->get(route('admin.certificates.index'))->assertOk()->assertSee('Unknown');
    $this->get(route('admin.dashboard'))->assertOk()
        ->assertViewHas('statusCounts', fn ($counts) => $counts['unknown'] === 1 && $counts['valid'] === 0);
});
