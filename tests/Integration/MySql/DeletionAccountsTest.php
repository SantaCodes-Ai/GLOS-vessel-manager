<?php

namespace Tests\Integration\MySql;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\ValidPdf;
use Tests\Integration\MySql\Support\AccountFixtures;
use Tests\MySqlTestCase;

#[Group('mysql-accounts')]
final class DeletionAccountsTest extends MySqlTestCase
{
    use AccountFixtures;

    #[DataProvider('deletableAccounts')]
    public function test_admin_can_delete_eligible_accounts_and_preserve_decision_history(string $role, string $status, bool $active): void
    {
        $admin = $this->account('admin');
        $target = $this->account($role, ['registration_status' => $status, 'requested_role' => $role, 'is_active' => $active]);
        $history = null;
        if ($status !== 'pending') {
            DB::table('registration_decisions')->insert(array_replace($this->decisionRow($target, $admin), ['decision' => $status]));
            $history = (array) DB::table('registration_decisions')->sole();
        }
        $this->deleteThroughController($admin, $target);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        if ($history !== null) {
            $this->assertSame($history, (array) DB::table('registration_decisions')->sole());
        } else {
            $this->assertDatabaseCount('registration_decisions', 0);
        }
        $this->assertDatabaseHas('privilege_audits', ['action' => 'delete_user', 'actor_user_id' => $admin->id, 'target_user_id' => $target->id, 'previous_role' => $role, 'new_role' => 'deleted']);
    }

    public static function deletableAccounts(): array
    {
        return [['client', 'approved', true], ['client', 'pending', false], ['client', 'rejected', false], ['admin', 'approved', true]];
    }

    public function test_deletion_removes_assignments_and_authentication_rows_but_preserves_certificates_versions_pdfs_and_audits(): void
    {
        $admin = $this->account('admin');
        $target = $this->account('client', ['registration_status' => 'approved', 'requested_role' => 'client']);
        $other = $this->account();
        $vessel = $this->vessel();
        $unrelatedVessel = $this->vessel('Unrelated vessel');
        $target->vessels()->attach([$vessel->id, $unrelatedVessel->id]);
        $other->vessels()->attach($vessel);
        $certificate = $this->certificate($target, $vessel);
        $certificateBefore = $certificate->getAttributes();
        $versionsBefore = $certificate->versions()->orderBy('version_number')->get()->map->getAttributes()->all();
        $filePath = $versionsBefore[0]['file_path'];
        DB::table('registration_decisions')->insert($this->decisionRow($target, $admin));
        $decision = (array) DB::table('registration_decisions')->sole();
        DB::table('privilege_audits')->insert(['actor_user_id' => $target->id, 'target_user_id' => $target->id,
            'action' => 'role_conversion', 'previous_role' => 'super_admin', 'new_role' => 'admin',
            'previous_is_active' => true, 'new_is_active' => true, 'reason' => 'Historical audit fixture', 'created_at' => now()]);
        $audit = (array) DB::table('privilege_audits')->sole();
        DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $target->id, 'payload' => 'fixture', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $target->email, 'token' => 'test-hashed-token', 'created_at' => now()]);
        $this->deleteThroughController($admin, $target);
        $this->assertDatabaseMissing('vessel_user_access', ['user_id' => $target->id]);
        $this->assertDatabaseHas('vessel_user_access', ['user_id' => $other->id, 'vessel_id' => $vessel->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
        $this->assertDatabaseCount('vessels', 2);
        $this->assertDatabaseCount('certificate_types', 1);
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseCount('certificate_versions', 2);
        $this->assertSame(array_replace($certificateBefore, ['created_by' => null]), $certificate->fresh()->getAttributes());
        foreach ($certificate->versions()->orderBy('version_number')->get() as $index => $version) {
            $this->assertSame(array_replace($versionsBefore[$index], ['uploaded_by' => null]), $version->getAttributes());
        }
        $this->assertSame($decision, (array) DB::table('registration_decisions')->sole());
        $this->assertSame($audit, (array) DB::table('privilege_audits')->where('id', $audit['id'])->sole());
        $this->assertSame(ValidPdf::contents(), Storage::disk('certificates')->get($filePath));
        $this->get(route('admin.certificates.versions.download', [$certificate, 1]))->assertOk()->assertStreamedContent(ValidPdf::contents());
        $this->get(route('admin.certificates.download', $certificate))->assertOk()->assertStreamedContent(ValidPdf::contents());
    }

    public function test_direct_user_deletion_enforces_the_mysql_assignment_cascade(): void
    {
        $admin = $this->account('admin');
        $target = $this->account();
        $other = $this->account();
        $vessel = $this->vessel();
        $target->vessels()->attach($vessel);
        $other->vessels()->attach($vessel);
        // Direct fixture DML distinguishes the actual FK cascade from service detach().
        DB::table('users')->where('id', $target->id)->delete();
        $this->assertDatabaseMissing('vessel_user_access', ['user_id' => $target->id]);
        $this->assertDatabaseHas('vessel_user_access', ['user_id' => $other->id, 'vessel_id' => $vessel->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('vessels', ['id' => $vessel->id]);
    }

    public function test_self_deletion_is_forbidden_even_for_the_only_active_administrator(): void
    {
        $admin = $this->account('admin');
        $this->actingAs($admin)->get(route('super-admin.users.delete', $admin))->assertForbidden();
        $this->delete(route('super-admin.users.destroy', $admin), ['confirmation' => '1'])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
        $this->assertDatabaseCount('privilege_audits', 0);
    }

    #[DataProvider('lastAdminActions')]
    public function test_the_last_accessible_administrator_cannot_be_demoted_or_deactivated(string $action): void
    {
        $admin = $this->account('admin');
        $this->account('admin', ['is_active' => false]);
        $this->account('admin', ['registration_status' => 'approved', 'email_verified_at' => null]);
        $this->actingAs($admin)->post(route('super-admin.administrators.update', $admin), [
            'action' => $action, 'password' => 'password', 'target_email' => $admin->email,
            'confirm' => '1', 'reason' => 'Verify last administrator protection',
        ])->assertSessionHasErrors('action');
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertDatabaseCount('privilege_audits', 0);
    }

    public static function lastAdminActions(): array
    {
        return [['demote'], ['deactivate']];
    }

    public function test_deleted_email_can_register_as_a_new_pending_identity(): void
    {
        $admin = $this->account('admin');
        $target = $this->account('client', ['email' => 'reused@example.test', 'registration_status' => 'approved', 'requested_role' => 'client']);
        DB::table('registration_decisions')->insert($this->decisionRow($target, $admin));
        $oldId = $target->id;
        $this->deleteThroughController($admin, $target);
        Auth::logout();
        $this->post('/register', $this->registrationInput(['email' => 'reused@example.test', 'requested_role' => 'admin']))->assertSessionHasNoErrors();
        $new = User::where('email', 'reused@example.test')->sole();
        $this->assertNotSame($oldId, $new->id);
        $this->assertSame('pending', $new->registration_status);
        $this->assertSame('client', $new->role);
        $this->assertSame('admin', $new->requested_role);
        $this->assertFalse($new->is_active);
        $this->assertDatabaseHas('registration_decisions', ['user_id' => $oldId]);
        $this->assertDatabaseHas('privilege_audits', ['target_user_id' => $oldId, 'action' => 'delete_user']);
    }

    public function test_a_real_late_foreign_key_failure_rolls_back_the_entire_deletion(): void
    {
        $admin = $this->account('admin');
        $target = $this->account();
        $vessel = $this->vessel();
        $target->vessels()->attach($vessel);
        $certificate = $this->certificate($target, $vessel);
        DB::table('sessions')->insert(['id' => 'atomic-session', 'user_id' => $target->id, 'payload' => 'fixture', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $target->email, 'token' => 'fixture-token', 'created_at' => now()]);
        $deletedWasObserved = false;
        $driverCode = null;
        $dispatcher = User::getEventDispatcher();
        User::deleted(function ($deleted) use ($target, $vessel, &$deletedWasObserved, &$driverCode) {
            if ($deleted->id !== $target->id) {
                return;
            }
            $deletedWasObserved = ! DB::table('users')->where('id', $target->id)->exists();
            // Existing FK rejects a new assignment to the just-deleted user.
            // This is a real MySQL failure after DELETE/SET NULL/CASCADE, without DDL.
            try {
                DB::table('vessel_user_access')->insert(['user_id' => $target->id, 'vessel_id' => $vessel->id]);
            } catch (QueryException $exception) {
                $driverCode = (int) $exception->errorInfo[1];
                throw $exception;
            }
        });
        try {
            $this->actingAs($admin)->get(route('super-admin.users.delete', $target))->assertOk();
            $this->delete(route('super-admin.users.destroy', $target), ['confirmation' => '1'])->assertSessionHasErrors('confirmation');
        } finally {
            $dispatcher->forget('eloquent.deleted: '.User::class);
        }
        $this->assertTrue($deletedWasObserved);
        $this->assertSame(1452, $driverCode);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseHas('vessel_user_access', ['user_id' => $target->id, 'vessel_id' => $vessel->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'atomic-session']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $target->email]);
        $this->assertDatabaseCount('privilege_audits', 0);
        $this->assertSame($target->id, $certificate->fresh()->created_by);
        $this->assertSame([$target->id, $target->id], $certificate->versions()->orderBy('version_number')->pluck('uploaded_by')->all());
        $this->assertSame(1, DB::connection()->transactionLevel());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());
    }

    #[DataProvider('deniedDeletionActors')]
    public function test_deletion_remains_admin_only(string $actor): void
    {
        $target = $this->account();
        if ($actor !== 'guest') {
            $this->actingAs($this->account($actor === 'staff' ? 'client' : 'admin', ['is_active' => $actor !== 'inactive']));
        }
        $response = $this->delete(route('super-admin.users.destroy', $target), ['confirmation' => '1']);
        $actor === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseCount('privilege_audits', 0);
    }

    public static function deniedDeletionActors(): array
    {
        return [['guest'], ['staff'], ['inactive']];
    }
}
