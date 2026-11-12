<?php

namespace Tests\Integration\MySql;

use App\Support\CertificateExpiry;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\MySql\Support\FleetFixtures;
use Tests\MySqlTestCase;

#[Group('mysql-fleet')]
final class ExpiryDashboardTest extends MySqlTestCase
{
    use FleetFixtures;

    #[DataProvider('boundaries')]
    public function test_calendar_boundaries_match_the_real_mysql_filtered_list(int $days, string $expected): void
    {
        $this->freezeCalendar();
        $admin = $this->account('admin');
        $date = CarbonImmutable::today('Europe/London')->addDays($days)->format('Y-m-d');
        $certificate = $this->record($this->vessel(), $this->type(), ['expiry_type' => 'fixed', 'expiry_date' => $date]);
        $this->assertSame($expected, $certificate->fresh()->expiry()->status);
        $this->assertSame($days, $certificate->fresh()->expiry()->days);
        $this->actingAs($admin)->get(route('admin.certificates.index', ['status' => $expected]))
            ->assertOk()->assertViewHas('certificates', fn ($rows) => $rows->total() === 1 && $rows->sole()->id === $certificate->id);
    }

    public static function boundaries(): array
    {
        return [[-1, 'expired'], [0, 'critical'], [14, 'critical'], [15, 'expiring-soon'], [30, 'expiring-soon'], [31, 'valid']];
    }

    public function test_all_card_totals_match_current_version_filters_without_history_inflation(): void
    {
        $this->freezeCalendar();
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $this->vessel('Second vessel');
        $type = $this->type();
        $records = [];
        foreach (['permanent' => null, 'valid' => 31, 'expiring-soon' => 30, 'critical' => 0, 'expired' => -1, 'unknown' => null] as $status => $days) {
            $data = $status === 'unknown' ? null : ['expiry_type' => $status === 'permanent' ? 'permanent' : 'fixed',
                'expiry_date' => $days === null ? '2020-01-01' : CarbonImmutable::today('Europe/London')->addDays($days)->format('Y-m-d')];
            $records[$status] = $this->record($vessel, $type, $data);
        }
        $this->version($records['valid'], 7, ['expiry_type' => 'fixed', 'expiry_date' => '2027-01-01']);
        $this->version($records['valid'], 3, ['expiry_type' => 'fixed', 'expiry_date' => '2020-01-01']);
        $response = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertViewHas('totalCertificates', 6)->assertViewHas('totalVessels', 2)
            ->assertViewHas('statusCounts', array_fill_keys(['permanent', 'valid', 'expiring-soon', 'critical', 'expired', 'unknown'], 1))
            ->assertViewHas('attentionCertificates', fn ($rows) => $rows->pluck('id')->all() === [$records['expired']->id, $records['critical']->id, $records['expiring-soon']->id]);
        $links = $this->cardLinks($response->getContent());
        $this->assertCount(8, $links);
        $this->assertSame(route('admin.vessels.index'), $links['View all vessels']);
        $this->assertSame(route('admin.certificates.index'), $links['View all certificates']);
        $this->get($links['View all vessels'])->assertViewHas('vessels', fn ($rows) => $rows->total() === 2);
        $this->get($links['View all certificates'])->assertViewHas('certificates', fn ($rows) => $rows->total() === 6);
        foreach ($records as $status => $certificate) {
            $label = (new CertificateExpiry($status))->label();
            $this->assertSame(route('admin.certificates.index', ['status' => $status]), $links['View '.$label.' certificates']);
            $this->get($links['View '.$label.' certificates'])->assertOk()->assertViewHas('status', $status)
                ->assertViewHas('certificates', fn ($rows) => $rows->total() === 1 && $rows->sole()->id === $certificate->id)
                ->assertSee('Clear expiry filter')->assertSee('value="'.$status.'" selected', false);
        }
    }

    #[DataProvider('unknownData')]
    public function test_unknown_uses_mysql_representable_missing_or_inconsistent_data(?array $data): void
    {
        $this->freezeCalendar();
        $admin = $this->account('admin');
        $certificate = $this->record($this->vessel(), $this->type(), $data);
        $this->assertSame('unknown', $certificate->fresh()->expiry()->status);
        $this->actingAs($admin)->get(route('admin.certificates.index', ['status' => 'unknown']))
            ->assertViewHas('certificates', fn ($rows) => $rows->total() === 1 && $rows->sole()->id === $certificate->id);
    }

    public static function unknownData(): array
    {
        return [[null], [['expiry_type' => 'fixed', 'expiry_date' => null]],
            [['expiry_type' => 'other']], [['expiry_type' => 'fixed', 'issuance_date' => '2027-01-01', 'expiry_date' => '2026-11-10']]];
    }

    #[DataProvider('searches')]
    public function test_search_sort_pagination_and_filter_clearing_remain_consistent(string $search): void
    {
        $this->freezeCalendar();
        $admin = $this->account('admin');
        $vessel = $this->vessel('Search Vessel');
        $type = $this->type('Search Type');
        $matching = collect();
        for ($i = 0; $i < 17; $i++) {
            $matching->push($this->record($vessel, $type, ['expiry_type' => 'fixed', 'expiry_date' => '2020-01-01'])->id);
        }
        $outside = $this->record($this->vessel('Outside Vessel'), $type, ['expiry_type' => 'fixed', 'expiry_date' => '2020-01-01']);
        if ($search === 'Search Type') {
            $matching->push($outside->id);
        }
        $this->record($vessel, $type, []);
        $expected = $matching->sortDesc()->values();
        $response = $this->actingAs($admin)->get(route('admin.certificates.index', ['search' => $search, 'status' => 'expired']))->assertOk()
            ->assertViewHas('certificates', fn ($rows) => $rows->total() === $expected->count() && $rows->pluck('id')->all() === $expected->take(15)->all());
        parse_str(parse_url($response->viewData('certificates')->nextPageUrl(), PHP_URL_QUERY), $next);
        $this->assertSame('expired', $next['status']);
        $this->assertSame($search, $next['search']);
        $this->get(route('admin.certificates.index', $next))->assertOk()
            ->assertViewHas('certificates', fn ($rows) => $rows->pluck('id')->all() === $expected->slice(15)->values()->all());
        $this->get(route('admin.certificates.index', ['search' => $search]))->assertViewHas('status', '')
            ->assertViewHas('certificates', fn ($rows) => $rows->total() === $expected->count() + 1);
    }

    public static function searches(): array
    {
        return [['Search Vessel'], ['Search Type']];
    }

    public function test_empty_and_invalid_filters_are_safe(): void
    {
        $admin = $this->account('admin');
        $this->actingAs($admin)->get(route('admin.certificates.index', ['status' => 'critical']))
            ->assertOk()->assertSee('No matching certificates')->assertSee('Clear expiry filter');
        foreach (['invalid', "expired' OR 1=1", ['expired']] as $status) {
            $this->getJson(route('admin.certificates.index', ['status' => $status]))->assertUnprocessable()->assertJsonValidationErrors('status');
        }
    }

    public function test_expiry_follows_application_calendar_timezone_not_utc(): void
    {
        config(['app.timezone' => 'Pacific/Auckland']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 23:30:00', 'UTC'));
        $admin = $this->account('admin');
        $certificate = $this->record($this->vessel(), $this->type(), ['expiry_type' => 'fixed', 'expiry_date' => '2026-11-11']);
        $this->assertSame('critical', $certificate->fresh()->expiry()->status);
        $this->assertSame(0, $certificate->fresh()->expiry()->days);
        $this->actingAs($admin)->get(route('admin.certificates.index', ['status' => 'critical']))
            ->assertViewHas('certificates', fn ($rows) => $rows->total() === 1);
    }
}
