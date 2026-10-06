<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contas a pagar: fornecedores, contas (com parcelas) e despesas fixas que geram uma conta
 * por mês. Junto com os recebimentos das OS, formam o fluxo de caixa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('document', 14)->nullable()->index();   // CPF/CNPJ só dígitos
            $table->string('phone', 11)->nullable();
            $table->string('email')->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Despesa fixa (aluguel, internet...): gera uma conta a pagar todo mês
        Schema::create('recurring_bills', function (Blueprint $table) {
            $table->id();
            $table->string('description', 150);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedTinyInteger('day_of_month');              // 1–31 (31 = último dia)
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('description', 150);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30)->index();
            $table->unsignedBigInteger('amount_cents');
            $table->date('due_date')->index();
            $table->date('paid_at')->nullable()->index();
            $table->string('payment_method', 30)->nullable();
            $table->string('document_number', 60)->nullable();       // nota fiscal, boleto...
            $table->text('notes')->nullable();
            // Compra parcelada: mesmas parcelas compartilham o grupo
            $table->uuid('installment_group')->nullable()->index();
            $table->unsignedTinyInteger('installment_number')->nullable();
            $table->unsignedTinyInteger('installment_count')->nullable();
            $table->foreignId('recurring_bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Uma conta por mês para cada despesa fixa
            $table->unique(['recurring_bill_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
        Schema::dropIfExists('recurring_bills');
        Schema::dropIfExists('suppliers');
    }
};
