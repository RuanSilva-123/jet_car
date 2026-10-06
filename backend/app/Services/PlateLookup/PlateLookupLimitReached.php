<?php

namespace App\Services\PlateLookup;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Cota diária do provedor esgotada. */
class PlateLookupLimitReached extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'O limite diário de consultas de placa foi atingido. Preencha os dados do veículo manualmente.',
        ], 429);
    }
}
