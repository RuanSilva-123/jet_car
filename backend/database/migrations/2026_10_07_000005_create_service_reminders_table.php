<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lembretes de revisão: o serviço do catálogo tem um intervalo ("troca de óleo a cada
 * 10 mil km ou 6 meses") e o scheduler gera a lista de clientes a contatar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('labor_services', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_months')->nullable()->after('is_active');
            $table->unsignedInteger('reminder_km')->nullable()->after('reminder_months');
        });

        Schema::create('service_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('labor_service_id')->nullable()->constrained()->nullOnDelete();
            // Serviço feito que originou o lembrete: um lembrete por execução
            $table->foreignId('source_item_id')->nullable()->unique()->constrained('service_order_items')->nullOnDelete();
            $table->string('service_name', 120);
            $table->date('last_done_at');
            $table->unsignedInteger('last_mileage')->nullable();
            $table->date('due_at')->nullable()->index();
            $table->unsignedInteger('due_mileage')->nullable();
            // pending → contacted → scheduled; dismissed (não quer); done (serviço refeito)
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('contacted_at')->nullable();
            $table->foreignId('contacted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reminders');

        Schema::table('labor_services', function (Blueprint $table) {
            $table->dropColumn(['reminder_months', 'reminder_km']);
        });
    }
};
