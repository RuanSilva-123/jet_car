<?php

namespace App\Enums;

enum ServiceOrderStatus: string
{
    /** Veículo recebido; orçamento sendo montado. */
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingApproval = 'waiting_approval';
    case WaitingParts = 'waiting_parts';
    /** Serviço pronto, aguardando retirada. */
    case Completed = 'completed';
    case Delivered = 'delivered';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::InProgress => 'Em andamento',
            self::WaitingApproval => 'Aguardando aprovação',
            self::WaitingParts => 'Aguardando peças',
            self::Completed => 'Concluída',
            self::Delivered => 'Entregue',
            self::Canceled => 'Cancelada',
        };
    }

    /** Status de execução do serviço: só depois que o cliente aprovou o orçamento. */
    public function requiresApprovedBudget(): bool
    {
        return in_array($this, [self::InProgress, self::WaitingParts, self::Completed, self::Delivered], true);
    }

    /** OS encerrada: não aceita edição de dados/serviços (pode ser reaberta). */
    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Canceled], true);
    }

    /**
     * Status que contam como "em aberto" na oficina.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => ! $status->isFinal()),
        ));
    }
}
