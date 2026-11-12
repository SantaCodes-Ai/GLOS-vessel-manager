<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessel_user_access', function (Blueprint $table) {

            $table->id();

            // User who is granted access
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // Vessel the user can access
            $table->foreignId('vessel_id')
                  ->constrained('vessels')
                  ->cascadeOnDelete();

            $table->timestamps();

            // Prevent duplicate access assignments
            $table->unique(['user_id', 'vessel_id']);

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessel_user_access');
    }
};