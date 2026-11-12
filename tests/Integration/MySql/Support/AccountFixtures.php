<?php

namespace Tests\Integration\MySql\Support;

use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\ValidPdf;

trait AccountFixtures
{
    protected function account(string $role = 'client', array $attributes = []): User
    {
        return User::factory()->create(array_replace(['role' => $role, 'is_active' => true], $attributes))->refresh();
    }

    protected function applicant(string $requested = 'client', array $attributes = []): User
    {
        return $this->account('client', array_replace([
            'requested_role' => $requested, 'registration_status' => 'pending',
            'email_verified_at' => null, 'is_active' => false, 'department' => 'Operations',
        ], $attributes));
    }

    protected function registrationInput(array $overrides = []): array
    {
        return array_replace(['name' => 'MySQL Applicant', 'email' => 'applicant@example.test',
            'password' => 'ApplicantSecure!123', 'password_confirmation' => 'ApplicantSecure!123',
            'requested_role' => 'client', 'department' => 'Marine Operations'], $overrides);
    }

    protected function vessel(string $name = 'MySQL Fixture Vessel'): Vessel
    {
        return Vessel::create(['vessel_name' => $name, 'vessel_type' => 'Cargo', 'flag_state' => 'UK'])->refresh();
    }

    protected function certificate(User $owner, Vessel $vessel): Certificate
    {
        $type = CertificateType::create(['name' => 'MySQL Safety Certificate', 'category' => 'Statutory']);
        $certificate = Certificate::create(['vessel_id' => $vessel->id, 'certificate_type_id' => $type->id, 'created_by' => $owner->id]);
        $path = 'vessels/'.$vessel->id.'/'.Str::uuid().'.pdf';
        Storage::disk('certificates')->put($path, ValidPdf::contents());
        $certificate->versions()->create(['version_number' => 1, 'expiry_type' => 'permanent',
            'file_path' => $path, 'original_filename' => 'fixture.pdf', 'uploaded_by' => $owner->id]);
        $certificate->versions()->create(['version_number' => 2, 'expiry_type' => 'permanent',
            'file_path' => $path, 'original_filename' => 'fixture.pdf', 'uploaded_by' => $owner->id]);

        return $certificate->refresh();
    }

    protected function decisionRow(User $applicant, User $actor): array
    {
        return ['user_id' => $applicant->id, 'actor_user_id' => $actor->id,
            'requested_role' => $applicant->requested_role ?? 'client', 'decision' => 'approved',
            'previous_role' => 'client', 'new_role' => $applicant->role,
            'reason' => 'Isolated integration history fixture', 'created_at' => now()];
    }

    protected function deleteThroughController(User $actor, User $target): void
    {
        $this->actingAs($actor)->get(route('super-admin.users.delete', $target))->assertOk();
        $this->delete(route('super-admin.users.destroy', $target), ['confirmation' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect(route('super-admin.administrators.index'));
    }

    protected function assertMysqlConstraint(callable $operation, int $code): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected a real MySQL constraint violation.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
            $this->assertSame($code, (int) $exception->errorInfo[1]);
        }
        $this->assertSame(1, DB::connection()->transactionLevel());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());
    }
}
