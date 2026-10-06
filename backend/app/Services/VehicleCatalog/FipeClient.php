<?php

namespace App\Services\VehicleCatalog;

use App\Enums\VehicleType;
use App\Exceptions\ExternalServiceUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Catálogo de marcas, modelos e anos da Tabela FIPE (API pública da Parallelum).
 *
 * A API sem token permite 500 requisições/dia, por isso toda resposta fica em cache
 * (padrão: 7 dias). Falhas não são cacheadas.
 */
class FipeClient
{
    /**
     * @return list<array{code: string, name: string}>
     */
    public function brands(VehicleType $type): array
    {
        return $this->fetch("{$type->fipePath()}/brands");
    }

    /**
     * Anos disponíveis de uma marca. Cada item indica ano + combustível,
     * ex.: {code: "2022-5", name: "2022 Flex"}. O ano 32000 é como a FIPE representa "Zero KM".
     *
     * @return list<array{code: string, name: string}>
     */
    public function years(VehicleType $type, string $brand): array
    {
        return $this->fetch("{$type->fipePath()}/brands/{$brand}/years");
    }

    /**
     * Modelos de uma marca em um ano/combustível (lista bem menor que a da marca inteira).
     *
     * @return list<array{code: string, name: string}>
     */
    public function models(VehicleType $type, string $brand, string $year): array
    {
        return $this->fetch("{$type->fipePath()}/brands/{$brand}/years/{$year}/models");
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function fetch(string $path): array
    {
        $ttl = now()->addDays(config('services.fipe.cache_days'));

        return Cache::remember("fipe:{$path}", $ttl, function () use ($path) {
            try {
                $response = Http::baseUrl(config('services.fipe.base_url'))
                    ->acceptJson()
                    ->timeout(10)
                    ->withHeaders(array_filter(['X-Subscription-Token' => config('services.fipe.token')]))
                    ->get($path);
            } catch (ConnectionException) {
                throw $this->unavailable();
            }

            if ($response->notFound()) {
                return [];
            }

            if ($response->failed() || ! is_array($response->json())) {
                throw $this->unavailable();
            }

            return collect($response->json())
                ->map(fn (array $item) => ['code' => (string) $item['code'], 'name' => trim((string) $item['name'])])
                ->values()
                ->all();
        });
    }

    private function unavailable(): ExternalServiceUnavailable
    {
        return new ExternalServiceUnavailable(
            'O catálogo de veículos (FIPE) está indisponível no momento. Preencha marca, modelo e ano manualmente.'
        );
    }
}
