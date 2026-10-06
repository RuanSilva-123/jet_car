<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vistoria na entrada do veículo: combustível, avarias, itens deixados no carro, fotos e a
 * assinatura do cliente. Depois de assinada não muda (protege a oficina em reclamações).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('fuel_level')->nullable();   // 0 = reserva … 4 = cheio (quartos)
            $table->json('damages')->nullable();                     // [{area, type, notes}]
            $table->json('checklist')->nullable();                   // itens conferidos: estepe, macaco...
            $table->text('belongings')->nullable();                  // pertences deixados no carro
            $table->text('notes')->nullable();
            $table->string('signed_name', 120)->nullable();
            $table->string('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('service_order_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption', 150)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_photos');
        Schema::dropIfExists('service_order_inspections');
    }
};
