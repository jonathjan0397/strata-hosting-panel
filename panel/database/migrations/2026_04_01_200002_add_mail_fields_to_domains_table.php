<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('mail_enabled')->default(false)->after('dmarc_enabled');
            $table->text('dkim_dns_record')->nullable()->after('dkim_public_key');
            $table->string('spf_dns_record')->nullable()->after('dkim_dns_record');
            $table->string('dmarc_dns_record')->nullable()->after('spf_dns_record');
            $table->string('server_ip')->nullable()->after('dmarc_dns_record');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['mail_enabled', 'dkim_dns_record', 'spf_dns_record', 'dmarc_dns_record', 'server_ip']);
        });
    }
};
