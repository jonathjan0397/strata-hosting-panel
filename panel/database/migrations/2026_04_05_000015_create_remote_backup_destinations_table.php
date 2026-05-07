<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('remote_backup_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 10); // sftp | s3
            $table->text('config'); // JSON, encrypted
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_backup_destinations');
    }
};
