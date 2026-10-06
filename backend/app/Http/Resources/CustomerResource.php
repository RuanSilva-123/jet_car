<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'person_type' => $this->person_type->value,
            'person_type_label' => $this->person_type->label(),
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'document' => $this->document,
            'state_registration' => $this->state_registration,
            'birth_date' => $this->birth_date?->toDateString(),
            'phone' => $this->phone,
            'phone_is_whatsapp' => $this->phone_is_whatsapp,
            'secondary_phone' => $this->secondary_phone,
            'email' => $this->email,
            'zip_code' => $this->zip_code,
            'street' => $this->street,
            'number' => $this->number,
            'complement' => $this->complement,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,
            'notes' => $this->notes,
            'vehicles_count' => $this->whenCounted('vehicles'),
            'vehicles' => VehicleResource::collection($this->whenLoaded('vehicles')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
