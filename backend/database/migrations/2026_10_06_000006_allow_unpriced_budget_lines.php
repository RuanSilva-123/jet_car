<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serviços e peças entram na OS antes do orçamento: o valor fica "a definir" (null)
 * até o orçamento ser montado. Zero passa a significar "sem custo".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('price_cents')->nullable()->default(null)->change();
        });

        Schema::table('service_order_parts', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_price_cents')->nullable()->default(null)->change();
        });

        // OS que ainda não tiveram orçamento aprovado: zero era "não preenchido"
        $pending = DB::table('service_orders')->whereNull('budget_approved_at')->pluck('id');
        DB::table('service_order_items')->whereIn('service_order_id', $pending)->where('price_cents', 0)->update(['price_cents' => null]);
        DB::table('service_order_parts')->whereIn('service_order_id', $pending)->where('unit_price_cents', 0)->update(['unit_price_cents' => null]);
    }

    public function down(): void
    {
        DB::table('service_order_items')->whereNull('price_cents')->update(['price_cents' => 0]);
        DB::table('service_order_parts')->whereNull('unit_price_cents')->update(['unit_price_cents' => 0]);

        Schema::table('service_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('price_cents')->default(0)->nullable(false)->change();
        });

        Schema::table('service_order_parts', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_price_cents')->default(0)->nullable(false)->change();
        });
    }
};
