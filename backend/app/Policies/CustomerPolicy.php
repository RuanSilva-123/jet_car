<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Toda a equipe cadastra e edita clientes; excluir é só para o master.
 */
class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Customer $customer): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return true;
    }

    public function update(User $actor, Customer $customer): bool
    {
        return true;
    }

    public function delete(User $actor, Customer $customer): bool
    {
        return $actor->isMaster();
    }
}
