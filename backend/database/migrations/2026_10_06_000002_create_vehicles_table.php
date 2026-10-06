<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('car');

            // Marca/modelo gravados como texto: o histórico não depende da FIPE continuar no ar
            $table->string('brand', 80);
            $table->string('model', 150);
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->unsignedSmallInteger('manufacture_year')->nullable();
            $table->string('fuel', 20)->nullable();

            // Identificação do veículo
            $table->string('plate', 7)->nullable()->index();
            $table->string('color', 40)->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('vin', 17)->nullable();
            $table->string('renavam', 11)->nullable();

            // Códigos da Tabela FIPE (quando o veículo foi escolhido pelo catálogo)
            $table->string('fipe_brand_code', 10)->nullable();
            $table->string('fipe_model_code', 10)->nullable();
            $table->string('fipe_year_code', 10)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
