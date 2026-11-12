<?php

namespace Tests\Integration\MySql;

use App\Models\CertificateType;
use App\Models\Vessel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\MySql\Support\FleetFixtures;
use Tests\MySqlTestCase;

#[Group('mysql-fleet')]
final class VesselTypeManagementTest extends MySqlTestCase
{
    use FleetFixtures;

    private function vesselInput(array $overrides = []): array
    {
        return array_replace(['vessel_name' => 'Fictional Ocean Pioneer', 'vessel_type' => 'Cargo',
            'flag_state' => 'UK', 'imo_number' => '9074729', 'official_number' => 'TEST-001', 'vessel_status' => 'active'], $overrides);
    }

    public function test_admin_registers_and_updates_a_vessel_without_affecting_another_record(): void
    {
        $admin = $this->account('admin');
        $unrelated = $this->vessel('Unrelated vessel');
        $before = $unrelated->getAttributes();
        $this->actingAs($admin)->post(route('admin.vessels.store'), $this->vesselInput())->assertSessionHasNoErrors();
        $vessel = Vessel::where('imo_number', '9074729')->sole();
        $this->assertSame($this->vesselInput(), $vessel->only(array_keys($this->vesselInput())));
        $this->put(route('admin.vessels.update', $vessel), $this->vesselInput(['vessel_name' => 'Updated vessel', 'vessel_status' => 'inactive']))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.vessels.show', $vessel));
        $this->assertSame('Updated vessel', $vessel->fresh()->vessel_name);
        $this->assertSame('inactive', $vessel->fresh()->vessel_status);
        $this->assertSame($before, $unrelated->fresh()->getAttributes());
        $this->get(route('admin.vessels.index', ['search' => '9074729', 'status' => 'inactive']))
            ->assertViewHas('vessels', fn ($rows) => $rows->total() === 1 && $rows->sole()->id === $vessel->id);
    }

    #[DataProvider('badVesselFields')]
    public function test_invalid_vessel_forms_return_validation_errors_on_create_and_edit(string $field, mixed $value): void
    {
        $admin = $this->account('admin');
        $vessel = Vessel::create($this->vesselInput())->refresh();
        $before = $vessel->getAttributes();
        $this->actingAs($admin)->from(route('admin.vessels.create'))
            ->post(route('admin.vessels.store'), $this->vesselInput([$field => $value]))->assertSessionHasErrors($field);
        $this->get(route('admin.vessels.create'))->assertOk();
        $this->from(route('admin.vessels.edit', $vessel))->put(route('admin.vessels.update', $vessel), $this->vesselInput([$field => $value]))
            ->assertSessionHasErrors($field);
        $this->get(route('admin.vessels.edit', $vessel))->assertOk();
        $this->assertSame($before, $vessel->fresh()->getAttributes());
        $this->assertDatabaseCount('vessels', 1);
    }

    public static function badVesselFields(): array
    {
        return [
            ['vessel_name', ''], ['vessel_type', ''], ['flag_state', ''], ['vessel_status', 'deleted'],
            ['imo_number', '123'], ['imo_number', '9074728'], ['imo_number', 'ABCDEFG'],
            ['vessel_name', ['nested' => ['bad']]], ['flag_state', ['UK']], ['vessel_status', ['active']],
            ['vessel_name', str_repeat('x', 256)], ['official_number', str_repeat('x', 256)],
        ];
    }

    public function test_imo_uniqueness_is_enforced_by_validation_and_the_real_mysql_index(): void
    {
        $admin = $this->account('admin');
        $existing = Vessel::create($this->vesselInput())->refresh();
        $this->actingAs($admin)->post(route('admin.vessels.store'), $this->vesselInput())->assertSessionHasErrors('imo_number');
        $this->assertMysqlConstraint(fn () => Vessel::create($this->vesselInput()), 1062);
        $this->assertDatabaseCount('vessels', 1);
        $this->assertSame('9074729', $existing->fresh()->imo_number);
        // Names are not unique, and multiple absent IMO numbers remain legitimate.
        $this->post(route('admin.vessels.store'), $this->vesselInput(['imo_number' => null]))->assertSessionHasNoErrors();
        $this->post(route('admin.vessels.store'), $this->vesselInput(['imo_number' => null]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('vessels', 3);
    }

    public function test_certificate_type_management_preserves_uniqueness_and_unrelated_types(): void
    {
        $admin = $this->account('admin');
        $other = $this->type('Unrelated type');
        $before = $other->getAttributes();
        $input = ['name' => 'Safety', 'category' => 'Statutory', 'description' => 'Fictional type', 'default_validity_months' => 12];
        $this->actingAs($admin)->post(route('admin.certificate-types.store'), $input)->assertSessionHasNoErrors();
        $type = CertificateType::where('name', 'Safety')->sole();
        $this->put(route('admin.certificate-types.update', $type), array_replace($input, ['description' => 'Updated description', 'default_validity_months' => 24]))->assertSessionHasNoErrors();
        $this->assertSame('Updated description', $type->fresh()->description);
        $this->assertEquals(24, $type->fresh()->default_validity_months);
        $this->post(route('admin.certificate-types.store'), array_replace($input, ['name' => 'SAFETY']))->assertSessionHasErrors('name');
        $this->assertMysqlConstraint(fn () => CertificateType::create($input), 1062);
        $this->assertSame($before, $other->fresh()->getAttributes());
    }

    #[DataProvider('badTypeFields')]
    public function test_malformed_certificate_type_forms_are_safe_under_mysql(string $field, mixed $value): void
    {
        $admin = $this->account('admin');
        $type = $this->type();
        $before = $type->getAttributes();
        $input = ['name' => $type->name, 'category' => 'Statutory', 'description' => 'Description', 'default_validity_months' => 12];
        $this->actingAs($admin)->from(route('admin.certificate-types.create'))
            ->post(route('admin.certificate-types.store'), array_replace($input, [$field => $value]))->assertSessionHasErrors($field);
        $this->get(route('admin.certificate-types.create'))->assertOk();
        $this->from(route('admin.certificate-types.edit', $type))
            ->put(route('admin.certificate-types.update', $type), array_replace($input, [$field => $value]))->assertSessionHasErrors($field);
        $this->get(route('admin.certificate-types.edit', $type))->assertOk();
        $this->assertSame($before, $type->fresh()->getAttributes());
    }

    public static function badTypeFields(): array
    {
        return [['name', ['bad']], ['category', ''], ['description', ['bad']], ['default_validity_months', 0],
            ['default_validity_months', [12]], ['name', str_repeat('x', 256)]];
    }
}
