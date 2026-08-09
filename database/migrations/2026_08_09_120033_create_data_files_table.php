<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('data_files', function (Blueprint $table) {
            $table->comment('Uploaded input files belonging to projects.');

            $table->id('data_file_id');

            $table->foreignId('project_id')
                ->constrained('projects', 'project_id')
                ->restrictOnDelete()
                ->comment('Owning project ID');

            $table->string('original_name', 255)
                ->comment('Original uploaded file name');

            $table->string('stored_path', 512)
                ->comment('Application-generated private storage path');

            $table->string('mime_type', 255)
                ->comment('Detected MIME type');

            $table->unsignedBigInteger('size')
                ->comment('File size in bytes');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_files');
    }
};
