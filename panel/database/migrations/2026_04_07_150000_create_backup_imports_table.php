<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('backup_job_id')->nullable()->constrained('backup_jobs')->nullOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_system', 32)->default('auto');
            $table->string('status', 32)->default('queued');
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('converted_filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('detected_paths')->nullable();
            $table->text('notes')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_imports');
    }
};
