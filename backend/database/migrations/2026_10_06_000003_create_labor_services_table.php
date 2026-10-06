<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de mão de obra (serviços que a oficina realiza). Sem valores por enquanto.
     */
    public function up(): void
    {
        Schema::create('labor_services', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 30)->default('other')->index();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labor_services');
    }
};
