<?php

namespace App\Enums;

enum ReminderStatus: string
{
    case Pending = 'pending';
    case Contacted = 'contacted';
    case Scheduled = 'scheduled';
    case Dismissed = 'dismissed';
    /** O serviço foi feito de novo: lembrete encerrado automaticamente. */
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'A contatar',
            self::Contacted => 'Contatado',
            self::Scheduled => 'Agendado',
            self::Dismissed => 'Descartado',
            self::Done => 'Serviço refeito',
        };
    }

    /** Ainda na lista de trabalho. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Contacted;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Contacted->value];
    }
}
