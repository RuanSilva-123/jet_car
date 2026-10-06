<?php

namespace App\Services\PlateLookup;

use App\Exceptions\ExternalServiceUnavailable;

/**
 * Provedor externo de dados de veículo pela placa.
 */
interface PlateLookupProvider
{
    /** Nome exibido no painel. */
    public function name(): string;

    /** Tokens/credenciais presentes no .env. */
    public function isConfigured(): bool;

    /**
     * Resposta bruta do provedor, ou null quando a placa não foi encontrada.
     *
     * @return array<string, mixed>|null
     *
     * @throws PlateLookupLimitReached
     * @throws ExternalServiceUnavailable
     */
    public function fetch(string $plate): ?array;
}
