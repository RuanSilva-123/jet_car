<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Gestão de contas do painel: exclusiva do usuário master.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isMaster();
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->isMaster();
    }

    public function create(User $actor): bool
    {
        return $actor->isMaster();
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->isMaster();
    }

    public function delete(User $actor, User $user): Response
    {
        if (! $actor->isMaster()) {
            return Response::deny();
        }

        return $actor->is($user)
            ? Response::deny('Você não pode excluir a própria conta.')
            : Response::allow();
    }
}
