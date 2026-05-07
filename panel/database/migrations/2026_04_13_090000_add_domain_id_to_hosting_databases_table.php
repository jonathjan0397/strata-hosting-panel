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

        DB::table('hosting_databases as hd')
            ->join('app_installations as ai', function ($join): void {
                $join->on('ai.account_id', '=', 'hd.account_id')
                    ->on('ai.db_name', '=', 'hd.db_name')
                    ->on('ai.db_user', '=', 'hd.db_user');
            })
            ->whereNull('hd.domain_id')
            ->whereNotNull('ai.domain_id')
            ->update([
                'hd.domain_id' => DB::raw('ai.domain_id'),
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
