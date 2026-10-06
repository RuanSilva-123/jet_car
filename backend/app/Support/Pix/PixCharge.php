<?php

namespace App\Support\Pix;

use App\Models\ServiceOrder;
use App\Support\ShopSettings;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Cobrança Pix de uma OS: copia e cola + QR Code (PNG em data URI) com a chave cadastrada
 * nos dados da oficina. Sem chave cadastrada, não há cobrança (null).
 */
final class PixCharge
{
    /**
     * @return array{payload: string, qr_code: string, amount_cents: int, txid: string, key: string, key_type: string, beneficiary: string}|null
     */
    public static function forOrder(ServiceOrder $order, ?int $amountCents = null): ?array
    {
        $amount = $amountCents ?? max(0, $order->balanceCents());

        return $amount > 0 ? self::make($amount, 'OS'.$order->number()) : null;
    }

    /**
     * @return array{payload: string, qr_code: string, amount_cents: int, txid: string, key: string, key_type: string, beneficiary: string}|null
     */
    public static function make(int $amountCents, string $txid): ?array
    {
        $shop = ShopSettings::get();
        if (! self::configured($shop)) {
            return null;
        }

        $payload = (new PixPayload(
            $shop['pix_key'],
            $shop['pix_beneficiary'] ?: $shop['name'],
            $shop['pix_city'],
            $amountCents,
            $txid,
        ))->build();

        return [
            'payload' => $payload,
            'qr_code' => self::qrCode($payload),
            'amount_cents' => $amountCents,
            'txid' => $txid,
            'key' => $shop['pix_key'],
            'key_type' => $shop['pix_key_type'],
            'beneficiary' => $shop['pix_beneficiary'] ?: $shop['name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $shop
     */
    public static function configured(array $shop): bool
    {
        return ($shop['pix_key'] ?? '') !== '' && ($shop['pix_city'] ?? '') !== '';
    }

    private static function qrCode(string $payload): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'eccLevel' => EccLevel::M,
            'scale' => 6,
            'quietzoneSize' => 2,
            'outputBase64' => true,
        ]);

        return (new QRCode($options))->render($payload);
    }
}
