<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            // AES-256-CBC encrypted mailbox password — used only for webmail SSO.
            // Null on legacy rows; populated on create and every password change.
            $table->text('password_encrypted')->nullable()->after('local_part');
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn('password_encrypted');
        });
    }
};
