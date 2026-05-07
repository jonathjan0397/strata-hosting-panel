<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_forwarders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained();
            $table->string('source');                     // from@domain.com
            $table->string('destination');                // to@anywhere.com
            $table->timestamps();

            $table->unique(['source', 'destination']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_forwarders');
    }
};
