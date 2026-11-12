<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {

            $table->id();

            // Vessel associated with the certificate
            $table->foreignId('vessel_id')
                  ->constrained('vessels')
                  ->restrictOnDelete();

            // Type of certificate
            $table->foreignId('certificate_type_id')
                  ->constrained('certificate_types')
                  ->restrictOnDelete();

            // Optional certificate reference number
            $table->string('certificate_number')->nullable();

            // Additional information
            $table->text('remarks')->nullable();

            // Administrator who created the record
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            // Improve searches for certificates belonging to vessels
            $table->index(['vessel_id', 'certificate_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};