<?php

namespace App\Models;

use App\Enums\ServiceOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro da linha do tempo da OS. Tipos: created, status_changed, item_added, item_removed,
 * item_done, item_undone, updated, note.
 */
#[Fillable(['user_id', 'type', 'from_status', 'to_status', 'description'])]
class ServiceOrderEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => ServiceOrderStatus::class,
            'to_status' => ServiceOrderStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
