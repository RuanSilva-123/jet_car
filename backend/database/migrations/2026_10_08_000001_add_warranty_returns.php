<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retorno em garantia: a OS nova aponta para a OS original e cada serviço refeito aponta
 * para o serviço original (base do relatório "quais serviços mais voltam").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreignId('warranty_of_id')->nullable()->after('vehicle_id')->constrained('service_orders')->nullOnDelete();
        });

        Schema::table('service_order_items', function (Blueprint $table) {
            $table->foreignId('warranty_of_item_id')->nullable()->after('labor_service_id')->constrained('service_order_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warranty_of_item_id');
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warranty_of_id');
        });
    }
};
