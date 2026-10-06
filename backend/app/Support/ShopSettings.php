<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Dados da oficina usados nos PDFs (cabeçalho, validade do orçamento, garantia, rodapé).
 * Editáveis pelo master em "Dados da oficina".
 */
final class ShopSettings
{
    private const KEY = 'shop';

    /**
     * @return array{name: string, document: string, phone: string, email: string, address: string,
     *     budget_validity_days: int, warranty_text: string, budget_notes: string}
     */
    public static function defaults(): array
    {
        return [
            'name' => 'JetCar Mecânica Automotiva',
            'document' => '',
            'phone' => '',
            'email' => '',
            'address' => '',
            'budget_validity_days' => 7,
            'warranty_text' => 'Garantia de 90 dias para os serviços executados, contados a partir da entrega do veículo, conforme o Código de Defesa do Consumidor (art. 26).',
            'budget_notes' => 'Valores sujeitos a alteração caso sejam identificados outros problemas durante o serviço; qualquer serviço adicional só será feito com a autorização do cliente.',
        ];
    }

    /**
     * @return array{name: string, document: string, phone: string, email: string, address: string,
     *     budget_validity_days: int, warranty_text: string, budget_notes: string}
     */
    public static function get(): array
    {
        $saved = Setting::find(self::KEY)?->value ?? [];

        return [...self::defaults(), ...array_intersect_key($saved, self::defaults())];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): array
    {
        Setting::updateOrCreate(['key' => self::KEY], ['value' => array_intersect_key($values, self::defaults())]);

        return self::get();
    }
}
