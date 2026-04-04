<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Reseller pool quotas — null means unlimited
            $table->unsignedInteger('quota_accounts')->nullable()->after('reseller_id');
            $table->unsignedBigInteger('quota_disk_mb')->nullable()->after('quota_accounts');
            $table->unsignedBigInteger('quota_bandwidth_mb')->nullable()->after('quota_disk_mb');
            $table->unsignedInteger('quota_domains')->nullable()->after('quota_bandwidth_mb');
            $table->unsignedInteger('quota_email_accounts')->nullable()->after('quota_domains');
            $table->unsignedInteger('quota_databases')->nullable()->after('quota_email_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'quota_accounts',
                'quota_disk_mb',
                'quota_bandwidth_mb',
                'quota_domains',
                'quota_email_accounts',
                'quota_databases',
            ]);
        });
    }
};
