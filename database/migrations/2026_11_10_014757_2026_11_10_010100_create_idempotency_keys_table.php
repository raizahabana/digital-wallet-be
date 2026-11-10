<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable(); // null = still processing
            $table->json('response_body')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};