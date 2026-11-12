<?php

use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CertificateDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function navigationCertificate(Vessel $vessel, CertificateType $type, ?array $version): Certificate
{
    $certificate = Certificate::create(['vessel_id' => $vessel->id, 'certificate_type_id' => $type->id]);
    if ($version !== null) {
        $certificate->versions()->create(array_replace([
            'version_number' => 1, 'expiry_type' => 'fixed', 'issuance_date' => '2019-01-01',
            'file_path' => 'private/navigation-fixture.pdf', 'original_filename' => 'fixture.pdf',
        ], $version));
    }

    return $certificate;
}

function dashboardCardLinks(string $html): array
{
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $links = [];
    foreach ((new DOMXPath($document))->query('//a[@class="summary-card-link"]') as $link) {
        $links[$link->getAttribute('aria-label')] = $link->getAttribute('href');
    }

    return $links;
}

beforeEach(function () {
    config(['app.timezone' => 'Europe/London']);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00:00', 'Europe/London'));
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->vessel = Vessel::create(['vessel_name' => 'Fleet Match', 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
    $this->type = CertificateType::create(['name' => 'Safety', 'category' => 'Statutory']);
});

test('total cards navigate to the complete vessel and certificate registers', function () {
    Vessel::create(['vessel_name' => 'Inactive Vessel', 'vessel_type' => 'Cargo', 'flag_state' => 'UK', 'vessel_status' => 'inactive']);
    $certificate = navigationCertificate($this->vessel, $this->type, ['expiry_type' => 'permanent']);
    navigationCertificate($this->vessel, $this->type, null);
    $certificate->versions()->create(['version_number' => 2, 'expiry_type' => 'permanent', 'file_path' => 'private/new.pdf', 'original_filename' => 'new.pdf']);
    $dashboard = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
        ->assertViewHas('totalVessels', 2)->assertViewHas('totalCertificates', 2);
    $links = dashboardCardLinks($dashboard->getContent());
    expect($links['View all vessels'])->toBe(route('admin.vessels.index'))
        ->and($links['View all certificates'])->toBe(route('admin.certificates.index'))
        ->and($links)->toHaveCount(8);
    $this->get($links['View all vessels'])->assertOk()->assertViewHas('vessels', fn ($rows) => $rows->total() === 2);
    $this->get($links['View all certificates'])->assertOk()->assertViewHas('certificates', fn ($rows) => $rows->total() === 2);
});

test('each category card count matches its filtered current certificate records', function (string $status, string $label) {
    $records = [];
    foreach (['permanent' => null, 'valid' => 31, 'expiring-soon' => 15, 'critical' => 0, 'expired' => -1, 'unknown' => null] as $category => $days) {
        $data = $category === 'unknown' ? null : ['expiry_type' => $category === 'permanent' ? 'permanent' : 'fixed', 'expiry_date' => $days === null ? null : today()->addDays($days)->format('Y-m-d')];
        $records[$category] = navigationCertificate($this->vessel, $this->type, $data);
    }
    // The current version is 9 even though a historical row is inserted afterward.
    $renewed = $records['valid'];
    $renewed->versions()->create(['version_number' => 9, 'expiry_type' => 'fixed', 'expiry_date' => '2027-01-01', 'file_path' => 'private/current.pdf', 'original_filename' => 'current.pdf']);
    $renewed->versions()->create(['version_number' => 3, 'expiry_type' => 'fixed', 'expiry_date' => '2020-01-01', 'file_path' => 'private/history.pdf', 'original_filename' => 'history.pdf']);

    $dashboard = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertViewHas('totalCertificates', 6);
    expect($dashboard->viewData('statusCounts'))->toBe(array_fill_keys(CertificateDashboard::STATUSES, 1));
    $links = dashboardCardLinks($dashboard->getContent());
    expect($links['View '.$label.' certificates'])->toBe(route('admin.certificates.index', ['status' => $status]));
    $this->get($links['View '.$label.' certificates'])->assertOk()
        ->assertViewHas('status', $status)
        ->assertViewHas('certificates', fn ($rows) => $rows->total() === 1 && $rows->sole()->id === $records[$status]->id)
        ->assertSee('Expiry filter:')->assertSee('Clear expiry filter')
        ->assertSee('value="'.$status.'" selected', false);
})->with([
    ['permanent', 'Permanent'], ['valid', 'Valid'], ['expiring-soon', 'Expiring Soon'],
    ['critical', 'Critical'], ['expired', 'Expired'], ['unknown', 'Unknown'],
]);

test('direct filtered visits respect the existing inclusive calendar thresholds', function (int $days, string $expected) {
    $certificate = navigationCertificate($this->vessel, $this->type, ['expiry_date' => today()->addDays($days)->format('Y-m-d')]);
    $this->actingAs($this->admin)->get(route('admin.certificates.index', ['status' => $expected]))
        ->assertOk()->assertViewHas('certificates', fn ($rows) => $rows->total() === 1 && $rows->sole()->id === $certificate->id);
})->with([[-1, 'expired'], [0, 'critical'], [14, 'critical'], [15, 'expiring-soon'], [30, 'expiring-soon'], [31, 'valid']]);

test('unknown filter includes absent missing and inconsistent current expiry data', function () {
    $missingVersion = navigationCertificate($this->vessel, $this->type, null);
    $missingDate = navigationCertificate($this->vessel, $this->type, ['expiry_date' => null]);
    $inconsistent = navigationCertificate($this->vessel, $this->type, ['issuance_date' => '2027-01-01', 'expiry_date' => '2026-11-10']);
    navigationCertificate($this->vessel, $this->type, ['expiry_type' => 'permanent', 'expiry_date' => '2020-01-01']);
    $expected = [$missingVersion->id, $missingDate->id, $inconsistent->id];
    $this->actingAs($this->admin)->get(route('admin.certificates.index', ['status' => 'unknown']))
        ->assertOk()->assertViewHas('certificates', fn ($rows) => $rows->total() === 3 && $rows->pluck('id')->sort()->values()->all() === $expected);
});

test('status filtering preserves grouped searches stable sorting and pagination', function (string $search) {
    $matching = collect();
    for ($i = 0; $i < 17; $i++) {
        $matching->push(navigationCertificate($this->vessel, $this->type, ['expiry_date' => '2020-01-01'])->id);
    }
    navigationCertificate($this->vessel, $this->type, ['expiry_type' => 'permanent']);
    $other = Vessel::create(['vessel_name' => 'Outside fleet', 'vessel_type' => 'Cargo', 'flag_state' => 'UK']);
    $outside = navigationCertificate($other, $this->type, ['expiry_date' => '2020-01-01']);
    if ($search === 'Safety') {
        $matching->push($outside->id);
    }
    $expected = $matching->sortDesc()->values();
    $params = ['status' => 'expired', 'search' => $search];
    $response = $this->actingAs($this->admin)->get(route('admin.certificates.index', $params))->assertOk()
        ->assertViewHas('certificates', fn ($rows) => $rows->total() === $expected->count() && $rows->pluck('id')->all() === $expected->take(15)->all());
    parse_str(parse_url($response->viewData('certificates')->nextPageUrl(), PHP_URL_QUERY), $next);
    expect($next)->toMatchArray(['status' => 'expired', 'search' => $search, 'page' => '2']);
    $this->get(route('admin.certificates.index', $next))->assertOk()
        ->assertViewHas('certificates', fn ($rows) => $rows->total() === $expected->count() && $rows->pluck('id')->all() === $expected->slice(15)->values()->all());
    $response->assertSee('href="'.route('admin.certificates.index', ['search' => $search]).'"', false);
    $this->get(route('admin.certificates.index', ['search' => $search]))->assertViewHas('status', '')
        ->assertViewHas('certificates', fn ($rows) => $rows->total() === $expected->count() + 1);
})->with(['Fleet Match', 'Safety']);

test('empty filtered results are helpful and resettable', function () {
    $this->actingAs($this->admin)->get(route('admin.certificates.index', ['status' => 'expired']))
        ->assertOk()->assertSee('No matching certificates')->assertSee('Reset to show all certificates')
        ->assertSee('Clear expiry filter')->assertViewHas('certificates', fn ($rows) => $rows->total() === 0);
});

test('invalid expiry filters fail validation safely', function (mixed $status) {
    $this->actingAs($this->admin)->getJson(route('admin.certificates.index', ['status' => $status]))
        ->assertUnprocessable()->assertJsonValidationErrors('status');
})->with([['not-a-status'], ["expired' OR 1=1"], [['expired']], ['<script>alert(1)</script>']]);

test('protected dashboard and destination routes still deny guests staff and inactive administrators', function (string $actor) {
    if ($actor !== 'guest') {
        $this->actingAs(User::factory()->create(['role' => $actor === 'staff' ? 'client' : 'admin', 'is_active' => $actor !== 'inactive']));
    }
    foreach ([route('admin.dashboard'), route('admin.vessels.index'), route('admin.certificates.index', ['status' => 'expired'])] as $url) {
        $response = $this->get($url);
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
    }
})->with(['guest', 'staff', 'inactive']);

test('current-version metadata is read in batches without per-certificate queries', function () {
    for ($i = 0; $i < 20; $i++) {
        navigationCertificate($this->vessel, $this->type, ['expiry_date' => '2020-01-01']);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $this->actingAs($this->admin)->get(route('admin.certificates.index', ['status' => 'expired']))->assertOk()
            ->assertViewHas('certificates', fn ($rows) => $rows->total() === 20);
        expect(count(DB::getQueryLog()))->toBeLessThan(12);
    } finally {
        DB::disableQueryLog();
    }
});

test('status filtering and counts remain consistent beyond a metadata batch', function () {
    DB::table('certificates')->insert(array_fill(0, 501, [
        'vessel_id' => $this->vessel->id, 'certificate_type_id' => $this->type->id,
        'created_at' => now(), 'updated_at' => now(),
    ]));
    $ids = Certificate::orderBy('id')->pluck('id');
    DB::table('certificate_versions')->insert($ids->map(fn ($id) => [
        'certificate_id' => $id, 'version_number' => 1, 'expiry_type' => 'fixed',
        'expiry_date' => '2020-01-01', 'file_path' => 'private/batch.pdf', 'original_filename' => 'batch.pdf',
    ])->all());
    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
        ->assertViewHas('statusCounts', fn ($counts) => $counts['expired'] === 501);
    $this->get(route('admin.certificates.index', ['status' => 'expired', 'page' => 34]))->assertOk()
        ->assertViewHas('certificates', fn ($rows) => $rows->total() === 501 && $rows->count() === 6);
});
