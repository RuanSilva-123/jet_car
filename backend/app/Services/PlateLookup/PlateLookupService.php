<?php

namespace App\Services\PlateLookup;

use App\Services\PlateLookup\Providers\ApiBrasilProvider;
use App\Services\PlateLookup\Providers\ApiPlacasProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Consulta de veículo pela placa com cache (cada placa consome a cota uma vez a cada N dias).
 */
class PlateLookupService
{
    public function __construct(private readonly PlateDataNormalizer $normalizer) {}

    public function provider(): ?PlateLookupProvider
    {
        return match (config('services.plate_lookup.driver')) {
            'apibrasil' => app(ApiBrasilProvider::class),
            'apiplacas' => app(ApiPlacasProvider::class),
            'mock' => app(\App\Services\PlateLookup\Providers\MockProvider::class),
            default => null,
        };
    }

    public function isEnabled(): bool
    {
        return (bool) $this->provider()?->isConfigured();
    }

    /**
     * Dados normalizados do veículo, ou null se a placa não foi encontrada.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(string $plate): ?array
    {
        $cacheKey = "plate-lookup:{$plate}";

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $raw = $this->provider()?->fetch($plate);
        $data = $raw ? $this->normalizer->normalize($plate, $raw) : null;

        // Só resultados encontrados vão para o cache
        if ($data !== null) {
            Cache::put($cacheKey, $data, now()->addDays(config('services.plate_lookup.cache_days')));
        }

        return $data;
    }
}
