<?php

namespace App\Services\PlateLookup\Providers;

use App\Exceptions\ExternalServiceUnavailable;
use App\Services\PlateLookup\PlateLookupLimitReached;
use App\Services\PlateLookup\PlateLookupProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * APIBrasil — plano gratuito com 100 consultas/dia (https://apibrasil.com.br).
 * POST {base}/vehicles/dados  body {"placa": "..."}  headers: Authorization Bearer + DeviceToken.
 */
class ApiBrasilProvider implements PlateLookupProvider
{
    public function name(): string
    {
        return 'APIBrasil';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.plate_lookup.apibrasil.bearer_token'))
            && filled(config('services.plate_lookup.apibrasil.device_token'));
    }

    public function fetch(string $plate): ?array
    {
        $config = config('services.plate_lookup.apibrasil');

        try {
            $response = Http::baseUrl($config['base_url'])
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->withToken($config['bearer_token'])
                ->withHeaders(['DeviceToken' => $config['device_token']])
                ->post('vehicles/dados', ['placa' => $plate]);
        } catch (ConnectionException) {
            throw $this->unavailable();
        }

        $body = $response->json();
        $status = $response->status();
        // A APIBrasil também sinaliza erro com {"error": true} em respostas 200
        $isError = $response->failed() || data_get($body, 'error') === true;
        $message = $isError ? Str::lower((string) data_get($body, 'message', '')) : '';

        if ($status === 429 || Str::contains($message, ['limite', 'limit', 'quota', 'cota'])) {
            throw new PlateLookupLimitReached;
        }

        if (in_array($status, [404, 406, 410], true) || Str::contains($message, ['não encontrad', 'nao encontrad', 'not found', 'sem resultado'])) {
            return null;
        }

        if (in_array($status, [401, 402, 403], true)) {
            Log::warning('Consulta de placa: credenciais da APIBrasil recusadas.', ['status' => $status]);
            throw $this->unavailable();
        }

        if ($isError || ! is_array($body)) {
            throw $this->unavailable();
        }

        return $body;
    }

    private function unavailable(): ExternalServiceUnavailable
    {
        return new ExternalServiceUnavailable('A consulta de placa está indisponível no momento. Preencha os dados do veículo manualmente.');
    }
}
