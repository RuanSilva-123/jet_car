<?php

namespace App\Policies;

use App\Models\ServiceOrder;
use App\Models\User;

/**
 * Ordens de serviço são o dia a dia da oficina: toda a equipe abre, acompanha e atualiza.
 * Não há exclusão — uma OS que não vai acontecer é cancelada (e fica no histórico).
 */
class ServiceOrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, ServiceOrder $order): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return true;
    }

    public function update(User $actor, ServiceOrder $order): bool
    {
        return true;
    }
}
