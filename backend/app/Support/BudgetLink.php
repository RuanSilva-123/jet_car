<?php

namespace App\Support;

use App\Models\ServiceOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Link público do orçamento, enviado ao cliente pelo WhatsApp.
 *
 * O token é "{id}.{expira}.{assinatura}": a assinatura é um HMAC (chave da aplicação) do id
 * e da validade, então não dá para trocar o número da OS nem estender o prazo sem invalidar
 * o link. A validade acompanha a do orçamento (budget_validity_days nos dados da oficina).
 */
final class BudgetLink
{
    /**
     * @return array{token: string, url: string, expires_at: Carbon}
     */
    public static function make(ServiceOrder $order): array
    {
        $expiresAt = now()->addDays((int) ShopSettings::get()['budget_validity_days'])->endOfDay();
        $token = $order->id.'.'.$expiresAt->timestamp.'.'.self::sign($order->id, $expiresAt->timestamp);

        return ['token' => $token, 'url' => url('/orcamento/'.$token), 'expires_at' => $expiresAt];
    }

    /**
     * OS do token. 404 para link adulterado; 410 para link vencido.
     */
    public static function resolve(string $token): ServiceOrder
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            throw new NotFoundHttpException('Link inválido.');
        }

        [$id, $expires, $signature] = $parts;
        if (! hash_equals(self::sign((int) $id, (int) $expires), $signature)) {
            throw new NotFoundHttpException('Link inválido.');
        }
        if ((int) $expires < now()->timestamp) {
            throw new HttpException(410, 'Este link expirou. Peça um novo orçamento à oficina.');
        }

        return ServiceOrder::findOrFail((int) $id);
    }

    private static function sign(int $id, int $expires): string
    {
        $key = (string) config('app.key');
        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        // 32 caracteres hex (128 bits) bastam e deixam a URL mais curta
        return substr(hash_hmac('sha256', "budget-link|{$id}|{$expires}", $key), 0, 32);
    }
}
