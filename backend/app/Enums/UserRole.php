<?php

namespace App\Enums;

enum UserRole: string
{
    /** Dono do sistema: único que cria e gerencia contas. */
    case Master = 'master';

    /** Usuário do painel criado pelo master. */
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Master => 'Administrador Master',
            self::Admin => 'Administrador',
        };
    }
}
