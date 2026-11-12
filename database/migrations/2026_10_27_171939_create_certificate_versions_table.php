<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_versions', function (Blueprint $table) {

            $table->id();

            // Certificate associated with this version
            $table->foreignId('certificate_id')
                  ->constrained('certificates')
                  ->restrictOnDelete();

            // Version number (1, 2, 3...)
            $table->unsignedInteger('version_number');

            // Certificate reference number
            $table->string('certificate_number')->nullable();

            // Certificate issuance date
            $table->date('issuance_date')->nullable();

            // Certificate expiration date
            $table->date('expiry_date')->nullable();

            // Whether the certificate has a fixed expiry
            $table->string('expiry_type')
                  ->default('fixed');

            // Location of the uploaded PDF
            $table->string('file_path', 500);

            // Original uploaded filename
            $table->string('original_filename');

            // Administrator who uploaded the document
            $table->foreignId('uploaded_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Date and time the PDF was uploaded
            $table->timestamp('uploaded_at')
                  ->useCurrent();

            $table->timestamps();

            // Prevent duplicate version numbers
            $table->unique([
                'certificate_id',
                'version_number'
            ]);

            // Improve expiration date searches
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_versions');
    }
};