<?php

namespace App\Services\AddressLookup;

use App\Exceptions\ExternalServiceUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Endereço a partir do CEP (ViaCEP). CEPs encontrados ficam 30 dias em cache.
 */
class ViaCepClient
{
    /**
     * @return array{zip_code: string, street: string, complement: string, neighborhood: string, city: string, state: string}|null
     */
    public function lookup(string $zipCode): ?array
    {
        $cacheKey = "viacep:{$zipCode}";

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $response = Http::baseUrl(config('services.viacep.base_url'))
                ->acceptJson()
                ->timeout(8)
                ->get("{$zipCode}/json/");
        } catch (ConnectionException) {
            throw $this->unavailable();
        }

        // ViaCEP responde 400 para formato inválido e {"erro": true} para CEP inexistente
        if ($response->badRequest() || $response->json('erro')) {
            return null;
        }

        if ($response->failed()) {
            throw $this->unavailable();
        }

        $address = [
            'zip_code' => $zipCode,
            'street' => (string) $response->json('logradouro', ''),
            'complement' => (string) $response->json('complemento', ''),
            'neighborhood' => (string) $response->json('bairro', ''),
            'city' => (string) $response->json('localidade', ''),
            'state' => (string) $response->json('uf', ''),
        ];

        Cache::put($cacheKey, $address, now()->addDays(30));

        return $address;
    }

    private function unavailable(): ExternalServiceUnavailable
    {
        return new ExternalServiceUnavailable('A busca de CEP está indisponível no momento. Preencha o endereço manualmente.');
    }
}
