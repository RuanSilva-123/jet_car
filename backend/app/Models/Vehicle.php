<?php

namespace App\Models;

use App\Enums\FuelType;
use App\Enums\VehicleType;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'type', 'brand', 'model', 'model_year', 'manufacture_year', 'fuel',
    'plate', 'color', 'mileage', 'vin', 'renavam',
    'fipe_brand_code', 'fipe_model_code', 'fipe_year_code', 'notes',
])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VehicleType::class,
            'fuel' => FuelType::class,
            'model_year' => 'integer',
            'manufacture_year' => 'integer',
            'mileage' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
