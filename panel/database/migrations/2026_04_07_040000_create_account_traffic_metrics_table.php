<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('account_traffic_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('bandwidth_bytes')->default(0);
            $table->unsignedInteger('status_2xx')->default(0);
            $table->unsignedInteger('status_3xx')->default(0);
            $table->unsignedInteger('status_4xx')->default(0);
            $table->unsignedInteger('status_5xx')->default(0);
            $table->timestamps();

            $table->unique(['domain_id', 'date']);
            $table->index(['account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_traffic_metrics');
    }
};
