<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->string('restore_status', 32)->nullable()->after('status');
            $table->text('restore_error')->nullable()->after('error');
            $table->timestamp('last_restored_at')->nullable()->after('restore_error');
        });
    }

    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropColumn(['restore_status', 'restore_error', 'last_restored_at']);
        });
    }
};
