<?php

namespace App\Models;

use App\Enums\ServiceCategory;
use Database\Factories\LaborServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Serviço (mão de obra) que a oficina realiza, ex.: "Troca de correia dentada".
 */
#[Fillable(['name', 'category', 'description', 'is_active'])]
class LaborService extends Model
{
    /** @use HasFactory<LaborServiceFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ServiceCategory::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Nome já usado por outro serviço do catálogo (sem diferenciar maiúsculas; excluídos não contam).
     */
    public static function nameTaken(string $name, ?int $ignoreId = null): bool
    {
        return static::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
