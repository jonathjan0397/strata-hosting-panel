<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname');
            $table->string('ip_address', 45);
            $table->unsignedSmallInteger('port')->default(8743);
            $table->string('node_id')->unique();        // UUID assigned on agent install
            $table->string('hmac_secret');              // shared secret for HMAC-SHA256
            $table->string('tls_fingerprint')->nullable(); // agent cert fingerprint
            $table->string('status')->default('unknown'); // online, offline, unknown, upgrading
            $table->string('agent_version')->nullable();
            $table->json('last_health')->nullable();    // last health check payload
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
