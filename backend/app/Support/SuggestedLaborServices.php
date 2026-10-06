<?php

namespace App\Support;

use App\Enums\ServiceCategory;

/**
 * Lista inicial de serviços comuns em oficinas mecânicas, oferecida no painel
 * quando o catálogo está vazio. A oficina pode editar ou remover depois.
 */
final class SuggestedLaborServices
{
    /**
     * @return list<array{name: string, category: ServiceCategory}>
     */
    public static function all(): array
    {
        $services = [
            ServiceCategory::Maintenance->value => [
                'Revisão preventiva',
                'Troca de óleo e filtro de óleo',
                'Troca de filtro de ar',
                'Troca de filtro de combustível',
                'Troca de filtro de cabine',
                'Diagnóstico com scanner',
            ],
            ServiceCategory::Engine->value => [
                'Troca de correia dentada',
                'Troca de correia do alternador (poly-V)',
                'Troca de velas de ignição',
                'Troca de cabos de vela',
                'Limpeza de bicos injetores',
                'Limpeza do corpo de borboleta (TBI)',
                'Troca da junta do cabeçote',
                'Retífica de motor',
            ],
            ServiceCategory::Suspension->value => [
                'Troca de amortecedor',
                'Troca de mola',
                'Troca de bandeja',
                'Troca de pivô',
                'Troca de bieleta',
                'Troca de bucha da suspensão',
                'Troca de coxim do amortecedor',
            ],
            ServiceCategory::Brakes->value => [
                'Troca de pastilhas de freio',
                'Troca de discos de freio',
                'Troca de lonas de freio',
                'Troca de fluido de freio',
                'Regulagem do freio de mão',
            ],
            ServiceCategory::Transmission->value => [
                'Troca do kit de embreagem',
                'Troca de óleo do câmbio',
                'Troca de junta homocinética',
                'Troca de coifa da homocinética',
            ],
            ServiceCategory::Steering->value => [
                'Troca de terminal de direção',
                'Troca de barra axial',
                'Troca de óleo da direção hidráulica',
            ],
            ServiceCategory::Cooling->value => [
                'Troca de bomba d\'água',
                'Troca de válvula termostática',
                'Troca de radiador',
                'Troca de líquido de arrefecimento',
            ],
            ServiceCategory::Electrical->value => [
                'Troca de bateria',
                'Revisão do alternador',
                'Revisão do motor de partida',
                'Troca de lâmpadas',
            ],
            ServiceCategory::AirConditioning->value => [
                'Higienização do ar-condicionado',
                'Carga de gás do ar-condicionado',
            ],
            ServiceCategory::Exhaust->value => [
                'Troca de escapamento',
                'Troca de sonda lambda',
            ],
            ServiceCategory::Tires->value => [
                'Alinhamento',
                'Balanceamento',
                'Rodízio de pneus',
            ],
        ];

        $list = [];
        foreach ($services as $category => $names) {
            foreach ($names as $name) {
                $list[] = ['name' => $name, 'category' => ServiceCategory::from($category)];
            }
        }

        return $list;
    }
}
