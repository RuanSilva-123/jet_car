<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estoque de peças: catálogo (custo, preço de venda, quantidade) e movimentações.
 * A peça da OS pode apontar para o catálogo (part_id): ao entrar na OS dá baixa no estoque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('part_number', 60)->nullable()->index();   // código/referência
            $table->string('brand', 80)->nullable();
            $table->string('unit', 10)->default('un');                // un, L, kg, m, jogo...
            $table->unsignedBigInteger('cost_cents')->nullable();     // custo de compra
            $table->unsignedBigInteger('price_cents')->nullable();    // preço de venda
            $table->decimal('stock_quantity', 12, 2)->default(0);
            $table->decimal('min_stock', 12, 2)->default(0);          // abaixo disso: aviso de estoque baixo
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            // entry (compra), adjustment (contagem), order_out (baixa na OS), order_return (devolução da OS)
            $table->string('type', 20);
            $table->decimal('quantity', 12, 2);                       // positivo entra, negativo sai
            $table->decimal('balance_after', 12, 2);
            $table->unsignedBigInteger('unit_cost_cents')->nullable();
            $table->foreignId('service_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('service_order_parts', function (Blueprint $table) {
            $table->foreignId('part_id')->nullable()->after('service_order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_order_parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('part_id');
        });

        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('parts');
    }
};
