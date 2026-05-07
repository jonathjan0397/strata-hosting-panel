<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('database_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('db_name', 64);
            $table->string('db_user', 32);
            $table->string('password_hint', 255)->nullable();
            $table->timestamps();
            $table->unique(['db_name', 'db_user']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_grants');
    }
};
