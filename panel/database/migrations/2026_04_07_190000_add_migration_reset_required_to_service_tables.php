<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['email_accounts', 'ftp_accounts', 'web_dav_accounts', 'hosting_databases', 'database_grants'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                if (! Schema::hasColumn($table->getTable(), 'migration_reset_required')) {
                    $column = $table->boolean('migration_reset_required')->default(false);
                    if (Schema::hasColumn($table->getTable(), 'active')) {
                        $column->after('active');
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['email_accounts', 'ftp_accounts', 'web_dav_accounts', 'hosting_databases', 'database_grants'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                if (Schema::hasColumn($table->getTable(), 'migration_reset_required')) {
                    $table->dropColumn('migration_reset_required');
                }
            });
        }
    }
};
