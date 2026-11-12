<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BootstrapSuperAdmin extends Command
{
    protected $signature = 'glos:bootstrap-super-admin {user_id} {--email=} {--operator=}';

    protected $description = 'Retired compatibility command; does not create a third role';

    public function handle(): int
    {
        $this->error('Super Admin bootstrap is retired. No account changed. Use glos:create-admin or review glos:convert-admin-roles.');

        return self::FAILURE;
    }
}
