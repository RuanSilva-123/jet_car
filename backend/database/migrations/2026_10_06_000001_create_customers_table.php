<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('person_type', 20)->default('individual');

            // Identificação (PF: nome/CPF/nascimento | PJ: razão social/fantasia/CNPJ/IE)
            $table->string('name', 150);
            $table->string('trade_name', 150)->nullable();
            $table->string('document', 14)->nullable()->index();
            $table->string('state_registration', 20)->nullable();
            $table->date('birth_date')->nullable();

            // Contato (telefones só com dígitos: DDD + número)
            $table->string('phone', 11);
            $table->boolean('phone_is_whatsapp')->default(true);
            $table->string('secondary_phone', 11)->nullable();
            $table->string('email')->nullable();

            // Endereço
            $table->string('zip_code', 8)->nullable();
            $table->string('street', 150)->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement', 100)->nullable();
            $table->string('neighborhood', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('state', 2)->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
