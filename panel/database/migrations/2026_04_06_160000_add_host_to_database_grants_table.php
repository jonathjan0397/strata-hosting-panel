<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('database_grants', function (Blueprint $table) {
            $table->dropUnique(['db_name', 'db_user']);
            $table->string('host', 255)->default('localhost')->after('db_user');
            $table->unique(['db_name', 'db_user', 'host']);
        });
    }

    public function down(): void
    {
        Schema::table('database_grants', function (Blueprint $table) {
            $table->dropUnique(['db_name', 'db_user', 'host']);
            $table->dropColumn('host');
            $table->unique(['db_name', 'db_user']);
        });
    }
};
