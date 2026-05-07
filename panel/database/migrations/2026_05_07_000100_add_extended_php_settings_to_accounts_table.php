<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('php_max_input_time')->default(60)->after('php_max_exec_time');
            $table->unsignedSmallInteger('php_max_input_vars')->default(1000)->after('php_max_input_time');
            $table->unsignedSmallInteger('php_max_file_uploads')->default(20)->after('php_max_input_vars');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['php_max_input_time', 'php_max_input_vars', 'php_max_file_uploads']);
        });
    }
};
