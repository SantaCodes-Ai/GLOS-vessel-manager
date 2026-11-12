<?php

namespace Tests\Integration\MySql;

use App\Models\CertificateType;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\MySql\Support\AccountFixtures;
use Tests\MySqlTestCase;

#[Group('mysql-accounts')]
final class AccountIntegrityTest extends MySqlTestCase
{
    use AccountFixtures;

    #[DataProvider('invalidForeignKeys')]
    public function test_mysql_rejects_orphan_account_and_certificate_references(string $scenario): void
    {
        $user = $this->account();
        $vessel = $this->vessel();
        $type = CertificateType::create(['name' => 'Integrity fixture', 'category' => 'Statutory']);
        $this->assertMysqlConstraint(function () use ($scenario, $user, $vessel, $type) {
            match ($scenario) {
                'missing user' => DB::table('vessel_user_access')->insert(['user_id' => 0, 'vessel_id' => $vessel->id]),
                'missing vessel' => DB::table('vessel_user_access')->insert(['user_id' => $user->id, 'vessel_id' => 0]),
                'missing creator' => DB::table('certificates')->insert(['vessel_id' => $vessel->id, 'certificate_type_id' => $type->id, 'created_by' => 0]),
            };
        }, 1452);
        $this->assertDatabaseCount('vessel_user_access', 0);
        $this->assertDatabaseCount('certificates', 0);
    }

    public static function invalidForeignKeys(): array
    {
        return [['missing user'], ['missing vessel'], ['missing creator']];
    }

    public function test_mysql_enforces_assignment_uniqueness(): void
    {
        $user = $this->account();
        $vessel = $this->vessel();
        $user->vessels()->attach($vessel);
        $this->assertMysqlConstraint(fn () => $user->vessels()->attach($vessel), 1062);
        $this->assertDatabaseCount('vessel_user_access', 1);
    }

    public function test_certificates_restrict_vessel_deletion_without_damaging_account_data(): void
    {
        $user = $this->account();
        $vessel = $this->vessel();
        $this->certificate($user, $vessel);
        $this->assertMysqlConstraint(fn () => DB::table('vessels')->where('id', $vessel->id)->delete(), 1451);
        $this->assertDatabaseHas('vessels', ['id' => $vessel->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseCount('certificate_versions', 2);
    }

    public function test_nested_rollback_restores_mysql_data_without_losing_the_managed_transaction(): void
    {
        $original = $this->account('admin', ['name' => 'Original name']);
        try {
            DB::transaction(function () use ($original) {
                DB::table('users')->where('id', $original->id)->update(['name' => 'Should roll back']);
                $this->account('client', ['email' => 'rollback@example.test']);
                throw new \RuntimeException('Deliberate savepoint rollback');
            });
            $this->fail('The nested operation must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Deliberate savepoint rollback', $exception->getMessage());
        }
        $this->assertSame('Original name', $original->fresh()->name);
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
        $this->assertSame(1, DB::connection()->transactionLevel());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());
    }
}
