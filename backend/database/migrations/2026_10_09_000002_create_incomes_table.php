<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outras entradas do caixa: dinheiro que não vem de OS (aporte, empréstimo, venda de um bem,
 * prêmio...). Prevista (expected_on) ou já recebida (received_at), entra no fluxo de caixa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->string('description', 150);
            $table->string('category', 30)->index();
            $table->unsignedBigInteger('amount_cents');
            $table->date('expected_on')->index();
            $table->date('received_at')->nullable()->index();
            $table->string('payment_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
