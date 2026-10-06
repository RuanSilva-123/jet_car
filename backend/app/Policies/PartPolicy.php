<?php

namespace App\Policies;

use App\Models\Part;
use App\Models\User;

/** Toda a equipe consulta e movimenta o estoque; excluir uma peça é só para o master. */
class PartPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Part $part): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return true;
    }

    public function update(User $actor, Part $part): bool
    {
        return true;
    }

    public function delete(User $actor, Part $part): bool
    {
        return $actor->isMaster();
    }
}
