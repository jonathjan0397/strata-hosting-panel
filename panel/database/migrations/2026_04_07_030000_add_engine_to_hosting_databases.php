<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hosting_databases', function (Blueprint $table) {
            $table->string('engine', 24)->default('mysql')->after('node_id');
        });

        Schema::table('database_grants', function (Blueprint $table) {
            $table->string('engine', 24)->default('mysql')->after('node_id');
        });
    }

    public function down(): void
    {
        Schema::table('database_grants', function (Blueprint $table) {
            $table->dropColumn('engine');
        });

        Schema::table('hosting_databases', function (Blueprint $table) {
            $table->dropColumn('engine');
        });
    }
};
