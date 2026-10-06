<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    /** O carro chegou e o agendamento virou OS. */
    case Arrived = 'arrived';
    case NoShow = 'no_show';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Agendado',
            self::Confirmed => 'Confirmado',
            self::Arrived => 'Chegou',
            self::NoShow => 'Não compareceu',
            self::Canceled => 'Cancelado',
        };
    }

    /** Ainda esperando o carro (pode editar e fazer o check-in). */
    public function isOpen(): bool
    {
        return $this === self::Scheduled || $this === self::Confirmed;
    }
}
