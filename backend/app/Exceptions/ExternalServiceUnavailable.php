<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Serviço de terceiros (FIPE, ViaCEP...) fora do ar ou recusando requisições.
 * O painel trata o 503 permitindo o preenchimento manual.
 */
class ExternalServiceUnavailable extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 503);
    }
}
