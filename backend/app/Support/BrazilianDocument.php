<?php

namespace App\Support;

/**
 * Validação de CPF e CNPJ pelos dígitos verificadores.
 *
 * O CNPJ aceita o formato alfanumérico (IN RFB 2.229/2024, emitido desde julho/2026):
 * 12 caracteres [A-Z0-9] + 2 dígitos verificadores numéricos. Cada caractere vale
 * (código ASCII − 48), o que mantém o cálculo idêntico para CNPJs só com números.
 */
final class BrazilianDocument
{
    /** Remove pontuação e deixa letras em maiúsculas: "12.ABC.345/01DE-35" → "12ABC34501DE35". */
    public static function normalize(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $value));
    }

    public static function isValidCpf(string $cpf): bool
    {
        if (! preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;
            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $cpf[$i] * ($position + 1 - $i);
            }
            $digit = ($sum * 10) % 11 % 10;

            if ((int) $cpf[$position] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function isValidCnpj(string $cnpj): bool
    {
        if (! preg_match('/^[A-Z0-9]{12}\d{2}$/', $cnpj) || preg_match('/^(.)\1{13}$/', $cnpj)) {
            return false;
        }

        $weights = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        foreach ([12, 13] as $length) {
            $sum = 0;
            $offset = 13 - $length;
            for ($i = 0; $i < $length; $i++) {
                $sum += (ord($cnpj[$i]) - 48) * $weights[$i + $offset];
            }
            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;

            if ((int) $cnpj[$length] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
