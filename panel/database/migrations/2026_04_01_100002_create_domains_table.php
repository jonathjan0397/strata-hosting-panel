<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained();
            $table->string('domain');
            $table->string('type')->default('main');    // main, addon, subdomain, parked
            $table->string('document_root');
            $table->string('web_server')->default('nginx'); // nginx, apache
            $table->string('php_version')->nullable();  // inherits from account if null
            $table->boolean('ssl_enabled')->default(false);
            $table->string('ssl_provider')->nullable(); // letsencrypt, zerossl, custom
            $table->timestamp('ssl_expires_at')->nullable();
            $table->boolean('dkim_enabled')->default(false);
            $table->text('dkim_public_key')->nullable();
            $table->boolean('spf_enabled')->default(false);
            $table->boolean('dmarc_enabled')->default(false);
            $table->json('custom_directives')->nullable(); // extra nginx/apache directives
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
