<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pesquisa de satisfação depois da entrega: nota de 0 a 10 (NPS) e comentário.
 * Uma resposta por OS, dada pelo cliente no link assinado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('survey_score')->nullable()->after('paid_cents');
            $table->text('survey_comment')->nullable()->after('survey_score');
            $table->timestamp('survey_answered_at')->nullable()->index()->after('survey_comment');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn(['survey_score', 'survey_comment', 'survey_answered_at']);
        });
    }
};
