<?php

namespace App\Support;

use App\Models\ServiceOrder;
use Illuminate\Support\Carbon;

/**
 * Link público do orçamento, enviado ao cliente pelo WhatsApp (token assinado, ver SignedToken).
 * A validade acompanha a do orçamento (budget_validity_days nos dados da oficina).
 */
final class BudgetLink
{
    private const PURPOSE = 'budget-link';

    /**
     * @return array{token: string, url: string, expires_at: Carbon}
     */
    public static function make(ServiceOrder $order): array
    {
        $expiresAt = now()->addDays((int) ShopSettings::get()['budget_validity_days'])->endOfDay();
        $token = SignedToken::make(self::PURPOSE, $order->id, $expiresAt);

        return ['token' => $token, 'url' => url('/orcamento/'.$token), 'expires_at' => $expiresAt];
    }

    /** OS do token. 404 para link adulterado; 410 para link vencido. */
    public static function resolve(string $token): ServiceOrder
    {
        return ServiceOrder::findOrFail(SignedToken::resolve(self::PURPOSE, $token, 'Este link expirou. Peça um novo orçamento à oficina.'));
    }
}
