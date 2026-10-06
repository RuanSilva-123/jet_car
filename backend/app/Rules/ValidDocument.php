<?php

namespace App\Rules;

use App\Enums\PersonType;
use App\Support\BrazilianDocument;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CPF para pessoa física, CNPJ (numérico ou alfanumérico) para pessoa jurídica.
 * Espera o valor já normalizado (sem pontuação).
 */
class ValidDocument implements ValidationRule
{
    public function __construct(private readonly ?PersonType $personType) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $document = (string) $value;

        $valid = match ($this->personType) {
            PersonType::Company => BrazilianDocument::isValidCnpj($document),
            PersonType::Individual => BrazilianDocument::isValidCpf($document),
            null => true, // tipo inválido já gera erro no próprio campo person_type
        };

        if (! $valid) {
            $fail($this->personType === PersonType::Company ? 'CNPJ inválido.' : 'CPF inválido.');
        }
    }
}
