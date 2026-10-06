<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Fornecedor (peças, aluguel, serviços...). */
#[Fillable(['name', 'document', 'phone', 'email', 'contact_name', 'notes'])]
class Supplier extends Model
{
    use SoftDeletes;

    /**
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }
}
