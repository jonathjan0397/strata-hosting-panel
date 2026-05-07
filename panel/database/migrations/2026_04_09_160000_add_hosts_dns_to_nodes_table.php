<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('nodes', 'hosts_dns')) {
            Schema::table('nodes', function (Blueprint $table) {
                $table->boolean('hosts_dns')->default(false)->after('is_primary');
            });
        }

        DB::table('nodes')->update(['hosts_dns' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('nodes', 'hosts_dns')) {
            Schema::table('nodes', function (Blueprint $table) {
                $table->dropColumn('hosts_dns');
            });
        }
    }
};
