<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ConvertAdministratorRoles extends Command
{
    protected $signature = 'glos:convert-admin-roles {--apply : Explicitly apply the conversion} {--operator= : Accountable operator identifier}';

    protected $description = 'Preview or explicitly convert legacy super_admin rows to admin without changing credentials';

    public function handle(): int
    {
        $count = User::where('role', 'super_admin')->count();
        $this->info('Legacy administrator accounts: '.$count.'. Historical audit rows and bootstrap state will be preserved.');
        if (! $this->option('apply')) {
            $this->info('Dry run only. No accounts changed.');

            return self::SUCCESS;
        }
        $operator = $this->option('operator');
        if (! $this->input->isInteractive() || ! is_string($operator) || trim($operator) === '' || mb_strlen($operator) > 255) {
            $this->error('Apply requires an interactive terminal and an operator identifier.');

            return self::FAILURE;
        }
        if (! $this->confirm('Convert legacy Super Admin roles to admin on the configured database?', false)) {
            return self::FAILURE;
        }
        try {
            $converted = DB::transaction(function () use ($operator) {
                if (! DB::table('privilege_management_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new RuntimeException('Privilege lock missing.');
                }
                $eligible = User::accessibleAdministrators()->orderBy('id')->lockForUpdate()->get(['id']);
                if ($eligible->isEmpty()) {
                    throw new RuntimeException('No accessible administrator exists.');
                }
                $users = User::where('role', 'super_admin')->orderBy('id')->lockForUpdate()->get();
                foreach ($users as $user) {
                    $user->role = 'admin';
                    $user->save();
                    DB::table('privilege_audits')->insert([
                        'actor_user_id' => null, 'operator_identifier' => $operator, 'target_user_id' => $user->id,
                        'action' => 'role_conversion', 'previous_role' => 'super_admin', 'new_role' => 'admin',
                        'previous_is_active' => $user->is_active, 'new_is_active' => $user->is_active,
                        'reason' => 'Explicit conversion to the two-role administrator model.', 'created_at' => now(),
                    ]);
                }

                return $users->count();
            }, 3);
        } catch (Throwable) {
            $this->error('Conversion failed. Verify the database, migration and accessible administrator state before retrying.');

            return self::FAILURE;
        }
        $this->info('Converted '.$converted.' legacy roles. Credentials and historical audits were preserved.');

        return self::SUCCESS;
    }
}
