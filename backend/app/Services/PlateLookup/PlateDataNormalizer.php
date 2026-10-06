<?php

namespace App\Services\PlateLookup;

use App\Enums\FuelType;
use App\Enums\VehicleType;
use Illuminate\Support\Str;

/**
 * Converte a resposta dos provedores de placa para o formato do painel.
 *
 * Os provedores brasileiros revendem a mesma base e seguem o formato documentado pela
 * API Placas (MARCA, MODELO, anoModelo, cor, extra{...}, fipe{dados[...]}), com variações
 * de caixa e de envelope ({response: ...} / {data: ...}). Aqui a leitura é tolerante a isso.
 *
 * Dados do proprietário nunca são repassados.
 */
class PlateDataNormalizer
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *     plate: string, type: string|null, brand: string|null, model: string|null,
     *     model_year: int|null, manufacture_year: int|null, fuel: string|null, color: string|null,
     *     city: string|null, state: string|null,
     *     fipe: array{brand_code: string, model_code: string, model_year: int|null, fuel: string|null}|null
     * }|null
     */
    public function normalize(string $plate, array $raw): ?array
    {
        $vehicle = $this->unwrap($raw);
        $extra = $this->get($vehicle, ['extra']);
        $extra = is_array($extra) ? $extra : [];
        $fipe = $this->bestFipeMatch($vehicle);

        $brand = $this->text($fipe['texto_marca'] ?? null)
            ?? $this->text($this->get($vehicle, ['marca']))
            ?? $this->text($this->get($extra, ['marca']));

        $model = $this->text($fipe['texto_modelo'] ?? null)
            ?? $this->text($this->get($vehicle, ['versao', 'submodelo', 'modelo']))
            ?? $this->text($this->get($extra, ['modelo']));

        // Sem marca e modelo não há o que preencher
        if ($brand === null && $model === null) {
            return null;
        }

        $modelYear = $this->year($this->get($vehicle, ['anomodelo', 'ano_modelo']))
            ?? $this->year($this->get($extra, ['ano_modelo']));
        $manufactureYear = $this->year($this->get($extra, ['ano_fabricacao']))
            ?? $this->year($this->get($vehicle, ['ano', 'anofabricacao', 'ano_fabricacao']));

        $fuelLabel = $this->text($this->get($extra, ['combustivel']))
            ?? $this->text($this->get($vehicle, ['combustivel']))
            ?? $this->text($fipe['combustivel'] ?? null);

        $color = $this->text($this->get($vehicle, ['cor'])) ?? $this->text($this->get($extra, ['cor']));

        return [
            'plate' => $plate,
            'type' => $this->vehicleType($this->get($extra, ['tipo_veiculo', 'especie']) ?? $this->get($vehicle, ['tipo_veiculo', 'tipo']))?->value,
            'brand' => $brand,
            'model' => $model,
            'model_year' => $modelYear,
            'manufacture_year' => $manufactureYear,
            'fuel' => $this->fuel($fuelLabel)?->value,
            'color' => $color !== null ? Str::ucfirst(Str::lower($color)) : null,
            'city' => $this->text($this->get($vehicle, ['municipio'])),
            'state' => $this->text($this->get($vehicle, ['uf'])),
            'fipe' => $fipe && isset($fipe['codigo_marca'], $fipe['codigo_modelo']) ? [
                'brand_code' => (string) $fipe['codigo_marca'],
                'model_code' => (string) $fipe['codigo_modelo'],
                'model_year' => $this->year($fipe['ano_modelo'] ?? null) ?? $modelYear,
                'fuel' => $this->text($fipe['combustivel'] ?? null),
            ] : null,
        ];
    }

    /**
     * Remove envelopes comuns ({response: {...}}, {data: {...}}) até chegar no veículo.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function unwrap(array $raw): array
    {
        for ($depth = 0; $depth < 3; $depth++) {
            $inner = $this->get($raw, ['response', 'data', 'result', 'veiculo']);
            if (! is_array($inner) || array_is_list($inner)) {
                break;
            }
            $raw = $inner;
        }

        return $raw;
    }

    /**
     * Correspondência FIPE de maior "score" (o provedor pode devolver várias).
     *
     * @param  array<string, mixed>  $vehicle
     * @return array<string, mixed>|null
     */
    private function bestFipeMatch(array $vehicle): ?array
    {
        $fipe = $this->get($vehicle, ['fipe']);
        $entries = is_array($fipe) ? ($this->get($fipe, ['dados']) ?? $fipe) : [];

        if (! is_array($entries) || ! array_is_list($entries)) {
            return null;
        }

        return collect($entries)
            ->filter(fn ($entry) => is_array($entry))
            ->map(fn (array $entry) => array_change_key_case($entry))
            ->sortByDesc(fn (array $entry) => (int) ($entry['score'] ?? 0))
            ->first();
    }

    /**
     * Primeiro valor encontrado entre as chaves (sem diferenciar maiúsculas).
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function get(array $data, array $keys): mixed
    {
        $lower = array_change_key_case($data);

        foreach ($keys as $key) {
            if (array_key_exists($key, $lower) && $lower[$key] !== '' && $lower[$key] !== null) {
                return $lower[$key];
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function year(mixed $value): ?int
    {
        $year = is_numeric($value) ? (int) $value : null;

        return $year !== null && $year >= 1900 && $year <= (int) date('Y') + 1 ? $year : null;
    }

    private function fuel(?string $label): ?FuelType
    {
        $label = Str::lower(Str::ascii((string) $label));

        return match (true) {
            $label === '' => null,
            // Detran descreve flex como "Alcool / Gasolina" (com ou sem espaços, em qualquer ordem)
            Str::contains($label, 'flex'),
            Str::contains($label, ['alcool', 'etanol']) && Str::contains($label, 'gasolina') => FuelType::Flex,
            Str::contains($label, 'hibrid') => FuelType::Hybrid,
            Str::contains($label, 'eletric') => FuelType::Electric,
            Str::contains($label, 'diesel') => FuelType::Diesel,
            Str::contains($label, ['gas natural', 'gnv']) => FuelType::Cng,
            Str::contains($label, ['alcool', 'etanol']) => FuelType::Ethanol,
            Str::contains($label, 'gasolina') => FuelType::Gasoline,
            default => null,
        };
    }

    private function vehicleType(mixed $value): ?VehicleType
    {
        $type = Str::lower(Str::ascii((string) $value));

        return match (true) {
            $type === '' => null,
            Str::contains($type, ['motocicleta', 'motoneta', 'ciclomotor', 'triciclo', 'quadriciclo']) => VehicleType::Motorcycle,
            Str::contains($type, ['caminhao', 'onibus', 'micro-onibus', 'microonibus', 'trator']) => VehicleType::Truck,
            default => VehicleType::Car,
        };
    }
}
