<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Reseller foreign key — add if not present
            if (! Schema::hasColumn('users', 'reseller_id')) {
                $table->unsignedBigInteger('reseller_id')->nullable();
            }

            // Reseller pool quotas — null means unlimited
            $table->unsignedInteger('quota_accounts')->nullable();
            $table->unsignedBigInteger('quota_disk_mb')->nullable();
            $table->unsignedBigInteger('quota_bandwidth_mb')->nullable();
            $table->unsignedInteger('quota_domains')->nullable();
            $table->unsignedInteger('quota_email_accounts')->nullable();
            $table->unsignedInteger('quota_databases')->nullable();
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
