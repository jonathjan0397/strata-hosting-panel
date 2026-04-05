<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // 'disabled' | 'daily' | 'weekly'
            $table->string('backup_schedule', 10)->default('daily')->after('suspended_at');
            // HH:MM in 24h format, e.g. "02:00"
            $table->string('backup_time', 5)->default('02:00')->after('backup_schedule');
            // 0=Sun … 6=Sat (only relevant when backup_schedule='weekly')
            $table->tinyInteger('backup_day')->unsigned()->default(0)->after('backup_time');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['backup_schedule', 'backup_time', 'backup_day']);
        });
    }
};
