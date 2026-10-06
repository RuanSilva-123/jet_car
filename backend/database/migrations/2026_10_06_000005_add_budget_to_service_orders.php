<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orçamento da OS: valor da mão de obra por serviço, peças e desconto.
 * Valores sempre em centavos (inteiros) para não haver erro de arredondamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('price_cents')->default(0)->after('notes');
        });

        Schema::create('service_order_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('part_number', 60)->nullable();   // código/referência da peça
            $table->decimal('quantity', 10, 2)->default(1);   // aceita fração (ex.: 4,5 litros de óleo)
            $table->unsignedBigInteger('unit_price_cents')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('service_orders', function (Blueprint $table) {
            // Totais recalculados a cada alteração (lista e PDF não precisam somar na hora)
            $table->unsignedBigInteger('labor_total_cents')->default(0)->after('expected_at');
            $table->unsignedBigInteger('parts_total_cents')->default(0)->after('labor_total_cents');
            $table->unsignedBigInteger('discount_cents')->default(0)->after('parts_total_cents');
            $table->unsignedBigInteger('total_cents')->default(0)->after('discount_cents');

            // Aprovação do orçamento pelo cliente
            $table->timestamp('budget_sent_at')->nullable()->after('total_cents');
            $table->timestamp('budget_approved_at')->nullable()->after('budget_sent_at');
            $table->unsignedBigInteger('budget_approved_total_cents')->nullable()->after('budget_approved_at');
        });

        // Configurações gerais (dados da oficina para os PDFs etc.)
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn([
                'labor_total_cents', 'parts_total_cents', 'discount_cents', 'total_cents',
                'budget_sent_at', 'budget_approved_at', 'budget_approved_total_cents',
            ]);
        });

        Schema::dropIfExists('service_order_parts');

        Schema::table('service_order_items', function (Blueprint $table) {
            $table->dropColumn('price_cents');
        });
    }
};
