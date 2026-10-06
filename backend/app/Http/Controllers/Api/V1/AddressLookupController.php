<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AddressLookup\ViaCepClient;
use Illuminate\Http\JsonResponse;

class AddressLookupController extends Controller
{
    public function __invoke(string $zipCode, ViaCepClient $viaCep): JsonResponse
    {
        $address = $viaCep->lookup($zipCode);

        return $address
            ? response()->json(['data' => $address])
            : response()->json(['message' => 'CEP não encontrado.'], 404);
    }
}
