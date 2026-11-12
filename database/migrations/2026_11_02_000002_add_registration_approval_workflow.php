<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('requested_role', 32)->nullable();
            $table->string('registration_status', 32)->nullable()->index();
        });
        Schema::create('registration_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('actor_user_id')->index();
            $table->string('requested_role', 32);
            $table->string('decision', 32);
            $table->string('previous_role', 32);
            $table->string('new_role', 32);
            $table->string('reason', 1000);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_decisions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['requested_role', 'registration_status']);
        });
    }
};
