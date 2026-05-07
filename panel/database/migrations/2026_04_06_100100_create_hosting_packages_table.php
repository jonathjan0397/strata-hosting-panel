<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosting_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('feature_list_id')->nullable()->constrained()->nullOnDelete();
            $table->string('php_version')->default('8.4');
            $table->unsignedBigInteger('disk_limit_mb')->default(0);
            $table->unsignedBigInteger('bandwidth_limit_mb')->default(0);
            $table->unsignedSmallInteger('max_domains')->default(0);
            $table->unsignedSmallInteger('max_subdomains')->default(0);
            $table->unsignedSmallInteger('max_email_accounts')->default(0);
            $table->unsignedSmallInteger('max_databases')->default(0);
            $table->unsignedSmallInteger('max_ftp_accounts')->default(0);
            $table->boolean('available_to_resellers')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosting_packages');
    }
};
