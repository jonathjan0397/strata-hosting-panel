<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosting_databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('db_name')->unique();
            $table->string('db_user')->unique();
            $table->string('note')->nullable();     // optional description
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosting_databases');
    }
};
