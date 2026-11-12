<?php

namespace Tests\Integration\MySql;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\ValidPdf;
use Tests\Integration\MySql\Support\FleetFixtures;
use Tests\MySqlTestCase;

#[Group('mysql-fleet')]
final class StaffCertificateAccessTest extends MySqlTestCase
{
    use FleetFixtures;

    private function scenario(): array
    {
        $this->freezeCalendar();
        $admin = $this->account('admin');
        $staff = $this->account();
        $vessel = $this->vessel('Assigned Fleet Vessel');
        $secretVessel = $this->vessel('Unassigned Fleet Vessel');
        $type = $this->type('Assigned Safety');
        $secretType = $this->type('Private Compliance');
        $assigned = $this->record($vessel, $type, ['expiry_type' => 'fixed', 'expiry_date' => '2020-01-01']);
        $this->version($assigned, 2, ['expiry_type' => 'fixed', 'expiry_date' => '2027-01-01']);
        $secret = $this->record($secretVessel, $secretType);
        $this->version($secret, 9, ['expiry_type' => 'fixed', 'expiry_date' => '2020-01-01']);
        $staff->vessels()->attach($vessel);

        return compact('admin', 'staff', 'vessel', 'secretVessel', 'type', 'assigned', 'secret');
    }

    public function test_staff_lists_details_dashboard_and_search_are_assignment_scoped(): void
    {
        extract($this->scenario());
        $this->actingAs($staff)->get(route('client.dashboard'))->assertOk()
            ->assertViewHas('totalVessels', 1)->assertViewHas('totalCertificates', 1)
            ->assertViewHas('statusCounts', ['permanent' => 0, 'valid' => 1, 'expiring-soon' => 0, 'critical' => 0, 'expired' => 0, 'unknown' => 0])
            ->assertViewHas('attentionCertificates', fn ($rows) => $rows->isEmpty())->assertDontSee('Private Compliance');
        $this->get(route('client.vessels.index'))->assertOk()->assertSee('Assigned Fleet Vessel')->assertDontSee('Unassigned Fleet Vessel');
        $this->get(route('client.vessels.show', $vessel))->assertOk()->assertSee('Assigned Safety');
        $this->get(route('client.certificates.show', $assigned))->assertOk()->assertSee('Version history')
            ->assertDontSee($assigned->currentVersion()->sole()->file_path);
        $this->get(route('client.certificates.index', ['search' => 'Fleet']))->assertViewHas('certificates', fn ($rows) => $rows->total() === 1 && $rows->sole()->id === $assigned->id);
        $this->get(route('client.certificates.index', ['search' => 'Compliance']))->assertViewHas('certificates', fn ($rows) => $rows->total() === 0);
        $this->get(route('client.vessels.show', $secretVessel))->assertNotFound();
        $this->get(route('client.certificates.show', $secret))->assertNotFound();
    }

    #[DataProvider('documentActions')]
    public function test_current_and_historical_documents_enforce_assignments_and_headers(string $action): void
    {
        extract($this->scenario());
        foreach ([
            route('client.certificates.'.$action, $assigned),
            route('client.certificates.versions.'.$action, [$assigned, 1]),
        ] as $url) {
            $response = $this->actingAs($staff)->get($url)->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
                ->assertStreamedContent(ValidPdf::contents());
            $this->assertStringStartsWith($action === 'preview' ? 'inline;' : 'attachment;', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->get(route('client.certificates.'.$action, $secret))->assertNotFound();
        $this->get(route('client.certificates.versions.'.$action, [$secret, 9]))->assertNotFound();
        // Version number 9 belongs only to the unauthorised certificate.
        $this->get(route('client.certificates.versions.'.$action, [$assigned, 9]))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.certificates.'.$action, $secret))->assertOk()->assertStreamedContent(ValidPdf::contents());
        $this->get(route('client.certificates.'.$action, $assigned))->assertForbidden();
    }

    public static function documentActions(): array
    {
        return [['preview'], ['download']];
    }

    public function test_revocation_is_immediate_even_with_a_cached_vessel_relation(): void
    {
        extract($this->scenario());
        $staff->load('vessels');
        $this->actingAs($staff)->get(route('client.certificates.preview', $assigned))->assertOk();
        $this->actingAs($admin)->put(route('admin.clients.update', $staff), [
            'name' => $staff->name, 'email' => $staff->email, 'is_active' => true, 'vessel_ids' => [],
        ])->assertSessionHasNoErrors();
        $this->assertCount(1, $staff->vessels); // Deliberately stale model relation.
        $this->actingAs($staff)->get(route('client.dashboard'))->assertViewHas('totalVessels', 0)->assertViewHas('totalCertificates', 0);
        $this->get(route('client.vessels.show', $vessel))->assertNotFound();
        $this->get(route('client.certificates.show', $assigned))->assertNotFound();
        foreach (['preview', 'download'] as $action) {
            $this->get(route('client.certificates.'.$action, $assigned))->assertNotFound();
            $this->get(route('client.certificates.versions.'.$action, [$assigned, 1]))->assertNotFound();
        }
        $this->actingAs($admin)->get(route('admin.certificates.download', $assigned))->assertOk();
    }

    public function test_staff_cannot_mutate_vessels_types_certificates_or_assignments(): void
    {
        extract($this->scenario());
        $before = $assigned->getAttributes();
        $files = $this->privateFiles();
        $this->actingAs($staff)->get(route('admin.certificates.index', ['status' => 'expired']))->assertForbidden();
        $this->get(route('admin.certificates.create'))->assertForbidden();
        $this->get(route('admin.certificates.renew', $assigned))->assertForbidden();
        foreach ([route('admin.certificates.store'), route('admin.certificates.renew.store', $assigned)] as $url) {
            $this->post($url, $this->uploadInput($vessel, $type))->assertForbidden();
        }
        $this->put(route('admin.vessels.update', $vessel), ['vessel_name' => 'Injected'])->assertForbidden();
        $this->put(route('admin.certificate-types.update', $type), ['name' => 'Injected'])->assertForbidden();
        $this->put(route('admin.clients.update', $staff), ['vessel_ids' => [$secretVessel->id]])->assertForbidden();
        // Certificate editing is not an implemented endpoint for any role.
        $this->put(route('admin.certificates.show', $assigned), ['certificate_number' => 'Injected'])->assertStatus(405);
        $this->assertSame($before, $assigned->fresh()->getAttributes());
        $this->assertSame($files, $this->privateFiles());
        $this->assertDatabaseCount('certificates', 2);
        $this->assertDatabaseCount('certificate_versions', 3);
    }

    #[DataProvider('badDocumentStates')]
    public function test_missing_or_unsafe_test_owned_document_paths_fail_safely(string $state): void
    {
        extract($this->scenario());
        $version = $assigned->currentVersion()->sole();
        if ($state === 'missing') {
            Storage::disk('certificates')->delete($version->file_path);
        } else {
            $version->update(['file_path' => '../outside.pdf']);
        }
        $this->actingAs($staff)->get(route('client.certificates.preview', $assigned))->assertNotFound();
        $this->get(route('client.certificates.download', $assigned))->assertNotFound();
    }

    public static function badDocumentStates(): array
    {
        return [['missing'], ['unsafe']];
    }

    #[DataProvider('ineligibleActors')]
    public function test_guest_and_inactive_staff_cannot_access_metadata_or_documents(string $actor): void
    {
        extract($this->scenario());
        if ($actor === 'inactive') {
            $staff->is_active = false;
            $staff->save();
            $this->actingAs($staff);
        }
        foreach ([route('client.dashboard'), route('client.certificates.show', $assigned), route('client.certificates.download', $assigned),
            route('client.certificates.versions.preview', [$assigned, 1])] as $url) {
            $response = $this->get($url);
            $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
        }
    }

    public static function ineligibleActors(): array
    {
        return [['guest'], ['inactive']];
    }
}
