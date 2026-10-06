<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Saldo inicial do caixa: o valor que havia em caixa/banco numa data. Daí em diante o sistema
 * soma os recebimentos e desconta as contas pagas.
 */
final class FinanceSettings
{
    private const KEY = 'finance';

    /**
     * @return array{opening_balance_cents: int, opening_date: string|null}
     */
    public static function get(): array
    {
        $saved = Setting::find(self::KEY)?->value ?? [];

        return [
            'opening_balance_cents' => (int) ($saved['opening_balance_cents'] ?? 0),
            'opening_date' => $saved['opening_date'] ?? null,
        ];
    }

    /**
     * @param  array{opening_balance_cents: int, opening_date: string|null}  $values
     */
    public static function save(array $values): array
    {
        Setting::updateOrCreate(['key' => self::KEY], ['value' => [
            'opening_balance_cents' => (int) $values['opening_balance_cents'],
            'opening_date' => $values['opening_date'],
        ]]);

        return self::get();
    }
}
