<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // nginx | apache
            $table->string('web_server')->default('nginx')->after('is_primary');
            // JSON array: ["varnish", "redis", "memcached"]
            $table->json('accelerators')->nullable()->after('web_server');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['web_server', 'accelerators']);
        });
    }
};
