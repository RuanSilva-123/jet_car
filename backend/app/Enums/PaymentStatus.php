<?php

namespace App\Enums;

/** Situação do pagamento da OS, calculada a partir do total e do que já foi pago. */
enum PaymentStatus: string
{
    /** Sem valor a cobrar (orçamento ainda vazio). */
    case None = 'none';
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sem valor',
            self::Pending => 'Em aberto',
            self::Partial => 'Pago em parte',
            self::Paid => 'Pago',
        };
    }

    public static function for(int $totalCents, int $paidCents): self
    {
        return match (true) {
            $totalCents <= 0 && $paidCents <= 0 => self::None,
            $paidCents >= $totalCents => self::Paid,
            $paidCents > 0 => self::Partial,
            default => self::Pending,
        };
    }
}
