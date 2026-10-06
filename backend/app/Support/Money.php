<?php

namespace App\Support;

/** Valores em centavos ↔ texto em reais. */
final class Money
{
    /** 123456 → "R$ 1.234,56" */
    public static function format(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    /** Quantidade × preço unitário, arredondado para o centavo. */
    public static function multiply(float|string $quantity, int $unitCents): int
    {
        return (int) round((float) $quantity * $unitCents);
    }

    /** 4.5 → "4,5"; 2.00 → "2" */
    public static function quantity(float|string $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 2, ',', '.'), '0'), ',');
    }
}
