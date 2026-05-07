<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_installations', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_installations', 'migration_verification_required')) {
                $table->boolean('migration_verification_required')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_installations', function (Blueprint $table): void {
            if (Schema::hasColumn('app_installations', 'migration_verification_required')) {
                $table->dropColumn('migration_verification_required');
            }
        });
    }
};
