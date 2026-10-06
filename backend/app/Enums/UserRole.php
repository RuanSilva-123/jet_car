<?php

namespace App\Enums;

enum UserRole: string
{
    /** Dono do sistema: único que cria e gerencia contas. */
    case Master = 'master';

    /** Usuário do painel criado pelo master. */
    case Admin = 'admin';

    /** Mecânico: executa os serviços da OS; não acessa o financeiro. */
    case Mechanic = 'mechanic';

    public function label(): string
    {
        return match ($this) {
            self::Master => 'Administrador Master',
            self::Admin => 'Administrador',
            self::Mechanic => 'Mecânico',
        };
    }

    /** Pagamentos, contas a receber, relatórios e faturamento. */
    public function canManageFinance(): bool
    {
        return $this !== self::Mechanic;
    }
}
