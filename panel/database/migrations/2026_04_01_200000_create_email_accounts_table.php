<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained();
            $table->string('email')->unique();             // user@domain.com
            $table->string('local_part');                  // just "user"
            $table->unsignedBigInteger('quota_mb')->default(0); // 0 = unlimited
            $table->unsignedBigInteger('used_mb')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['domain_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
