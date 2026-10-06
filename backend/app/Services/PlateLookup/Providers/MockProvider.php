<?php

namespace App\Services\PlateLookup\Providers;

use App\Services\PlateLookup\PlateLookupProvider;

/**
 * Mock provider for testing and homologation.
 */
class MockProvider implements PlateLookupProvider
{
    public function name(): string
    {
        return 'Mock (Homologação)';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function fetch(string $plate): ?array
    {
        return [
            'MARCA' => 'VW',
            'MODELO' => 'CROSSFOX',
            'SUBMODELO' => 'CROSSFOX',
            'VERSAO' => 'CROSSFOX',
            'ano' => '2007',
            'anoModelo' => '2007',
            'chassi' => '*****10137',
            'cor' => 'Prata',
            'data' => '20/07/2022 15:10:09',
            'extra' => [
                'ano_fabricacao' => '2007',
                'ano_modelo' => '2007',
                'combustivel' => 'Alcool / Gasolina',
                'tipo_doc_prop' => 'Fisica',
                'tipo_veiculo' => 'Automovel',
                'uf' => 'RS',
            ],
            'fipe' => ['dados' => [
                ['ano_modelo' => '2007', 'codigo_marca' => 59, 'codigo_modelo' => '1111', 'combustivel' => 'Gasolina', 'score' => 40,
                    'texto_marca' => 'VW - VolksWagen', 'texto_modelo' => 'CROSSFOX outro'],
                ['ano_modelo' => '2007', 'codigo_marca' => 59, 'codigo_modelo' => '2368', 'combustivel' => 'Gasolina', 'score' => 101,
                    'texto_marca' => 'VW - VolksWagen', 'texto_modelo' => 'CROSSFOX 1.6 Mi Total Flex 8V 5p'],
            ]],
            'municipio' => 'São Leopoldo',
            'placa' => strtoupper($plate),
            'uf' => 'RS',
        ];
    }
}
