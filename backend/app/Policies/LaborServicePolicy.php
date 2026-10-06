<?php

namespace App\Policies;

use App\Models\LaborService;
use App\Models\User;

/**
 * Toda a equipe consulta e mantém o catálogo de serviços; excluir é só para o master
 * (no dia a dia, prefira desativar o serviço).
 */
class LaborServicePolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, LaborService $service): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return true;
    }

    public function update(User $actor, LaborService $service): bool
    {
        return true;
    }

    public function delete(User $actor, LaborService $service): bool
    {
        return $actor->isMaster();
    }
}
