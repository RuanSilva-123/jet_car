<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recebimentos da OS (Pix, dinheiro, cartão...). O total pago fica gravado na OS (paid_cents)
 * para a tela de contas a receber filtrar sem somar na hora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
            $table->string('method', 30);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedTinyInteger('installments')->default(1);   // parcelas no cartão
            $table->date('paid_at')->index();
            $table->string('notes', 500)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('paid_cents')->default(0)->after('total_cents');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn('paid_cents');
        });

        Schema::dropIfExists('service_order_payments');
    }
};
