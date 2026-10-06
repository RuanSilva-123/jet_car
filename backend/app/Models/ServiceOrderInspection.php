<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vistoria de entrada do veículo (uma por OS). */
#[Fillable(['fuel_level', 'damages', 'checklist', 'belongings', 'notes'])]
class ServiceOrderInspection extends Model
{
    public const FUEL_LABELS = ['Reserva', '1/4', '1/2', '3/4', 'Cheio'];

    public const DAMAGE_AREAS = [
        'front' => 'Frente',
        'rear' => 'Traseira',
        'left' => 'Lateral esquerda',
        'right' => 'Lateral direita',
        'roof' => 'Teto',
        'hood' => 'Capô',
        'wheels' => 'Rodas e pneus',
        'glass' => 'Vidros e faróis',
        'interior' => 'Interior',
        'other' => 'Outro',
    ];

    public const DAMAGE_TYPES = [
        'scratch' => 'Risco',
        'dent' => 'Amassado',
        'crack' => 'Trinca',
        'broken' => 'Quebrado',
        'rust' => 'Ferrugem',
        'paint' => 'Pintura danificada',
        'missing' => 'Faltando',
        'other' => 'Outro',
    ];

    /** Itens conferidos na entrada. */
    public const CHECKLIST = [
        'spare_tire' => 'Estepe',
        'jack' => 'Macaco',
        'wheel_wrench' => 'Chave de roda',
        'triangle' => 'Triângulo',
        'documents' => 'Documento do veículo',
        'stereo' => 'Som / multimídia',
        'floor_mats' => 'Tapetes',
        'hubcaps' => 'Calotas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fuel_level' => 'integer',
            'damages' => 'array',
            'checklist' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ServiceOrder, $this>
     */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
