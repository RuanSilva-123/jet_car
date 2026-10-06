<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PlateLookup\PlateLookupService;
use Illuminate\Http\JsonResponse;

class PlateLookupController extends Controller
{
    public function __construct(private readonly PlateLookupService $plates) {}

    /** O painel só oferece o preenchimento pela placa quando há provedor configurado. */
    public function status(): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => $this->plates->isEnabled(),
            'provider' => $this->plates->isEnabled() ? $this->plates->provider()?->name() : null,
        ]]);
    }

    public function show(string $plate): JsonResponse
    {
        $plate = strtoupper($plate);

        if (! $this->plates->isEnabled()) {
            return response()->json(['message' => 'A consulta de placa não está configurada.'], 503);
        }

        $data = $this->plates->lookup($plate);

        return $data
            ? response()->json(['data' => $data])
            : response()->json(['message' => 'Não encontramos dados para esta placa. Preencha manualmente.'], 404);
    }
}
