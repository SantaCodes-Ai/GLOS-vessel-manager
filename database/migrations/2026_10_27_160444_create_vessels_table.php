<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessels', function (Blueprint $table) {

            $table->id();

            $table->string('vessel_name');

            $table->string('imo_number', 7)
                  ->nullable()
                  ->unique();

            $table->string('vessel_type');

            $table->string('flag_state');

            $table->string('official_number')
                  ->nullable();

            $table->string('vessel_status')
                  ->default('active');

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessels');
    }
};