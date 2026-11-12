<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privilege_management_locks', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->timestamp('bootstrapped_at')->nullable();
        });
        DB::table('privilege_management_locks')->insert(['id' => 1]);

        Schema::create('privilege_audits', function (Blueprint $table) {
            $table->id();
            // Preserve historical IDs even if accounts are removed in a future phase.
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('operator_identifier')->nullable();
            $table->unsignedBigInteger('target_user_id')->index();
            $table->string('action', 32);
            $table->string('previous_role', 32);
            $table->string('new_role', 32);
            $table->boolean('previous_is_active');
            $table->boolean('new_is_active');
            $table->string('reason', 1000);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privilege_audits');
        Schema::dropIfExists('privilege_management_locks');
    }
};
