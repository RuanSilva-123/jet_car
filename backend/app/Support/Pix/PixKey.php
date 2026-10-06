<?php

namespace App\Support\Pix;

use App\Support\BrazilianDocument;

/**
 * Chave Pix da oficina: valida e normaliza conforme o tipo, no formato que vai no BR Code.
 * CPF/CNPJ só dígitos; celular +55DDDNÚMERO; e-mail em minúsculas; aleatória (EVP) em UUID.
 */
final class PixKey
{
    public const TYPES = [
        'cnpj' => 'CNPJ',
        'cpf' => 'CPF',
        'phone' => 'Celular',
        'email' => 'E-mail',
        'random' => 'Chave aleatória',
    ];

    /** Chave normalizada ou null se for inválida para o tipo. */
    public static function normalize(string $type, string $value): ?string
    {
        $value = trim($value);

        return match ($type) {
            'cpf' => BrazilianDocument::isValidCpf($digits = preg_replace('/\D/', '', $value) ?? '') ? $digits : null,
            'cnpj' => BrazilianDocument::isValidCnpj($cnpj = BrazilianDocument::normalize($value)) ? $cnpj : null,
            'phone' => self::phone($value),
            'email' => filter_var($email = mb_strtolower($value), FILTER_VALIDATE_EMAIL) && strlen($email) <= 77 ? $email : null,
            'random' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid = mb_strtolower($value)) ? $uuid : null,
            default => null,
        };
    }

    /** Celular com DDD (11 dígitos), com ou sem +55. */
    private static function phone(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) === 13 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^[1-9]{2}9\d{8}$/', $digits) ? '+55'.$digits : null;
    }
}
