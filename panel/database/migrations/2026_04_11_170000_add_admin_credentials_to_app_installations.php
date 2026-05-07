<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_installations', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_installations', 'admin_username')) {
                $table->string('admin_username', 60)->nullable()->after('admin_email');
            }

            if (! Schema::hasColumn('app_installations', 'admin_password')) {
                $table->text('admin_password')->nullable()->after('admin_username');
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_installations', function (Blueprint $table): void {
            if (Schema::hasColumn('app_installations', 'admin_password')) {
                $table->dropColumn('admin_password');
            }

            if (Schema::hasColumn('app_installations', 'admin_username')) {
                $table->dropColumn('admin_username');
            }
        });
    }
};
