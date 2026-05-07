<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();

            $table->string('app_slug', 50);          // wordpress, joomla, drupal, piwigo, phpbb
            $table->string('install_path', 255);      // relative to docroot: "/" or "/blog"
            $table->string('install_dir', 500);       // absolute path on server

            $table->string('db_name', 64)->nullable();
            $table->string('db_user', 64)->nullable();
            $table->text('db_password')->nullable();  // encrypted

            $table->string('site_url', 500);
            $table->string('site_title', 255)->nullable();
            $table->string('admin_email', 255)->nullable();

            // For assisted installs — URL user visits to complete web installer
            $table->string('setup_url', 500)->nullable();

            $table->string('installed_version', 30)->nullable();
            $table->string('latest_version', 30)->nullable();
            $table->boolean('update_available')->default(false);
            $table->boolean('auto_update')->default(true);

            // queued | installing | active | updating | error
            $table->string('status', 20)->default('queued');
            $table->text('error_message')->nullable();

            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index('node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_installations');
    }
};
