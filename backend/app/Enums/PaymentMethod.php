<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Pix = 'pix';
    case Cash = 'cash';
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';
    case BankTransfer = 'bank_transfer';
    case BankSlip = 'bank_slip';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'Pix',
            self::Cash => 'Dinheiro',
            self::CreditCard => 'Cartão de crédito',
            self::DebitCard => 'Cartão de débito',
            self::BankTransfer => 'Transferência',
            self::BankSlip => 'Boleto',
            self::Other => 'Outro',
        };
    }

    /** Só o cartão de crédito é parcelado. */
    public function allowsInstallments(): bool
    {
        return $this === self::CreditCard;
    }
}
