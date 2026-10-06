<?php

namespace App\Services\PlateLookup\Providers;

use App\Exceptions\ExternalServiceUnavailable;
use App\Services\PlateLookup\PlateLookupLimitReached;
use App\Services\PlateLookup\PlateLookupProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * API Placas (https://apiplacas.com.br) — GET {base}/consulta/{placa}/{token}.
 * Códigos: 200 ok · 401 placa inválida · 402 token inválido · 406 sem resultados · 429 limite.
 */
class ApiPlacasProvider implements PlateLookupProvider
{
    public function name(): string
    {
        return 'API Placas';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.plate_lookup.apiplacas.token'));
    }

    public function fetch(string $plate): ?array
    {
        $config = config('services.plate_lookup.apiplacas');

        try {
            $response = Http::baseUrl($config['base_url'])
                ->acceptJson()
                ->timeout(15)
                ->get("consulta/{$plate}/{$config['token']}");
        } catch (ConnectionException) {
            throw $this->unavailable();
        }

        return match (true) {
            $response->status() === 429 => throw new PlateLookupLimitReached,
            in_array($response->status(), [401, 404, 406], true) => null,
            $response->status() === 402 => $this->invalidToken(),
            $response->failed() || ! is_array($response->json()) => throw $this->unavailable(),
            default => $response->json(),
        };
    }

    private function invalidToken(): never
    {
        Log::warning('Consulta de placa: token da API Placas recusado.');

        throw $this->unavailable();
    }

    private function unavailable(): ExternalServiceUnavailable
    {
        return new ExternalServiceUnavailable('A consulta de placa está indisponível no momento. Preencha os dados do veículo manualmente.');
    }
}
