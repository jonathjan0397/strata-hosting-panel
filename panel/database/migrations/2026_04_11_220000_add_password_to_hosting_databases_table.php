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

        DB::table('hosting_databases as hd')
            ->join('app_installations as ai', function ($join): void {
                $join->on('ai.account_id', '=', 'hd.account_id')
                    ->on('ai.db_name', '=', 'hd.db_name')
                    ->on('ai.db_user', '=', 'hd.db_user');
            })
            ->whereNull('hd.password')
            ->whereNotNull('ai.db_password')
            ->update([
                'hd.password' => DB::raw('ai.db_password'),
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
