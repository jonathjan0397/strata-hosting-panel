<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('filename')->nullable();
            $table->enum('type', ['files', 'databases', 'full'])->default('full');
            $table->enum('status', ['pending', 'running', 'complete', 'failed'])->default('pending');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('error')->nullable();
            $table->enum('trigger', ['manual', 'scheduled'])->default('manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_jobs');
    }
};
