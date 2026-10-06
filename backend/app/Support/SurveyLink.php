<?php

namespace App\Support;

use App\Models\ServiceOrder;
use Illuminate\Support\Carbon;

/**
 * Link da pesquisa de satisfação, enviado depois da entrega (token assinado, ver SignedToken).
 * Vale por 30 dias a partir do envio.
 */
final class SurveyLink
{
    private const PURPOSE = 'survey';

    public const VALID_DAYS = 30;

    /**
     * @return array{token: string, url: string, expires_at: Carbon}
     */
    public static function make(ServiceOrder $order): array
    {
        $expiresAt = now()->addDays(self::VALID_DAYS)->endOfDay();
        $token = SignedToken::make(self::PURPOSE, $order->id, $expiresAt);

        return ['token' => $token, 'url' => url('/avaliacao/'.$token), 'expires_at' => $expiresAt];
    }

    public static function resolve(string $token): ServiceOrder
    {
        return ServiceOrder::findOrFail(SignedToken::resolve(self::PURPOSE, $token, 'Este link de avaliação expirou.'));
    }
}
