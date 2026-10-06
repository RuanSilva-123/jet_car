<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agendamento de quem ainda não tem cadastro: nome, telefone e carro em texto livre.
 * O cadastro do cliente é feito no check-in (quando o carro chega e vira OS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->string('contact_name', 120)->nullable()->after('customer_id');
            $table->string('contact_phone', 11)->nullable()->after('contact_name');
            $table->string('vehicle_description', 120)->nullable()->after('vehicle_id');
        });
    }

    public function down(): void
    {
        // Agendamentos sem cliente não cabem mais na coluna obrigatória
        DB::table('appointments')->whereNull('customer_id')->delete();

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'contact_phone', 'vehicle_description']);
            $table->foreignId('customer_id')->nullable(false)->change();
        });
    }
};
