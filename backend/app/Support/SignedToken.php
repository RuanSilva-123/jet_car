<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Token de link público: "{id}.{expira}.{assinatura}". A assinatura é um HMAC (chave da
 * aplicação) do propósito + id + validade: não dá para trocar o id, estender o prazo nem usar
 * o link de um propósito (orçamento) em outro (avaliação).
 */
final class SignedToken
{
    public static function make(string $purpose, int $id, Carbon $expiresAt): string
    {
        return $id.'.'.$expiresAt->timestamp.'.'.self::sign($purpose, $id, $expiresAt->timestamp);
    }

    /**
     * Id assinado no token. 404 para link adulterado; 410 para link vencido.
     */
    public static function resolve(string $purpose, string $token, string $expiredMessage): int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            throw new NotFoundHttpException('Link inválido.');
        }

        [$id, $expires, $signature] = $parts;
        if (! hash_equals(self::sign($purpose, (int) $id, (int) $expires), $signature)) {
            throw new NotFoundHttpException('Link inválido.');
        }
        if ((int) $expires < now()->timestamp) {
            throw new HttpException(410, $expiredMessage);
        }

        return (int) $id;
    }

    private static function sign(string $purpose, int $id, int $expires): string
    {
        $key = (string) config('app.key');
        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        // 32 caracteres hex (128 bits) bastam e deixam a URL mais curta
        return substr(hash_hmac('sha256', "{$purpose}|{$id}|{$expires}", $key), 0, 32);
    }
}
