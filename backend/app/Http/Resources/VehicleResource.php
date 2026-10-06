<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vehicle
 */
class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'brand' => $this->brand,
            'model' => $this->model,
            'model_year' => $this->model_year,
            'manufacture_year' => $this->manufacture_year,
            'fuel' => $this->fuel?->value,
            'fuel_label' => $this->fuel?->label(),
            'plate' => $this->plate,
            'color' => $this->color,
            'mileage' => $this->mileage,
            'vin' => $this->vin,
            'renavam' => $this->renavam,
            'fipe_brand_code' => $this->fipe_brand_code,
            'fipe_model_code' => $this->fipe_model_code,
            'fipe_year_code' => $this->fipe_year_code,
            'notes' => $this->notes,
        ];
    }
}
