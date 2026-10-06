<?php

namespace App\Support\Pix;

use Illuminate\Support\Str;

/**
 * Pix "copia e cola" estático (BR Code, padrão EMV do Banco Central — Manual de Padrões
 * para Iniciação do Pix). Cada campo é ID (2) + tamanho (2) + valor; o último é o CRC16.
 *
 * Estático = não confirma o pagamento sozinho: a oficina confere no banco e registra o
 * recebimento na OS. O txid identifica a OS no extrato.
 */
final class PixPayload
{
    public function __construct(
        private readonly string $key,
        private readonly string $beneficiary,
        private readonly string $city,
        private readonly ?int $amountCents = null,
        private readonly ?string $txid = null,
    ) {}

    public function __toString(): string
    {
        return $this->build();
    }

    public function build(): string
    {
        $account = self::field('00', 'br.gov.bcb.pix').self::field('01', $this->key);

        $payload = self::field('00', '01')                      // versão do payload
            .self::field('26', $account)                         // conta Pix (chave)
            .self::field('52', '0000')                           // categoria do comerciante
            .self::field('53', '986')                            // moeda: real
            .($this->amountCents ? self::field('54', number_format($this->amountCents / 100, 2, '.', '')) : '')
            .self::field('58', 'BR')
            .self::field('59', self::text($this->beneficiary, 25))
            .self::field('60', self::text($this->city, 15))
            .self::field('62', self::field('05', self::txid($this->txid)))
            .'6304';

        return $payload.self::crc16($payload);
    }

    /** CRC-16/CCITT-FALSE (polinômio 0x1021, início 0xFFFF), em hexadecimal maiúsculo. */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return str_pad(strtoupper(dechex($crc)), 4, '0', STR_PAD_LEFT);
    }

    private static function field(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /** Nome e cidade: sem acento, em maiúsculas, no tamanho máximo do padrão. */
    private static function text(string $value, int $max): string
    {
        $ascii = preg_replace('/[^A-Z0-9 ]/', '', mb_strtoupper(Str::ascii($value))) ?? '';

        return mb_substr(trim(preg_replace('/\s+/', ' ', $ascii) ?? ''), 0, $max);
    }

    /** Identificador: só letras e números, até 25 caracteres ("***" = sem identificador). */
    private static function txid(?string $txid): string
    {
        $clean = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $txid) ?? '', 0, 25);

        return $clean === '' ? '***' : $clean;
    }
}
