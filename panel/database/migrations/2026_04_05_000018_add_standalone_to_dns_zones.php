<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow DNS zones that are not tied to a hosted domain (server/standalone zones).
     * domain_id becomes nullable; account_id becomes nullable.
     * A zone_name unique index is added for standalone zones.
     */
    public function up(): void
    {
        Schema::table('dns_zones', function (Blueprint $table) {
            // Drop existing FK constraints before changing columns
            $table->dropForeign(['domain_id']);
            $table->dropForeign(['account_id']);
            $table->dropUnique(['domain_id']);

            $table->foreignId('domain_id')->nullable()->change();
            $table->foreignId('account_id')->nullable()->change();

            $table->foreign('domain_id')->references('id')->on('domains')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();

            // Ensure unique zone_name across all zones
            $table->unique('zone_name');
        });
    }

    public function down(): void
    {
        Schema::table('dns_zones', function (Blueprint $table) {
            $table->dropUnique(['zone_name']);
            $table->dropForeign(['domain_id']);
            $table->dropForeign(['account_id']);

            $table->foreignId('domain_id')->nullable(false)->change();
            $table->foreignId('account_id')->nullable(false)->change();

            $table->foreign('domain_id')->references('id')->on('domains')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->unique('domain_id');
        });
    }
};
