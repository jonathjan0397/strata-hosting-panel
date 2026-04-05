<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('php_upload_max', 10)->default('64M');
            $table->string('php_post_max', 10)->default('64M');
            $table->string('php_memory_limit', 10)->default('256M');
            $table->unsignedSmallInteger('php_max_exec_time')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['php_upload_max', 'php_post_max', 'php_memory_limit', 'php_max_exec_time']);
        });
    }
};
