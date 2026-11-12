<?php

namespace Tests\Integration\MySql;

use App\Models\Certificate;
use App\Models\CertificateVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\ValidPdf;
use Tests\Integration\MySql\Support\FleetFixtures;
use Tests\MySqlTestCase;

#[Group('mysql-fleet')]
final class CertificateLifecycleTest extends MySqlTestCase
{
    use FleetFixtures;

    public function test_registration_stores_private_original_bytes_and_exactly_version_one(): void
    {
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $type = $this->type();
        $this->actingAs($admin)->post(route('admin.certificates.store'), $this->uploadInput($vessel, $type, [
            'version_number' => 99, 'created_by' => 0, 'uploaded_by' => 0, 'file_path' => '../public/unsafe.php',
        ]))->assertSessionHasNoErrors();
        $certificate = Certificate::sole();
        $version = CertificateVersion::sole();
        $this->assertSame($vessel->id, $certificate->vessel_id);
        $this->assertSame($type->id, $certificate->certificate_type_id);
        $this->assertSame($admin->id, $certificate->created_by);
        $this->assertSame(1, $version->version_number);
        $this->assertSame($certificate->id, $version->certificate_id);
        $this->assertSame($admin->id, $version->uploaded_by);
        $this->assertSame('certificate.pdf', $version->original_filename);
        $this->assertSame('2027-01-01', $version->expiry_date->format('Y-m-d'));
        $this->assertMatchesRegularExpression('#\Avessels/'.$vessel->id.'/[a-f0-9-]{36}\.pdf\z#', $version->file_path);
        $this->assertSame('private', Storage::disk('certificates')->getVisibility($version->file_path));
        $this->assertSame(ValidPdf::contents(), Storage::disk('certificates')->get($version->file_path));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->get(route('admin.certificates.show', $certificate))->assertOk()->assertDontSee($version->file_path);
    }

    public function test_renewals_increment_versions_preserve_history_and_keep_associations(): void
    {
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $type = $this->type();
        $certificate = $this->record($vessel, $type, ['expiry_type' => 'fixed', 'expiry_date' => '2020-01-01']);
        $other = $this->record($vessel, $type, ['expiry_type' => 'permanent']);
        $unrelated = $other->getAttributes();
        $oldVersion = $certificate->currentVersion()->sole();
        $before = $oldVersion->getAttributes();
        $newBytes = ValidPdf::contents()."\n";
        $this->actingAs($admin);
        foreach ([2, 3] as $number) {
            $this->post(route('admin.certificates.renew.store', $certificate), $this->uploadInput($vessel, $type, [
                'certificate_number' => 'RENEW-'.$number, 'document' => $this->pdf('renewal.pdf', $newBytes),
                'vessel_id' => 0, 'certificate_type_id' => 0, 'version_number' => 999, 'uploaded_by' => 0,
            ]))->assertSessionHasNoErrors();
            $current = $certificate->fresh()->currentVersion;
            $this->assertSame($number, $current->version_number);
            $this->assertSame('RENEW-'.$number, $current->certificate_number);
            $this->assertSame($admin->id, $current->uploaded_by);
            $this->assertNotSame($oldVersion->file_path, $current->file_path);
            $this->assertSame($newBytes, Storage::disk('certificates')->get($current->file_path));
        }
        $this->assertSame($before, $oldVersion->fresh()->getAttributes());
        $this->assertSame(ValidPdf::contents(), Storage::disk('certificates')->get($oldVersion->file_path));
        $this->assertSame($vessel->id, $certificate->fresh()->vessel_id);
        $this->assertSame($type->id, $certificate->fresh()->certificate_type_id);
        $this->assertSame('Preserve metadata', $certificate->fresh()->remarks);
        $this->assertSame([1, 2, 3], $certificate->versions()->orderBy('version_number')->pluck('version_number')->all());
        $this->assertSame($unrelated, $other->fresh()->getAttributes());
    }

    public function test_highest_version_number_wins_even_when_history_is_inserted_later(): void
    {
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $type = $this->type();
        $certificate = $this->record($vessel, $type);
        $current = $this->version($certificate, 7);
        $this->version($certificate, 2, ['expiry_type' => 'fixed', 'expiry_date' => '2020-01-01']);
        $this->assertSame($current->id, $certificate->fresh()->currentVersion->id);
        $this->actingAs($admin)->post(route('admin.certificates.renew.store', $certificate), $this->uploadInput($vessel, $type))->assertSessionHasNoErrors();
        $this->assertSame(8, $certificate->fresh()->currentVersion->version_number);
    }

    #[DataProvider('badMetadata')]
    public function test_invalid_registration_and_renewal_preserve_existing_metadata_and_files(string $field, mixed $value): void
    {
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $type = $this->type();
        $certificate = $this->record($vessel, $type, []);
        $before = $certificate->getAttributes();
        $version = $certificate->currentVersion()->sole()->getAttributes();
        $files = $this->privateFiles();
        $this->actingAs($admin);
        foreach ([route('admin.certificates.store'), route('admin.certificates.renew.store', $certificate)] as $url) {
            $this->post($url, $this->uploadInput($vessel, $type, [$field => $value]))->assertSessionHasErrors($field);
            $this->assertSame($before, $certificate->fresh()->getAttributes());
            $this->assertSame($version, $certificate->versions()->sole()->getAttributes());
            $this->assertDatabaseCount('certificates', 1);
            $this->assertDatabaseCount('certificate_versions', 1);
            $this->assertSame($files, $this->privateFiles());
        }
    }

    public static function badMetadata(): array
    {
        return [['certificate_number', ['array']], ['issuance_date', 'not-a-date'], ['expiry_type', 'other'],
            ['expiry_date', null], ['expiry_date', '2025-12-31'], ['expiry_date', '2027-02-30']];
    }

    public function test_permanent_expiry_clears_dates_during_creation_and_renewal(): void
    {
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $type = $this->type();
        $this->actingAs($admin)->post(route('admin.certificates.store'), $this->uploadInput($vessel, $type, [
            'expiry_type' => 'permanent', 'expiry_date' => 'invalid-discarded',
        ]))->assertSessionHasNoErrors();
        $certificate = Certificate::sole();
        $this->assertNull($certificate->currentVersion->expiry_date);
        $this->post(route('admin.certificates.renew.store', $certificate), ['expiry_type' => 'permanent', 'document' => $this->pdf()])->assertSessionHasNoErrors();
        $this->assertNull($certificate->fresh()->currentVersion->expiry_date);
        $this->assertNull($certificate->fresh()->currentVersion->issuance_date);
    }

    #[DataProvider('badAssociations')]
    public function test_invalid_certificate_associations_fail_before_persistence(string $field, mixed $value): void
    {
        $admin = $this->account('admin');
        $vessel = $this->vessel();
        $type = $this->type();
        $files = $this->privateFiles();
        $this->actingAs($admin)->post(route('admin.certificates.store'), $this->uploadInput($vessel, $type, [$field => $value]))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('certificate_versions', 0);
        $this->assertSame($files, $this->privateFiles());
    }

    public static function badAssociations(): array
    {
        return [['vessel_id', 0], ['certificate_type_id', 0], ['vessel_id', ['nested' => ['invalid']]]];
    }

    public function test_version_uniqueness_and_foreign_keys_are_genuine_mysql_constraints(): void
    {
        $vessel = $this->vessel();
        $type = $this->type();
        $certificate = $this->record($vessel, $type, []);
        $version = $certificate->versions()->sole();
        $attributes = $version->getAttributes();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
        $this->assertMysqlConstraint(fn () => DB::table('certificate_versions')->insert($attributes), 1062);
        $this->assertMysqlConstraint(fn () => DB::table('certificate_versions')->insert(array_replace($attributes, ['certificate_id' => 0])), 1452);
        $this->assertMysqlConstraint(fn () => DB::table('certificate_versions')->insert(array_replace($attributes, ['version_number' => 2, 'uploaded_by' => 0])), 1452);
        $this->assertDatabaseCount('certificate_versions', 1);
    }

    public function test_mysql_strict_date_storage_rejects_invalid_text_without_losing_the_transaction(): void
    {
        $certificate = $this->record($this->vessel(), $this->type(), ['expiry_type' => 'fixed', 'expiry_date' => '2027-01-01']);
        $version = $certificate->versions()->sole();
        try {
            DB::transaction(fn () => DB::table('certificate_versions')->where('id', $version->id)->update(['expiry_date' => 'invalid']));
            $this->fail('Strict MySQL must reject an invalid DATE value.');
        } catch (QueryException $exception) {
            $this->assertSame(1292, (int) $exception->errorInfo[1]);
            $this->assertSame('22007', $exception->errorInfo[0]);
        }
        $this->assertSame('2027-01-01', $version->fresh()->expiry_date->format('Y-m-d'));
        $this->assertSame(1, DB::connection()->transactionLevel());
    }
}
