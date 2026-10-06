<?php

namespace App\Support;

/** Formatação de dados brasileiros para documentos (PDFs). */
final class BrFormat
{
    public static function document(?string $value): string
    {
        $value = BrazilianDocument::normalize($value);

        return match (strlen($value)) {
            11 => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $value),
            14 => preg_replace('/^(\w{2})(\w{3})(\w{3})(\w{4})(\d{2})$/', '$1.$2.$3/$4-$5', $value),
            default => $value,
        };
    }

    public static function phone(?string $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return match (strlen($digits)) {
            11 => preg_replace('/^(\d{2})(\d{5})(\d{4})$/', '($1) $2-$3', $digits),
            10 => preg_replace('/^(\d{2})(\d{4})(\d{4})$/', '($1) $2-$3', $digits),
            default => $digits,
        };
    }

    /** Placa antiga com hífen (ABC-1234); Mercosul sem (ABC1D23). */
    public static function plate(?string $value): string
    {
        $value = strtoupper((string) $value);

        return preg_match('/^[A-Z]{3}\d{4}$/', $value) ? substr($value, 0, 3).'-'.substr($value, 3) : $value;
    }

    public static function zipCode(?string $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return strlen($digits) === 8 ? substr($digits, 0, 5).'-'.substr($digits, 5) : $digits;
    }

    public static function mileage(?int $value): string
    {
        return $value === null ? '' : number_format($value, 0, ',', '.').' km';
    }
}
