<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('email_accounts', 'password_encrypted')) {
            return;
        }

        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn('password_encrypted');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_accounts', 'password_encrypted')) {
            return;
        }

        Schema::table('email_accounts', function (Blueprint $table) {
            $table->text('password_encrypted')->nullable()->after('local_part');
        });
    }
};
