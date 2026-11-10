<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 15, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->timestamps();
        });

        // Non-negative balance enforced at the schema level.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_non_negative CHECK (balance >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};