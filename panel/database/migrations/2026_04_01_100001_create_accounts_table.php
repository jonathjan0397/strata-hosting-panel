<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained();
            $table->foreignId('reseller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('username')->unique();       // system username (e.g. jdoe)
            $table->string('plan')->nullable();         // plan slug
            $table->string('status')->default('active'); // active, suspended, terminated
            $table->string('php_version')->default('8.3');
            // Resource limits (0 = unlimited)
            $table->unsignedBigInteger('disk_limit_mb')->default(0);
            $table->unsignedBigInteger('bandwidth_limit_mb')->default(0);
            $table->unsignedSmallInteger('max_domains')->default(0);
            $table->unsignedSmallInteger('max_subdomains')->default(0);
            $table->unsignedSmallInteger('max_email_accounts')->default(0);
            $table->unsignedSmallInteger('max_databases')->default(0);
            $table->unsignedSmallInteger('max_ftp_accounts')->default(0);
            // Usage tracking
            $table->unsignedBigInteger('disk_used_mb')->default(0);
            $table->unsignedBigInteger('bandwidth_used_mb')->default(0);
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
