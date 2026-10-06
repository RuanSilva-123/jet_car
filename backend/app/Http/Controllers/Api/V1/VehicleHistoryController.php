<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceOrderResource;
use App\Http\Resources\VehicleResource;
use App\Models\ServiceOrder;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Histórico do veículo: todas as ordens de serviço, com o que foi feito em cada uma.
 */
class VehicleHistoryController extends Controller
{
    public function __invoke(int $vehicle): JsonResponse
    {
        Gate::authorize('viewAny', ServiceOrder::class);

        // Veículo removido do cadastro continua com o histórico acessível
        $vehicle = Vehicle::withTrashed()->with(['customer' => fn ($query) => $query->withTrashed()])->findOrFail($vehicle);

        $orders = ServiceOrder::query()
            ->where('vehicle_id', $vehicle->id)
            ->with(['items.doneBy', 'parts', 'creator'])
            ->latest('id')
            ->get();

        return response()->json(['data' => [
            'vehicle' => (new VehicleResource($vehicle))->resolve(),
            'customer' => [
                'id' => $vehicle->customer->id,
                'name' => $vehicle->customer->trade_name ?: $vehicle->customer->name,
                'phone' => $vehicle->customer->phone,
                'deleted' => $vehicle->customer->trashed(),
            ],
            'orders' => ServiceOrderResource::collection($orders)->resolve(),
        ]]);
    }
}
