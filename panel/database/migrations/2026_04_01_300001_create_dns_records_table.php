<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_zone_id')->constrained()->cascadeOnDelete();
            $table->string('name');                // host part: "@", "www", "_dmarc", etc.
            $table->string('type', 10);            // A, AAAA, CNAME, MX, TXT, SRV, CAA
            $table->integer('ttl')->default(300);
            $table->text('value');                 // record content
            $table->unsignedSmallInteger('priority')->default(0); // MX/SRV priority
            $table->boolean('managed')->default(false); // system-generated (DKIM etc.)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
