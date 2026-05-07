<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('target_agent_version')->nullable()->after('agent_version');
            $table->timestamp('agent_upgrade_started_at')->nullable()->after('target_agent_version');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['target_agent_version', 'agent_upgrade_started_at']);
        });
    }
};
