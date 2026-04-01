<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('zone_name');           // e.g. "example.com"
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique('domain_id');           // one zone per domain
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_zones');
    }
};
