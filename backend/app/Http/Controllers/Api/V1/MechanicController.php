<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** Mecânicos ativos: opções para atribuir os serviços da OS. */
class MechanicController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $mechanics = User::query()
            ->where('role', UserRole::Mechanic->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $mechanics]);
    }
}
