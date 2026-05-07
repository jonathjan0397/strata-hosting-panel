<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cron_jobs', function (Blueprint $table) {
            $table->string('working_dir')->default('.')->after('command');
        });

        DB::table('cron_jobs')->update([
            'working_dir' => '.',
        ]);
    }

    public function down(): void
    {
        Schema::table('cron_jobs', function (Blueprint $table) {
            $table->dropColumn('working_dir');
        });
    }
};
