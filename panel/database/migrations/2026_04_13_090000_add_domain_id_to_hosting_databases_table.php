<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosting_databases', function (Blueprint $table): void {
            if (! Schema::hasColumn('hosting_databases', 'domain_id')) {
                $table->foreignId('domain_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            }
        });

        DB::table('hosting_databases')
            ->whereNull('domain_id')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('app_installations')
                    ->whereColumn('app_installations.account_id', 'hosting_databases.account_id')
                    ->whereColumn('app_installations.db_name', 'hosting_databases.db_name')
                    ->whereColumn('app_installations.db_user', 'hosting_databases.db_user')
                    ->whereNotNull('app_installations.domain_id');
            })
            ->update([
                'domain_id' => DB::raw('(select app_installations.domain_id from app_installations where app_installations.account_id = hosting_databases.account_id and app_installations.db_name = hosting_databases.db_name and app_installations.db_user = hosting_databases.db_user and app_installations.domain_id is not null limit 1)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('hosting_databases', function (Blueprint $table): void {
            if (Schema::hasColumn('hosting_databases', 'domain_id')) {
                $table->dropConstrainedForeignId('domain_id');
            }
        });
    }
};
