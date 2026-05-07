<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('brand_name')->nullable();  // resellers only: shown to their clients
            $table->string('brand_color', 7)->nullable(); // hex, e.g. #6366f1
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['brand_name', 'brand_color']);
        });
    }
};
