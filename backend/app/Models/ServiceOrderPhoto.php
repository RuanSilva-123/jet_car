<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Foto do veículo tirada na vistoria de entrada (arquivo no disco privado "local"). */
#[Fillable(['path', 'caption', 'size', 'created_by'])]
class ServiceOrderPhoto extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'datetime'];
    }
}
