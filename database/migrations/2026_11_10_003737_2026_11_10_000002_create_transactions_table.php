<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['deposit', 'withdrawal', 'transfer_in', 'transfer_out']);
            $table->decimal('amount', 15, 2);
            $table->uuid('reference_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};