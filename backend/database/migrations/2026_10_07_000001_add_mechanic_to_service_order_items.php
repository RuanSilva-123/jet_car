<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Responsável (mecânico) por cada serviço da OS: base do relatório de produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_items', function (Blueprint $table) {
            $table->foreignId('mechanic_id')->nullable()->after('done_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mechanic_id');
        });
    }
};
