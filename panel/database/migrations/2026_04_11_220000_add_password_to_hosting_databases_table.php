<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hosting_databases', 'password')) {
            Schema::table('hosting_databases', function (Blueprint $table): void {
                $table->text('password')->nullable()->after('db_user');
            });
        }

        DB::table('hosting_databases')
            ->whereNull('password')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('app_installations')
                    ->whereColumn('app_installations.account_id', 'hosting_databases.account_id')
                    ->whereColumn('app_installations.db_name', 'hosting_databases.db_name')
                    ->whereColumn('app_installations.db_user', 'hosting_databases.db_user')
                    ->whereNotNull('app_installations.db_password');
            })
            ->update([
                'password' => DB::raw('(select app_installations.db_password from app_installations where app_installations.account_id = hosting_databases.account_id and app_installations.db_name = hosting_databases.db_name and app_installations.db_user = hosting_databases.db_user and app_installations.db_password is not null limit 1)'),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('hosting_databases', 'password')) {
            Schema::table('hosting_databases', function (Blueprint $table): void {
                $table->dropColumn('password');
            });
        }
    }
};
