<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VehicleType;
use App\Http\Controllers\Controller;
use App\Services\VehicleCatalog\FipeClient;
use Illuminate\Http\JsonResponse;

/**
 * Proxy do catálogo FIPE para o painel (cache + limite de uso ficam no backend).
 * {type}: car | motorcycle | truck
 */
class VehicleCatalogController extends Controller
{
    public function __construct(private readonly FipeClient $fipe) {}

    public function brands(VehicleType $type): JsonResponse
    {
        return response()->json(['data' => $this->fipe->brands($type)]);
    }

    public function years(VehicleType $type, string $brand): JsonResponse
    {
        return response()->json(['data' => $this->fipe->years($type, $brand)]);
    }

    public function models(VehicleType $type, string $brand, string $year): JsonResponse
    {
        return response()->json(['data' => $this->fipe->models($type, $brand, $year)]);
    }
}
