<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_migrations', function (Blueprint $table) {
            $table->foreignId('target_backup_job_id')
                ->nullable()
                ->after('backup_job_id')
                ->constrained('backup_jobs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_migrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_backup_job_id');
        });
    }
};
