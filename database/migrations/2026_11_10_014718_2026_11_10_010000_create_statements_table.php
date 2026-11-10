<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('queued')->index(); // queued | processing | completed | failed
            $table->string('file_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statements');
    }
};