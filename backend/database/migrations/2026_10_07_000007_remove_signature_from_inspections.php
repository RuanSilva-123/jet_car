<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * A oficina não usa assinatura na vistoria: remove as colunas e os arquivos de assinatura
 * já gravados. A vistoria continua com combustível, avarias, itens, pertences e fotos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $paths = DB::table('service_order_inspections')->whereNotNull('signature_path')->pluck('signature_path')->all();
        if ($paths !== []) {
            Storage::disk('local')->delete($paths);
        }

        Schema::table('service_order_inspections', function (Blueprint $table) {
            $table->dropColumn(['signed_name', 'signature_path', 'signed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('service_order_inspections', function (Blueprint $table) {
            $table->string('signed_name', 120)->nullable()->after('notes');
            $table->string('signature_path')->nullable()->after('signed_name');
            $table->timestamp('signed_at')->nullable()->after('signature_path');
        });
    }
};
