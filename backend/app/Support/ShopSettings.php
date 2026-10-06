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
     *     budget_validity_days: int, warranty_text: string, budget_notes: string, warranty_days: int,
     *     pix_key_type: string, pix_key: string, pix_beneficiary: string, pix_city: string}
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
            // Prazo da garantia em dias: identifica retornos em garantia
            'warranty_days' => 90,
            // Pix (copia e cola e QR Code no link do orçamento, na OS e no comprovante)
            'pix_key_type' => '',
            'pix_key' => '',
            'pix_beneficiary' => '',
            'pix_city' => '',
        ];
    }

    /**
     * @return array{name: string, document: string, phone: string, email: string, address: string,
     *     budget_validity_days: int, warranty_text: string, budget_notes: string, warranty_days: int,
     *     pix_key_type: string, pix_key: string, pix_beneficiary: string, pix_city: string}
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
        // Mescla com o que já está salvo: campo que não veio mantém o valor atual
        Setting::updateOrCreate(['key' => self::KEY], ['value' => array_intersect_key([...self::get(), ...$values], self::defaults())]);

        return self::get();
    }
}
